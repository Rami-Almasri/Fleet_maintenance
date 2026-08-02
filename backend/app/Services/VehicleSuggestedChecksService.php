<?php

namespace App\Services;

use App\Models\FindingKeyword;
use App\Models\Vehicle;
use App\Support\FaultVocabulary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "What should the inspector actually look at on THIS car?" — the one place that answers it.
 *
 * It replaces a fixed checklist. The old suggestion row printed Battery / Fluids / Brakes on every
 * car that had been idle too long, because that is literally what
 * DiagnosticGateService::POST_DOWNTIME_CHECKLIST says to go look at. That list is an AGENDA ("go
 * check these"), never evidence that those faults exist — so it is no longer offered as a
 * tap-to-confirm finding. It is returned separately, as `checklist`, clearly labelled.
 *
 * What IS offered instead is derived, per car, from what the car has actually done:
 *
 *   RECURRING HISTORY  — faults this car keeps coming back for (VehicleFaultRecurrenceService).
 *                        "Brake noise · returned 3× · last seen 52 days ago · usually returns
 *                        every ~47 days". A fault only qualifies after ≥2 real episodes under the
 *                        contract/gap episode rules, so this is a measured pattern, not a guess.
 *
 *   FORECAST           — scheduled upkeep the car is over, or close to (MaintenanceForecastService
 *                        for the km/date projection, DiagnosticGateService for the live routine
 *                        conditions: oil, battery age, tyres).
 *
 *   FLEET PATTERN      — "battery replacements on this model usually land around 48,000 km".
 *                        NOT AVAILABLE YET and deliberately not faked: the fleet-pattern evidence
 *                        artifact (E15, Evidence-Artifacts.md) has zero coverage, so this block
 *                        reports `available: false` with a readable `blocked_reason` rather than
 *                        inventing a number. Consumers degrade; they never guess. When the
 *                        calibration data lands, fill in fleetPattern() — no consumer changes.
 *
 * ── PROMOTION (the "forecasting layer" the operators asked for) ────────────────────────────────
 * A suggestion is `promoted` when the car is measurably PAST a due point:
 *   • a recurring fault whose days-since-last has passed its own average return gap; or
 *   • scheduled service already over its km/date limit.
 * Promoted suggestions sort to the top, ahead of severity. Promotion is a comparison between two
 * recorded numbers — an interval the car itself produced and the days since its last visit. It is
 * not a probability, and none is emitted (see the evidence contract below).
 *
 * ── EVIDENCE ARTIFACT CONTRACT (docs/Evidence-Artifacts.md — proposal gate) ────────────────────
 *   Class     : P (prediction / advisory). Read-only derivation, computed on read.
 *   Consumes  : E2 ticket lifecycle · E7 repair outcomes · E8 spend records · E6 odometer chain
 *               (via serviceStatus/forecast) · E17 comeback links (via the recurrence engine).
 *   Produces  : NOTHING PERSISTED. There is no suggested_checks table and there must not be one —
 *               a stored suggestion goes stale the moment the car is repaired. Every call recomputes.
 *   E15 §②    : bands, not decimals, until a Brier reading exists ⇒ this service emits counts and
 *               measured day/km intervals only.
 *   E15 §⑤    : obligations carry `probability = null` ⇒ EVERY suggestion here carries
 *               `probability => null`. There is no confidence score anywhere in this file.
 *   E18       : every suggestion names its `source` and the service stamps `generated_at`, so the
 *               UI can always show where a line came from and how fresh it is.
 *
 * ── LANGUAGE ──────────────────────────────────────────────────────────────────────────────────
 * The engine emits reason CODES + params, never English sentences (the reason-code contract). The
 * UI composes the sentence through tp()/tf(), which is what makes the panel work in Arabic. Where a
 * legacy collaborator (DiagnosticGateService) still hands back an English `detail`, it rides along
 * as `detail_en` — explicitly marked legacy, never the only copy of the information.
 *
 * ── VOCABULARY ────────────────────────────────────────────────────────────────────────────────
 * A suggestion is only `selectable` (a one-tap chip that pre-fills a finding) when it resolves to a
 * real keyword in config/maintenance_findings.php — the catalog defines what is selectable
 * (findings-vocabulary contract). A recurring fault we can only place at CATEGORY level renders as
 * a "check this system" line with `selectable => false`; it is never invented into a keyword.
 *
 * Every screen reads this one service: Inspection Review, the Decide step, the request card and the
 * Vehicle Profile. Adding a fifth surface must not mean adding a fifth ranking rule.
 */
class VehicleSuggestedChecksService
{
    /** Suggestion groups — the labelled sections the UI renders separately. */
    public const GROUP_RECURRING = 'recurring';
    public const GROUP_FORECAST  = 'forecast';
    public const GROUP_FLEET     = 'fleet_pattern';

    /**
     * A recurring fault counts as "coming due again" once it has used up this share of its own
     * average return gap. Below 1.0 so the inspector is warned BEFORE the car is back in the shop —
     * at 0.8, a fault that returns every 50 days is raised on day 40.
     */
    private const DUE_SOON_GAP_RATIO = 0.8;

    /**
     * How many OBSERVED gaps a fault needs before we call its interval a pattern.
     *
     * Two episodes produce exactly one gap, and one measurement is not an interval — saying "usually
     * returns every ~10 days" from a single 10-day observation claims a regularity nothing has shown.
     * Measured on the live fleet: 59% of chains (157 of 266) have only that one gap, and 93 of them
     * were being badged "Due now" on the strength of it. Below this threshold the panel states the
     * plain fact instead ("returned once before, 10 days later") and does not promote.
     */
    private const MIN_GAPS_FOR_INTERVAL = 2;

    /**
     * Past this multiple of its own gap, a fault's pattern has LAPSED and we stop predicting a return.
     *
     * The promotion rule reads "days since last ÷ its usual gap ≥ 0.8" — which keeps climbing forever,
     * so a fault that has been quiet for 131 days on a ~17-day interval scored 8× and shouted the
     * loudest badge on the panel. But a car eight intervals silent is evidence the fault STOPPED, not
     * that it is overdue. 56 live promotions were of exactly this kind. Beyond this multiple the panel
     * says the pattern appears to have stopped, which is what the data actually shows.
     */
    private const PATTERN_LAPSED_MULTIPLE = 3.0;

    /**
     * Recurring faults older than this are not offered. A brake fault that last recurred three years
     * ago is history, not a check for today — and the car has almost certainly changed since.
     */
    private const RECURRING_MAX_AGE_DAYS = 540;

    /** How many suggestions the panel offers. More than this is a list nobody reads. */
    private const MAX_SUGGESTIONS = 6;

    /**
     * Severity ordering for the tie-break, worst first. Anything unrecognised sorts last.
     *
     * This is the ONE scale suggestions are ranked on, because the two sources speak different
     * vocabularies: the recurrence engine carries MaintenanceAnalyticsService levels
     * (critical | special | minor | routine) while DiagnosticGateService conditions carry
     * (moderate | routine). Both are mapped through normaliseLevel() before they get here, so a
     * repeating brake fault and an overdue oil change are comparable instead of coincidentally sorted.
     */
    private const LEVEL_RANK = ['critical' => 0, 'moderate' => 1, 'minor' => 2, 'routine' => 3];

    /** Source severity vocabularies → the single scale above. Unknown wording degrades to 'minor'. */
    private const LEVEL_MAP = [
        'critical' => 'critical',
        'high'     => 'critical',
        'special'  => 'moderate',   // analytics' "needs particular attention"
        'moderate' => 'moderate',
        'minor'    => 'minor',
        'routine'  => 'routine',
    ];

    public function __construct(
        private VehicleFaultRecurrenceService $recurrence,
        private MaintenanceForecastService $forecast,
        private DiagnosticGateService $gate,
    ) {
    }

    /**
     * How long a computed payload is reused. One car costs ~20ms — almost entirely its repeat-fault
     * report, which is several queries over the full workshop log — and the inputs move slowly: a
     * repeat-fault chain only changes when a repair completes, and a service-due threshold only when
     * the odometer crosses it. Fifteen minutes is well inside both.
     *
     * This is why `generated_at` is on the payload and rendered: a cached answer must say when it was
     * computed (E18 — freshness is always shown), never silently pass itself off as live.
     */
    private const CACHE_TTL = 900;

    /**
     * The full suggested-checks payload for one car.
     *
     * @return array{
     *   vehicle_id:int, suggestions:array<int,array<string,mixed>>, checklist:?array<string,mixed>,
     *   fleet_pattern:array<string,mixed>, groups:array<string,array<string,mixed>>,
     *   summary:array<string,mixed>, origin:string, generated_at:string
     * }
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        return Cache::remember(
            'vehicle:suggested_checks:v1:' . $vehicle->id,
            self::CACHE_TTL,
            fn () => $this->build($vehicle),
        );
    }

    /** Uncached computation. Everything below this line is pure derivation over recorded evidence. */
    private function build(Vehicle $vehicle): array
    {
        $conditions = $this->gate->conditionsDue($vehicle);
        $recurring  = $this->fromRecurringHistory($vehicle);

        $suggestions = array_merge(
            $recurring['suggestions'],
            $this->fromForecast($vehicle, $conditions),
        );

        // One row per finding. A car whose oil is overdue AND whose oil keeps coming back should not
        // print "Oil Change" twice — the two reasons merge onto a single, better-evidenced suggestion.
        $suggestions = $this->mergeDuplicates($suggestions);
        $suggestions = $this->rank($suggestions);
        $suggestions = $this->localise($suggestions);

        return [
            'vehicle_id'    => (int) $vehicle->id,
            'suggestions'   => array_slice($suggestions, 0, self::MAX_SUGGESTIONS),
            'checklist'     => $this->postIdleChecklist($conditions),
            'fleet_pattern' => $this->fleetPattern($vehicle),
            'groups'        => [
                self::GROUP_RECURRING => [
                    'key'    => self::GROUP_RECURRING,
                    'origin' => 'This car\'s own repair history — the workshop log (N-Maintenance) and the '
                              . 'maintenance ticket workflow, merged into repair episodes.',
                ],
                self::GROUP_FORECAST => [
                    'key'    => self::GROUP_FORECAST,
                    'origin' => 'Scheduled-service rules: odometer vs service interval, battery age, tyre '
                              . 'reminders, and the projected due date from this car\'s measured usage rate.',
                ],
                self::GROUP_FLEET => [
                    'key'    => self::GROUP_FLEET,
                    'origin' => 'Fleet-wide component-life patterns for this make/model.',
                ],
            ],
            'summary' => [
                'total'     => count($suggestions),
                'promoted'  => count(array_filter($suggestions, fn ($s) => $s['promoted'])),
                'recurring' => count(array_filter($suggestions, fn ($s) => $s['group'] === self::GROUP_RECURRING)),
                'forecast'  => count(array_filter($suggestions, fn ($s) => $s['group'] === self::GROUP_FORECAST)),
                // Never truncate silently. `shown` vs `total` is the MAX_SUGGESTIONS cap; `unplaceable`
                // is the repeat faults that exist but have no catalog wording to offer. Both are the
                // difference between "this is everything" and "this is what fitted", and the panel
                // links to the full recurrence report when either is non-zero.
                'shown'       => min(count($suggestions), self::MAX_SUGGESTIONS),
                'unplaceable' => $recurring['unplaceable'],
            ],
            'origin' => 'Generated per vehicle from its own recorded history and service state. Nothing here '
                      . 'is a fixed checklist; a car with no repeat faults and nothing due returns no suggestions.',
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    // ── Source 1 · the car's own repeat faults ─────────────────────────────────────────────────

    /**
     * Faults this car has come back for, turned into checks. The recurrence engine has already done
     * the hard part (episode grouping, both fault vocabularies merged, routine/cosmetic excluded,
     * cancelled faults dropped); this reads its output and decides what to say about each one.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fromRecurringHistory(Vehicle $vehicle): array
    {
        $report      = $this->recurrence->forVehicle($vehicle);
        $out         = [];
        $unplaceable = 0;

        foreach ($report['faults'] ?? [] as $fault) {
            $sinceLast = $fault['days_since_last'] ?? null;
            if ($sinceLast !== null && $sinceLast > self::RECURRING_MAX_AGE_DAYS) {
                continue;   // stale pattern — the car has moved on
            }

            // The recurrence engine deliberately keeps free-text faults it cannot place in the catalog,
            // so a real repeating problem is never lost to a wording the catalog has no word for yet
            // (it buckets them under the raw label — including typos like "oil fillter change", which is
            // how routine upkeep occasionally slips past the routine filter). That is right for the
            // Vehicle Profile, which REPORTS history. It is wrong here, because this list is ACTED on:
            // with no catalog keyword and no category, there is nothing for the inspector to tap and no
            // picker to open. So they are counted out loud (summary.unplaceable) and left to the
            // recurrence panel, which still shows every one of them in full. Add the alias to
            // FaultVocabulary and the fault starts appearing here on its own.
            if ($this->catalogCategoryKey($fault['key']) === null) {
                $unplaceable++;
                continue;
            }

            $avgGap = $fault['avg_gap_days'] ?? null;

            // How many gaps the average is actually made of. N episodes produce N−1 gaps, so a 2-episode
            // chain averages a single observation — a fact about one return, not an interval.
            $observedGaps = max(0, (int) $fault['episodes'] - 1);
            $isInterval   = $observedGaps >= self::MIN_GAPS_FOR_INTERVAL && $avgGap && $avgGap > 0;

            // Has the car used up its own typical interval for this fault? Only answerable when the
            // car produced an interval AND we know when it was last seen; otherwise no claim is made.
            $ratio = ($avgGap && $avgGap > 0 && $sinceLast !== null) ? $sinceLast / $avgGap : null;

            // Silent for several intervals running: the fault has stopped coming back. Predicting a
            // return here would be reading a rising ratio as urgency when it is really absence.
            $lapsed = $ratio !== null && $ratio > self::PATTERN_LAPSED_MULTIPLE;

            // Promotion needs a measured interval, not one observation, and needs the pattern to still
            // be alive. Both guards exist because the unguarded rule promoted 93 single-gap chains and
            // 56 long-dead ones on the live fleet.
            $dueSoon = $isInterval && ! $lapsed && $ratio >= self::DUE_SOON_GAP_RATIO;

            // The counter-signal the recurrence engine already computes: the SAME garage taking the
            // car repeatedly inside a few days is a slow workshop, not a car that keeps failing. That
            // must not be promoted as if the vehicle were about to break — say so instead.
            $stalling = $fault['stalling'] ?? null;

            $reasons = [
                ['code' => 'repeat.returned', 'params' => ['episodes' => (int) $fault['episodes']]],
            ];
            if ($sinceLast !== null) {
                $reasons[] = ['code' => 'repeat.last_seen', 'params' => ['days' => (int) $sinceLast]];
            }
            if ($isInterval) {
                // "Usually every ~N days" — earned, because N is an average of ≥2 observations.
                $reasons[] = ['code' => 'repeat.usual_gap', 'params' => ['days' => (int) $avgGap, 'samples' => $observedGaps]];
            } elseif ($avgGap) {
                // One observation. State it as the single event it was, with no claim of regularity.
                $reasons[] = ['code' => 'repeat.single_gap', 'params' => ['days' => (int) $avgGap]];
            }
            if ($lapsed && $isInterval) {
                $reasons[] = ['code' => 'repeat.pattern_lapsed', 'params' => [
                    'days'  => (int) $sinceLast,
                    'times' => (int) floor($ratio),
                ]];
            }
            if ($dueSoon && ! $stalling) {
                $reasons[] = [
                    'code'   => $ratio >= 1 ? 'repeat.past_usual_gap' : 'repeat.approaching_usual_gap',
                    'params' => ['days' => (int) max(0, $sinceLast - (int) $avgGap)],
                ];
            }
            if ($stalling) {
                $reasons[] = ['code' => 'repeat.stalling', 'params' => [
                    'garage' => $stalling['garage'] ?? null,
                    'visits' => (int) ($stalling['visits'] ?? 0),
                    'days'   => (int) ($stalling['span_days'] ?? 0),
                ]];
            }

            $chip = $this->resolveChip($fault['key'], $fault['variants'] ?? [], $fault['issue'] ?? null);

            $out[] = [
                'group'          => self::GROUP_RECURRING,
                'category_key'   => $fault['key'],
                'category_label' => $fault['issue'],
                'chip'           => $chip,
                'selectable'     => $chip !== null,
                // Where to send the inspector when there is no exact keyword to pre-fill. A car whose
                // history is all legacy sheet wording ("Electrical", "Brakes") yields category-level
                // suggestions only — real and worth showing, but not storable as a finding. Opening the
                // picker filtered to that category is the honest action: it puts the inspector in front
                // of the right five keywords and lets a human choose, instead of the engine inventing one.
                'picker_category' => $this->catalogCategoryKey($fault['key']),
                'level'          => $this->normaliseLevel($fault['level'] ?? null),
                // Promoted = the car is at or past the interval IT established. A stalling fault is
                // never promoted: the evidence points at the workshop, not the vehicle.
                'promoted'       => $dueSoon && ! $stalling,
                'episodes'       => (int) $fault['episodes'],
                'days_since_last' => $sinceLast,
                'avg_gap_days'   => $avgGap,
                // How much evidence the interval rests on, published rather than implied. 1 means the
                // "gap" is a single observation; the UI must not word it as a pattern, and a consumer
                // reading this payload can apply its own threshold instead of trusting ours blindly.
                'gap_samples'    => $observedGaps,
                'interval_measured' => $isInterval,
                'pattern_lapsed' => $lapsed && $isInterval,
                'reasons'        => $reasons,
                'probability'    => null,   // E15 §⑤ — obligations and measured intervals carry no probability
                'source'         => 'repeat_fault_history',
                'evidence'       => [
                    'kind'       => 'repeat_faults',
                    'vehicle_id' => (int) $vehicle->id,
                    'fault_key'  => $fault['key'],
                ],
            ];
        }

        return ['suggestions' => $out, 'unplaceable' => $unplaceable];
    }

    // ── Source 2 · scheduled service & vehicle health ──────────────────────────────────────────

    /**
     * Upkeep the car is over or close to. Two collaborators, deliberately:
     *   • DiagnosticGateService::conditionsDue — the live routine conditions (oil over km/date,
     *     battery past its service life, tyres due). These already carry the catalog keyword each
     *     condition maps to, so they arrive selectable.
     *   • MaintenanceForecastService::forecast — the projection (how overdue in km, or how many days
     *     until due at this car's measured usage rate).
     *
     * The post-downtime and inactivity directives are NOT suggestions and are filtered out here —
     * post-downtime is returned separately as the checklist, and inactivity is a reason the car is
     * being looked at, not a fault to confirm.
     *
     * @param  array<int,array<string,mixed>>  $conditions
     * @return array<int,array<string,mixed>>
     */
    private function fromForecast(Vehicle $vehicle, array $conditions): array
    {
        $service = $this->forecast->forecast($vehicle);
        $out     = [];

        // A car whose odometer says it is 852,999 km past its oil service has a broken reading, not a
        // service need. The Proactive Diagnostic Monitor already refuses to raise a request on one; the
        // panel must refuse to print it, for the same reason and by the SAME rule — otherwise the
        // inspector reads a number the rest of the system has formally classified as an anomaly.
        $implausibleOil = $this->gate->isOilOverdueImplausible($vehicle->serviceStatus());

        foreach ($conditions as $c) {
            $directive = $c['directive'] ?? null;
            if (in_array($directive, [DiagnosticGateService::DIRECTIVE_DOWNTIME, DiagnosticGateService::DIRECTIVE_INACTIVITY], true)) {
                continue;   // agenda / context, not a finding to confirm
            }

            $key = $c['key'] ?? 'condition';

            if ($key === 'oil_change' && $implausibleOil) {
                continue;   // bad odometer — fix it in Mileage Reconciliation, don't inspect on it
            }

            $reasons = [['code' => 'forecast.condition_due', 'params' => ['condition' => $key]]];

            // Oil is the one condition the forecast engine can actually quantify — attach the numbers
            // so the line reads "overdue by 1,240 km" instead of a bare "service due".
            if ($key === 'oil_change') {
                if (($service['overdue_km'] ?? 0) > 0) {
                    $reasons[] = ['code' => 'forecast.overdue_km', 'params' => ['km' => (int) $service['overdue_km']]];
                } elseif (($service['remaining_km'] ?? null) !== null) {
                    $reasons[] = ['code' => 'forecast.remaining_km', 'params' => ['km' => (int) $service['remaining_km']]];
                }
                // A projected date exists only when the car's usage rate is measurable. No rate, no date.
                if (($service['days_to_due'] ?? null) !== null && $service['days_to_due'] > 0) {
                    $reasons[] = ['code' => 'forecast.days_to_due', 'params' => [
                        'days' => (int) $service['days_to_due'],
                        'date' => $service['projected_date'],
                    ]];
                }
            }

            $keywords = $c['finding_keywords'] ?? array_filter([$c['finding_keyword'] ?? null]);
            $chip     = $this->catalogKeyword(reset($keywords) ?: null);

            $out[] = [
                'group'          => self::GROUP_FORECAST,
                // The CONDITION key (oil_change / battery / tyres), not the chip's catalog category.
                // 'Oil Change' and 'Battery Replacement' both live under the catalog's `routine`
                // category, so keying on that would make two unrelated conditions look like one row
                // the moment either lost its chip.
                'category_key'   => $key,
                'category_label' => $c['label'] ?? $key,
                'chip'           => $chip,
                'selectable'     => $chip !== null,
                'picker_category' => $chip ? (FaultVocabulary::categoryOf($chip)['key'] ?? null) : null,
                'level'          => $this->normaliseLevel($c['severity'] ?? null),
                // Already over the limit — conditionsDue only reports a condition once it IS due, so
                // every routine condition here is a real overdue, not a forecast of one.
                'promoted'       => true,
                'episodes'       => null,
                'days_since_last' => null,
                'avg_gap_days'   => null,
                'reasons'        => $reasons,
                'probability'    => null,
                'source'         => 'service_forecast',
                // Legacy English from DiagnosticGateService, which has not yet been migrated to reason
                // codes. Carried explicitly as *_en so the UI can show it in English and fall back to
                // the composed code sentence in Arabic — never the only copy of the information.
                'detail_en'      => $c['detail'] ?? null,
                'evidence'       => [
                    'kind'          => 'service_status',
                    'vehicle_id'    => (int) $vehicle->id,
                    'condition_key' => $key,
                    'current_km'    => $service['current'] ?? null,
                    'interval_km'   => $service['interval'] ?? null,
                ],
            ];
        }

        return $out;
    }

    // ── Source 3 · fleet-wide patterns (not available yet) ─────────────────────────────────────

    /**
     * "Battery replacements on this model usually land around 48,000 km."
     *
     * Not built, and deliberately not guessed. The component-life evidence this needs (E13/E15) has
     * zero measured coverage today — a model-level replacement curve computed from the current data
     * would be a number with nothing behind it, which is exactly what the evidence contract forbids
     * (E18 §③: unavailable ⇒ a non-empty, human-readable blockedReason; §④: never return zero for
     * "unknown"). So the block reports itself unavailable and the UI shows nothing.
     *
     * To turn it on: compute per make/model median odometer at replacement per component from
     * E5 (component history) once that artifact passes its acceptance test, and return
     * `available => true` with suggestions in the GROUP_FLEET group. No consumer needs to change.
     *
     * @return array{available:bool, blocked_reason:?string, suggestions:array<int,mixed>}
     */
    private function fleetPattern(Vehicle $vehicle): array
    {
        return [
            'available'      => false,
            'blocked_reason' => 'Fleet-wide component-life patterns need per-model replacement history '
                              . '(component events). That evidence has no measured coverage yet, so no '
                              . 'model-level expectation is offered rather than one that cannot be traced.',
            'suggestions'    => [],
        ];
    }

    // ── The post-idle checklist — an agenda, never a finding ───────────────────────────────────

    /**
     * The fixed Battery / Fluids / Brakes list, returned as its OWN thing.
     *
     * This is the list that used to appear as "Suggested checks" on every idle car. It is a standing
     * safety agenda for a car coming back from a long stand — it says "go look at these", and says
     * nothing whatsoever about whether any of them is wrong. Presenting it as tap-to-confirm findings
     * invited the inspector to log a battery replacement the car's own battery age never called for.
     * It stays, it is still shown, and it is labelled for what it is.
     *
     * @param  array<int,array<string,mixed>>  $conditions
     * @return array{items:array<int,string>, days:?int, reason:array<string,mixed>}|null
     */
    private function postIdleChecklist(array $conditions): ?array
    {
        $downtime = null;
        foreach ($conditions as $c) {
            if (($c['directive'] ?? null) === DiagnosticGateService::DIRECTIVE_DOWNTIME) {
                $downtime = $c;
                break;
            }
        }

        if (! $downtime) {
            return null;
        }

        return [
            'items'  => array_values($downtime['checklist'] ?? DiagnosticGateService::POST_DOWNTIME_CHECKLIST),
            'days'   => isset($downtime['days']) ? (int) $downtime['days'] : null,
            'reason' => ['code' => 'checklist.post_idle', 'params' => ['days' => (int) ($downtime['days'] ?? 0)]],
            // Explicit, so no future consumer re-derives chips from this block the way the review
            // queue used to. These are inspection instructions, not findings.
            'selectable' => false,
        ];
    }

    // ── Vocabulary ─────────────────────────────────────────────────────────────────────────────

    /**
     * The tap-to-confirm keyword for a recurring fault, or null when we can only name the system.
     *
     * The recurrence engine works at CATEGORY level ("brakes"), but a chip must be a real entry in the
     * findings catalog or the Decide step cannot store it. So: try the distinct wordings that fed this
     * chain (a ticket-workflow fault already speaks catalog wording), then the chain's own label. If
     * neither is a catalog keyword the suggestion still renders — as "check the brakes", non-selectable
     * — because a category we can name is useful and a keyword we invented is not.
     *
     * @param  array<int,string>  $variants
     */
    private function resolveChip(string $categoryKey, array $variants, ?string $label): ?string
    {
        foreach ([...$variants, $label] as $candidate) {
            $keyword = $this->catalogKeyword($candidate);
            // The keyword must belong to the fault's OWN category — a stray wording that resolves
            // somewhere else would put a brake chip on an electrical chain.
            if ($keyword && (FaultVocabulary::categoryOf($keyword)['key'] ?? null) === $categoryKey) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * The findings-catalog category a recurrence key names, or null when it names none. The recurrence
     * engine's canonical keys are drawn FROM the catalog, so this is usually the identity — but it is
     * checked rather than assumed, so an alias-only key never sends the picker to a category that does
     * not exist.
     */
    private function catalogCategoryKey(string $recurrenceKey): ?string
    {
        foreach (config('maintenance_findings.categories', []) as $category) {
            if (($category['key'] ?? null) === $recurrenceKey) {
                return $recurrenceKey;
            }
        }

        return null;
    }

    /** The catalog's exact spelling of a keyword, or null when the wording is not in the catalog. */
    private function catalogKeyword(?string $candidate): ?string
    {
        if (! $candidate) {
            return null;
        }

        return $this->catalogIndex()[FaultVocabulary::normalise($candidate)] ?? null;
    }

    /**
     * normalised keyword → the catalog's exact spelling. Built once from
     * config/maintenance_findings.php, which is the single source of what is selectable.
     *
     * @return array<string,string>
     */
    private function catalogIndex(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $index = [];
        foreach (config('maintenance_findings.categories', []) as $category) {
            foreach ($category['keywords'] ?? [] as $keyword) {
                $normalised = FaultVocabulary::normalise($keyword);
                if ($normalised !== '' && ! isset($index[$normalised])) {
                    $index[$normalised] = $keyword;
                }
            }
        }

        return $index;
    }

    /**
     * Attach the Arabic name of every chip and category.
     *
     * The engine emits reason CODES precisely so the sentence around a suggestion can be composed in
     * either language — but the NOUN in the middle of that sentence ("Brake noise") is catalog data,
     * not a UI string, so the UI cannot translate it from labels.js. Arabic for a finding keyword lives
     * in `finding_keywords.keyword_ar` (fully populated) and Arabic for a category in the catalog's
     * `label_ar`. Both are attached here, in one batched query, so an Arabic user reads an Arabic
     * suggestion instead of an English keyword wrapped in an Arabic sentence.
     *
     * @param  array<int,array<string,mixed>>  $suggestions
     * @return array<int,array<string,mixed>>
     */
    private function localise(array $suggestions): array
    {
        $chips = array_values(array_filter(array_column($suggestions, 'chip')));

        $arabic = $chips
            ? FindingKeyword::whereIn('keyword', $chips)
                ->pluck('keyword_ar', 'keyword')
                ->all()
            : [];

        $categoryAr = [];
        foreach (config('maintenance_findings.categories', []) as $category) {
            if (($category['key'] ?? null) && ! empty($category['label_ar'])) {
                $categoryAr[$category['key']] = $category['label_ar'];
            }
        }

        foreach ($suggestions as &$s) {
            // Null when the catalog has no Arabic for it — the UI falls back to the English, which is
            // still the correct keyword. Never a machine translation.
            $s['chip_ar'] = $s['chip'] ? ($arabic[$s['chip']] ?? null) : null;
            // Only when the CATEGORY is the noun on screen. A forecast row shows its chip ("Battery
            // Replacement"), and its picker_category is the catalog bucket that chip happens to live in
            // (`routine`) — translating that would print "Routine Maintenance" under a battery check.
            $s['category_label_ar'] = $s['chip'] ? null : ($categoryAr[$s['picker_category'] ?? ''] ?? null);
        }

        return $suggestions;
    }

    /**
     * Map a source severity onto the single ranking scale. An unrecognised value degrades to 'minor'
     * rather than 'routine': a level we cannot place should not be quietly sorted to the bottom of the
     * inspector's list.
     */
    private function normaliseLevel(?string $level): string
    {
        return self::LEVEL_MAP[strtolower((string) $level)] ?? 'minor';
    }

    // ── Merge & rank ───────────────────────────────────────────────────────────────────────────

    /**
     * Collapse suggestions that name the same check. A car whose oil is overdue AND whose oil work
     * keeps coming back is ONE line carrying both reasons — the recurring row wins the identity
     * (it has the richer story) and inherits the forecast's reasons and its promotion.
     *
     * Identity is the chip when there is one, else the category — so two category-level suggestions
     * for the same system also merge, and a chip never merges with a different chip.
     *
     * @param  array<int,array<string,mixed>>  $suggestions
     * @return array<int,array<string,mixed>>
     */
    private function mergeDuplicates(array $suggestions): array
    {
        $byIdentity = [];

        foreach ($suggestions as $s) {
            $identity = $s['chip'] ? 'chip:' . FaultVocabulary::normalise($s['chip']) : 'cat:' . $s['category_key'];

            if (! isset($byIdentity[$identity])) {
                $byIdentity[$identity] = $s;
                continue;
            }

            $existing = $byIdentity[$identity];

            // Recurring history is the richer row, so it keeps the identity; the other row's reasons
            // and promotion are folded in so nothing the operator would have seen is lost.
            $winner = $existing['group'] === self::GROUP_RECURRING ? $existing : $s;
            $loser  = $existing['group'] === self::GROUP_RECURRING ? $s : $existing;

            $winner['reasons']    = array_merge($winner['reasons'], $loser['reasons']);
            $winner['promoted']   = $winner['promoted'] || $loser['promoted'];
            $winner['selectable'] = $winner['selectable'] || $loser['selectable'];
            $winner['chip']     ??= $loser['chip'];
            $winner['detail_en'] ??= $loser['detail_en'] ?? null;
            // The merged row inherits the WORSE severity — merging must never soften a critical fault.
            if ((self::LEVEL_RANK[$loser['level']] ?? 9) < (self::LEVEL_RANK[$winner['level']] ?? 9)) {
                $winner['level'] = $loser['level'];
            }
            // Both stories fed this row — say so, so the UI can badge it as such.
            $winner['also_group'] = $loser['group'];

            $byIdentity[$identity] = $winner;
        }

        return array_values($byIdentity);
    }

    /**
     * Worst-first ordering: what is already due, then how serious the fault family is, then how often
     * it has come back, then how recently. Every term is a recorded number — there is no weighting
     * coefficient here to tune and no score to explain.
     *
     * @param  array<int,array<string,mixed>>  $suggestions
     * @return array<int,array<string,mixed>>
     */
    private function rank(array $suggestions): array
    {
        usort($suggestions, function ($a, $b) {
            return [
                $a['promoted'] ? 0 : 1,
                self::LEVEL_RANK[$a['level']] ?? 9,
                -(int) ($a['episodes'] ?? 0),
                (int) ($a['days_since_last'] ?? PHP_INT_MAX),
            ] <=> [
                $b['promoted'] ? 0 : 1,
                self::LEVEL_RANK[$b['level']] ?? 9,
                -(int) ($b['episodes'] ?? 0),
                (int) ($b['days_since_last'] ?? PHP_INT_MAX),
            ];
        });

        return $suggestions;
    }
}
