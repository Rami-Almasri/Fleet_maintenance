<?php

namespace App\Services\Reports;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\GarageRecommendationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * VEHICLE SYSTEM DASHBOARD — one car, one system (engine, brakes, transmission …), the whole story.
 *
 * The question this answers is the one that gets asked about a problem car and never has a single
 * place to be answered: *has this system actually been fixed, or have we been paying for the same
 * failure over and over?* It isolates every recorded event that belongs to ONE system, lays them out
 * in order, shows what was replaced and whether the same failure came back afterwards, and states
 * what the record supports — repair, review, or stop.
 *
 * WHAT IS A FACT HERE AND WHAT IS A COUNT (see [[evidence-layer-governance]]):
 *
 *   FACT (recorded by a person, reproduced verbatim)
 *     — every timeline entry: its date, garage, severity, the finding it was logged under, the notes.
 *     — every repair line: the part or work described on the invoice line or the ticket.
 *
 *   DERIVED (arithmetic over those facts, always shown with its inputs)
 *     — the risk score and health index. Each is a sum of five named components, and the report
 *       prints the component, its input count and its contribution. There is no model, no training
 *       and no confidence: re-run the arithmetic by hand and you get the same number.
 *       See [[treat-data-as-source-of-truth]] — nothing here is a prediction.
 *
 *   NEVER PRODUCED
 *     — a probability that the system will fail again. The report says what happened and how often;
 *       whether to spend more is a person's call, and the decision line names the rule it applied.
 */
class VehicleSystemDashboardService
{
    public function __construct(private GarageRecommendationService $categories)
    {
    }

    /**
     * The systems a dashboard can be built for. Keys are the category keys of
     * config/maintenance_findings.php, so filtering is an equality test against data already
     * classified at intake — never a keyword scan invented here.
     */
    public const SYSTEMS = [
        'engine'       => 'Engine',
        'transmission' => 'Transmission & Drivetrain',
        'brakes'       => 'Brakes',
        'suspension'   => 'Suspension & Steering',
        'electrical'   => 'Electrical',
        'ac'           => 'Climate / A/C',
        'tyres'        => 'Tyres & Wheels',
        'bodywork'     => 'Bodywork & Exterior',
        'fluids'       => 'Fluids & Cooling',
    ];

    /**
     * The five components of the risk score, each with the ceiling it can contribute. They sum to
     * 100. Changing a weight changes every dashboard, so the numbers live here and nowhere else.
     */
    private const RISK_WEIGHTS = [
        'volume'      => 30,  // how many times this system has failed
        'severity'    => 20,  // how many of those were rated Critical
        'recurrence'  => 20,  // the same finding coming back
        'recency'     => 15,  // how recently it last failed
        'post_repair' => 15,  // failures recorded AFTER a major replacement on this system
    ];

    /**
     * Wording that means a MAJOR replacement rather than an adjustment.
     *
     * Deliberately narrow. An earlier draft also matched "installed", which appears in almost every
     * garage note ("bearing installed", "wheel alignment … installed") and flagged 29 of one car's 50
     * entries as major work — inflating the post-replacement risk component to the point where a
     * routine car read as unstable. A token here must be one that only appears when a component was
     * genuinely swapped out.
     */
    private const MAJOR_WORK_TOKENS = ['replace', 'replacement', 'overhaul', 'rebuild', 'new engine', 'engine change'];

    /**
     * Build the dashboard for one vehicle and one system.
     *
     * @param  Vehicle  $vehicle
     * @param  string   $system  One of self::SYSTEMS
     */
    public function build(Vehicle $vehicle, string $system): array
    {
        if (! isset(self::SYSTEMS[$system])) {
            throw new \InvalidArgumentException("Unknown system [{$system}].");
        }

        $events  = $this->events($vehicle, $system);
        $repairs = $this->repairs($vehicle, $system, $events);
        $risk    = $this->risk($events);

        return [
            'vehicle'    => $this->vehicleHeader($vehicle),
            'system'     => ['key' => $system, 'label' => self::SYSTEMS[$system]],
            'verdict'    => $this->verdict($risk, $events),
            'kpis'       => $this->kpis($events, $risk),
            'risk'       => $risk,
            'failure_mix'=> $this->failureMix($events),
            'timeline'   => $events->values()->all(),
            'repairs'    => $repairs,
            'provenance' => $this->provenance($vehicle, $system, $events),
        ];
    }

    // ── The events ──────────────────────────────────────────────────────────────────────────────

    /**
     * Every recorded failure of this system on this car, oldest first.
     *
     * Two sources, both already classified — no text is guessed at:
     *   • maintenance_tasks with this category_key — the per-fault record (the modern path).
     *   • workshop-log rows whose finding keyword resolves to this category — the sheet history,
     *     which predates per-fault tickets and is the only record for older cars.
     * A workshop row that already has tasks is represented by its tasks, so nothing double-counts.
     */
    private function events(Vehicle $vehicle, string $system): Collection
    {
        $tasks = MaintenanceTask::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category_key', $system)
            ->with(['maintenance:id,garage,vendor_id,out_date,actual_in_date,maintenance_notes,event_status', 'maintenance.vendor:id,name'])
            ->get();

        $coveredMaintenanceIds = $tasks->pluck('maintenance_id')->filter()->unique()->all();

        $sheetRows = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotIn('id', $coveredMaintenanceIds ?: [0])
            ->with('vendor:id,name')
            ->get()
            ->filter(fn (Maintenance $m) => $this->matchesSystem($m, $system));

        return $tasks->map(fn (MaintenanceTask $t) => $this->fromTask($t))
            ->concat($sheetRows->map(fn (Maintenance $m) => $this->fromSheetRow($m, $system)))
            ->filter(fn (array $e) => $e['date'] !== null)
            ->pipe(fn (Collection $all) => $this->collapseVisits($all))
            ->sortBy('date')
            ->values();
    }

    /**
     * ONE VISIT IS ONE EVENT.
     *
     * The sheet writes a separate row for each stage of the same trip — OUT, Follow up, IN — all
     * carrying the same date, the same garage and the same finding. Counted raw, a single visit to
     * one garage reads as three engine failures, and the volume and recurrence components of the risk
     * score inflate accordingly (one car showed 50 "failures" for what were far fewer actual trips).
     *
     * Rows identical on date + garage + finding are therefore the same event, and are collapsed to
     * one — keeping the richest of them, so no recorded detail is lost. Two genuinely separate visits
     * to the same garage on the same day for the same fault are indistinguishable in the data from
     * one visit logged twice; collapsing is the safer reading, and the Data Origin block says so.
     */
    private function collapseVisits(Collection $events): Collection
    {
        return $events
            ->groupBy(fn (array $e) => $e['date'] . '|' . mb_strtolower($e['finding']))
            // Within one day and one finding, split by garage — but only by garages that were
            // actually named. Rows whose garage was left blank are the same visit written up in a
            // second stage, not a second failure at a mystery workshop, so they fold into the named
            // garage. Two genuinely DIFFERENT named garages on one day stay two events: that is a car
            // moving between workshops, which is exactly what the report exists to show.
            ->flatMap(function (Collection $sameFinding) {
                $named = $sameFinding->reject(fn (array $e) => $e['garage'] === 'Not recorded');

                if ($named->pluck('garage')->unique()->count() <= 1) {
                    return [$this->mergeEntries($sameFinding)];
                }

                return $named
                    ->groupBy('garage')
                    ->map(fn (Collection $g) => $this->mergeEntries($g))
                    ->values();
            })
            ->values();
    }

    /**
     * Fold several rows of one visit into one entry WITHOUT losing anyone's words.
     *
     * The stages of a visit often carry different text — the OUT row holds the driver's complaint,
     * the follow-up row the garage's diagnosis. Keeping only the longest would silently delete one of
     * them, so the distinct notes are joined instead. The richest attributes (a real garage over a
     * blank one, a ticket's outcome over a sheet row's) win.
     *
     * @param  Collection<int, array>  $group
     */
    private function mergeEntries(Collection $group): array
    {
        $best = $group
            ->sortByDesc(fn (array $e) => [
                $e['source'] === 'ticket' ? 1 : 0,
                $e['garage'] !== 'Not recorded' ? 1 : 0,
                mb_strlen($e['detail']),
            ])
            ->first();

        $details = $group->pluck('detail')
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => $d !== '' && $d !== 'No notes recorded')
            ->unique()
            ->values();

        return array_merge($best, [
            'garage'     => $group->pluck('garage')->reject(fn ($g) => $g === 'Not recorded')->first() ?? 'Not recorded',
            'detail'     => $details->isEmpty() ? 'No notes recorded' : $details->implode("\n"),
            'recurred'   => $group->contains(fn (array $e) => $e['recurred']),
            'major_work' => $group->contains(fn (array $e) => $e['major_work']),
            'entries'    => $group->count(),
        ]);
    }

    /** A per-fault ticket entry — the richest record: the fault, its outcome, and whether it recurred. */
    private function fromTask(MaintenanceTask $t): array
    {
        $m    = $t->maintenance;
        $date = $t->identified_at ?? $t->created_at ?? optional($m)->out_date;

        return [
            'source'      => 'ticket',
            'ref'         => $t->maintenance_id,
            'date'        => $date ? Carbon::parse($date)->toDateString() : null,
            'garage'      => optional(optional($m)->vendor)->name ?? (optional($m)->garage ?: 'Not recorded'),
            'severity'    => $this->normaliseSeverity($t->severity),
            'finding'     => $t->symptom ?: 'Fault recorded without a finding',
            'detail'      => collect([$t->notes, $t->resolution_note])->filter()->implode(' · ') ?: 'No notes recorded',
            'outcome'     => $this->taskOutcome($t),
            'recurred'    => (bool) $t->recurrence_flagged,
            'major_work'  => $this->looksMajor($t->symptom . ' ' . $t->resolution_note),
        ];
    }

    /** A sheet/manual workshop entry — date, garage, finding and notes, exactly as logged. */
    private function fromSheetRow(Maintenance $m, string $system): array
    {
        return [
            'source'      => 'workshop log',
            'ref'         => $m->id,
            'date'        => optional($m->out_date)->toDateString(),
            'garage'      => optional($m->vendor)->name ?? ($m->garage ?: 'Not recorded'),
            'severity'    => $this->normaliseSeverity($m->fault_severity ?: $m->severity),
            'finding'     => $this->systemFinding($m, $system),
            'detail'      => collect([$m->garage_feedback, $m->maintenance_notes, $m->spare_part])->filter()->implode(' · ') ?: 'No notes recorded',
            'outcome'     => $m->actual_in_date ? 'Car returned ' . $m->actual_in_date->toDateString() : 'No return logged',
            'recurred'    => false,
            'major_work'  => $this->looksMajor(($m->service_sup ?? '') . ' ' . ($m->spare_part ?? '') . ' ' . ($m->maintenance_notes ?? '')),
        ];
    }

    /**
     * The part of a sheet row's finding that actually belongs to THIS system.
     *
     * One workshop visit fixes several unrelated things, and the sheet records them as one comma-
     * separated string: "Rim Scratch, Cooling Fan Malfunction, Minor Surface Scratch, Engine Oil
     * Leak". Reproducing that whole string on an Engine dashboard puts rim scratches on it and makes
     * every visit look like a different, unique failure — so nothing ever groups and the failure mix
     * becomes a list of one-offs.
     *
     * So the string is split back into its phrases and only the phrases that resolve to this system
     * are kept. A visit that mentioned engine oil leak and a rim scratch appears here as "Engine Oil
     * Leak" — the rim scratch is the bodywork dashboard's business, not this one's.
     */
    private function systemFinding(Maintenance $m, string $system): string
    {
        // The two columns are not peers. `service_sup` holds the SPECIFIC findings ("Engine Oil
        // Leak"); `service_main` holds the sheet's own CATEGORY word for the visit ("Engine"). Taking
        // both gives "Engine Oil Leak, Engine" — the category restating itself next to the finding it
        // already covers, which then fails to group against the same finding logged without it. So
        // the specific column is asked first, and the category word is used ONLY when the visit
        // recorded no specific finding for this system at all.
        foreach ([$m->service_sup, $m->service_main] as $text) {
            $phrases = $this->systemPhrases($text, $system);
            if ($phrases->isNotEmpty()) {
                return $phrases->implode(', ');
            }
        }

        return self::SYSTEMS[$system] . ' work (the entry named no specific finding)';
    }

    /**
     * The comma-separated phrases of one column that belong to the given system, de-duplicated
     * case-insensitively — the sheet writes "Engine Oil leak" and "Engine Oil Leak" for the same
     * thing, and a casing difference must not fork one recurring fault into two.
     *
     * @return Collection<int, string>
     */
    private function systemPhrases(?string $text, string $system): Collection
    {
        if (! is_string($text) || trim($text) === '') {
            return collect();
        }

        return collect(explode(',', $text))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->filter(fn ($p) => in_array($system, $this->categories->extractCategories($p, null, null), true))
            ->unique(fn ($p) => mb_strtolower($p))
            ->values();
    }

    private function taskOutcome(MaintenanceTask $t): string
    {
        return match ($t->status) {
            MaintenanceTask::STATUS_COMPLETED   => 'Repaired' . ($t->resolved_at ? ' ' . Carbon::parse($t->resolved_at)->toDateString() : ''),
            MaintenanceTask::STATUS_NOT_FOUND   => 'Workshop found no fault',
            MaintenanceTask::STATUS_CANCELLED   => 'Ruled not a real fault',
            MaintenanceTask::STATUS_IN_PROGRESS => 'Still being worked',
            MaintenanceTask::STATUS_PENDING     => 'Open, not started',
            default                             => 'Status: ' . ($t->status ?: 'not recorded'),
        };
    }

    private function looksMajor(?string $text): bool
    {
        $text = mb_strtolower((string) $text);

        foreach (self::MAJOR_WORK_TOKENS as $token) {
            if ($text !== '' && str_contains($text, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseSeverity(?string $value): ?string
    {
        $value = $value ? mb_strtolower(trim($value)) : null;

        return in_array($value, Maintenance::FAULT_SEVERITIES, true) ? $value : null;
    }

    /**
     * Does this workshop row belong to the given system?
     *
     * The sheet writes its OWN vocabulary in service_main / service_sup — "Body & Exterior", "Tires",
     * "Air Conditioning" — as a comma-separated list, and one row can name several systems at once.
     * GarageRecommendationService::extractCategories is the platform's single sanctioned reader of
     * that text (config/fault_extraction.php), already used by the cost estimator and the extraction
     * audit. Using it here rather than a second keyword map is the whole point: a row that this
     * dashboard counts under Engine is the same row the estimator counts under Engine.
     *
     * It is deliberately conservative — text it does not recognise yields NO category, so such a row
     * appears on no dashboard rather than on the wrong one.
     */
    private function matchesSystem(Maintenance $m, string $system): bool
    {
        return in_array($system, $this->categories->extractCategories($m->service_main, $m->service_sup, $m->findings), true);
    }

    // ── The score ───────────────────────────────────────────────────────────────────────────────

    /**
     * Risk as five auditable components. Every one reports the count it read and the points that
     * count earned, so the total can be checked by hand against the timeline above it.
     */
    private function risk(Collection $events): array
    {
        $total     = $events->count();
        $criticals = $events->where('severity', Maintenance::FAULT_SEVERITY_CRITICAL)->count();
        $repeats   = $this->repeatCount($events);
        $last      = $events->last();
        $daysSince = $last && $last['date'] ? Carbon::parse($last['date'])->diffInDays(Carbon::today()) : null;
        $postRepair= $this->postRepairFailures($events);

        $components = [
            [
                'key'         => 'volume',
                'label'       => 'How often this system has failed',
                'input'       => $total . ' recorded ' . Str::plural('event', $total),
                'points'      => (int) round(min($total, 10) / 10 * self::RISK_WEIGHTS['volume']),
                'max'         => self::RISK_WEIGHTS['volume'],
                'rule'        => '10 or more events reaches the full ' . self::RISK_WEIGHTS['volume'] . ' points.',
            ],
            [
                'key'         => 'severity',
                'label'       => 'How serious those failures were',
                'input'       => $criticals . ' rated Critical',
                'points'      => (int) round(min($criticals, 5) / 5 * self::RISK_WEIGHTS['severity']),
                'max'         => self::RISK_WEIGHTS['severity'],
                'rule'        => '5 or more Critical events reaches the full ' . self::RISK_WEIGHTS['severity'] . ' points.',
            ],
            [
                'key'         => 'recurrence',
                'label'       => 'The same finding coming back',
                'input'       => $repeats . ' ' . Str::plural('finding', $repeats) . ' logged more than once',
                'points'      => (int) round(min($repeats, 4) / 4 * self::RISK_WEIGHTS['recurrence']),
                'max'         => self::RISK_WEIGHTS['recurrence'],
                'rule'        => '4 or more repeated findings reaches the full ' . self::RISK_WEIGHTS['recurrence'] . ' points.',
            ],
            [
                'key'         => 'recency',
                'label'       => 'How recently it last failed',
                'input'       => $daysSince === null ? 'no dated event' : $daysSince . ' days ago',
                'points'      => $this->recencyPoints($daysSince),
                'max'         => self::RISK_WEIGHTS['recency'],
                'rule'        => 'Within 90 days scores full; 91–180 scores two thirds; 181–365 a third; older scores nothing.',
            ],
            [
                'key'         => 'post_repair',
                'label'       => 'Failures after a major replacement',
                'input'       => $postRepair . ' ' . Str::plural('event', $postRepair) . ' after replacement work',
                'points'      => (int) round(min($postRepair, 3) / 3 * self::RISK_WEIGHTS['post_repair']),
                'max'         => self::RISK_WEIGHTS['post_repair'],
                'rule'        => '3 or more failures after a replacement reaches the full ' . self::RISK_WEIGHTS['post_repair'] . ' points.',
            ],
        ];

        $score = array_sum(array_column($components, 'points'));

        return [
            'score'      => $score,
            'health'     => 100 - $score,
            'components' => $components,
            'basis'      => 'Sum of five counts over the timeline below. No model, no prediction — the arithmetic is printed beside each component.',
        ];
    }

    private function recencyPoints(?int $daysSince): int
    {
        if ($daysSince === null) {
            return 0;
        }

        $max = self::RISK_WEIGHTS['recency'];

        return match (true) {
            $daysSince <= 90  => $max,
            $daysSince <= 180 => (int) round($max * 2 / 3),
            $daysSince <= 365 => (int) round($max / 3),
            default           => 0,
        };
    }

    /** How many distinct findings appear more than once in the timeline. */
    private function repeatCount(Collection $events): int
    {
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->count();
    }

    /** Events dated after the FIRST entry that described a replacement/overhaul on this system. */
    private function postRepairFailures(Collection $events): int
    {
        $firstMajor = $events->first(fn (array $e) => $e['major_work']);

        if (! $firstMajor) {
            return 0;
        }

        return $events->filter(fn (array $e) => $e['date'] > $firstMajor['date'])->count();
    }

    // ── The verdict ─────────────────────────────────────────────────────────────────────────────

    /**
     * What the record supports. The rule that produced the line is printed with it, so the reader
     * can disagree with the rule rather than with an unexplained word.
     */
    private function verdict(array $risk, Collection $events): array
    {
        $score = $risk['score'];

        if ($events->isEmpty()) {
            return [
                'decision' => 'NOTHING RECORDED',
                'tone'     => 'neutral',
                'status'   => 'No history on this system',
                'rule'     => 'No event for this system has ever been logged against this car.',
            ];
        }

        [$decision, $tone, $status] = match (true) {
            $score >= 80 => ['STOP INVESTMENT',   'danger',  'CRITICAL / UNSTABLE'],
            $score >= 55 => ['MANAGEMENT REVIEW', 'warn',    'REPEATED FAILURES'],
            $score >= 30 => ['REPAIR AND WATCH',  'warn',    'ACTIVE HISTORY'],
            default      => ['NORMAL',            'ok',      'STABLE'],
        };

        return [
            'decision' => $decision,
            'tone'     => $tone,
            'status'   => $status,
            'rule'     => 'Risk 80+ = stop investment · 55–79 = management review · 30–54 = repair and watch · under 30 = normal.',
        ];
    }

    private function kpis(Collection $events, array $risk): array
    {
        $repeats  = $this->repeatCount($events);
        $major    = $events->filter(fn ($e) => $e['major_work'])->count();
        $open     = $events->filter(fn ($e) => in_array($e['outcome'], ['Open, not started', 'Still being worked'], true))->count();
        $garages  = $events->pluck('garage')->reject(fn ($g) => $g === 'Not recorded')->unique()->count();

        return [
            ['label' => 'Risk Score',       'value' => $risk['score'],  'note' => 'Out of 100. Components printed below.'],
            ['label' => 'Recorded Events',  'value' => $events->count(),'note' => 'Every logged failure of this system.'],
            ['label' => 'Repeated Findings','value' => $repeats,        'note' => $repeats > 0 ? 'The same wording logged again.' : 'No finding has repeated.'],
            ['label' => 'Major Work',       'value' => $major,          'note' => 'Entries describing a replacement or overhaul.'],
            ['label' => 'Still Open',       'value' => $open,           'note' => 'Faults with no resolution recorded.'],
            ['label' => 'Garages Involved', 'value' => $garages,        'note' => 'Distinct garages that touched this system.'],
        ];
    }

    /** The mix of findings, biggest first — what actually goes wrong with this system on this car. */
    private function failureMix(Collection $events): array
    {
        // Grouped case-insensitively (the sheet writes "Engine Oil leak" and "Engine Oil Leak" for
        // one thing), labelled with the first spelling seen so the wording stays the garage's own.
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->map(fn (Collection $g) => ['label' => trim($g->first()['finding']), 'count' => $g->count()])
            ->sortByDesc('count')
            ->values()
            ->take(8)
            ->all();
    }

    // ── What was replaced, and did it hold ──────────────────────────────────────────────────────

    /**
     * The parts and work billed against this system, each answered with the one question that
     * matters: was there another failure of this system AFTER it? A part followed by a further
     * failure is reported as such — that is a fact about the timeline, not a judgement of the garage.
     */
    private function repairs(Vehicle $vehicle, string $system, Collection $events): array
    {
        $lines = MaintenanceLineItem::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category_key', $system)
            ->with('maintenance:id,out_date,garage,vendor_id', 'maintenance.vendor:id,name')
            ->get();

        return $lines
            ->map(function (MaintenanceLineItem $l) use ($events) {
                $date  = optional(optional($l->maintenance)->out_date) ?: $l->installed_on ?: $l->created_at;
                $date  = $date ? Carbon::parse($date)->toDateString() : null;
                $after = $date ? $events->filter(fn ($e) => $e['date'] > $date)->count() : null;

                return [
                    'date'        => $date,
                    'work'        => $l->description ?: ($l->finding_text ?: 'Line recorded without a description'),
                    'part_number' => $l->part_number,
                    'garage'      => optional(optional($l->maintenance)->vendor)->name ?? (optional($l->maintenance)->garage ?: 'Not recorded'),
                    'held'        => $after === null ? 'Date not recorded' : ($after === 0 ? 'No further failure since' : $after . ' further ' . Str::plural('failure', $after) . ' after this'),
                    'held_ok'     => $after === 0,
                ];
            })
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    // ── Header + Data Origin ────────────────────────────────────────────────────────────────────

    private function vehicleHeader(Vehicle $vehicle): array
    {
        return [
            'id'    => $vehicle->id,
            'label' => collect([
                trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')),
                $vehicle->color,
                $vehicle->year,
                $vehicle->plate_no,
            ])->filter(fn ($p) => $p !== null && $p !== '')->implode(' · '),
            'plate' => $vehicle->plate_no,
            'vin'   => $vehicle->vin,
        ];
    }

    private function provenance(Vehicle $vehicle, string $system, Collection $events): array
    {
        $tickets = $events->where('source', 'ticket')->count();
        $sheet   = $events->where('source', 'workshop log')->count();

        return [
            'source'  => 'maintenance_tasks (category_key = ' . $system . ') + workshop-log rows whose finding resolves to that category',
            'window'  => $events->isEmpty()
                ? 'No events on record'
                : 'All history — ' . $events->first()['date'] . ' to ' . $events->last()['date'],
            'split'   => $tickets . ' from per-fault tickets · ' . $sheet . ' from the workshop log',
            'grouping'=> 'One visit is one event: sheet rows identical on date, garage and finding are the stages of a single trip (OUT / Follow up / IN) and are collapsed into one.',
            'omitted' => 'Retired tickets are excluded. Entries whose finding matches no catalog keyword are not attributed to any system, so they appear on no dashboard.',
            'derived' => 'Only the risk score and health index are derived, and every component is printed with its input count.',
        ];
    }
}
