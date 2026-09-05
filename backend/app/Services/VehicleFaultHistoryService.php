<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Support\FaultVocabulary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "HAS THIS CAR HAD THIS BEFORE?" — one answer, read from BOTH ledgers, with its provenance attached.
 *
 * The fleet records a fault in two places and a car's story runs through both:
 *
 *   • SHEET HISTORY  — `maintenances` rows imported from the N-Maintenance log (origin sheet/manual).
 *     21,928 rows, and effectively the whole pre-workflow past of every car.
 *   • SYSTEM HISTORY — `maintenance_tasks`, one row per fault raised inside the application: test-drive
 *     findings, inspections, diagnostics and the repairs that followed.
 *
 * Every surface that asked "have we seen this before?" was reading exactly one of them. The test-drive
 * watchdog (MaintenanceWorkflowService::faultHistory) read closed TICKETS only and compared finding text
 * with `===`, so a car with nine sheet-logged battery failures answered "no history". The dashboard's
 * What Keeps Coming Back read `recurring_fault_reviews` only — a table that can only ever fill once a
 * technician confirms a fault inside the workflow. Measured on live data: 372 of the fleet's 398
 * repeat-fault chains live wholly in sheet history, 10 wholly in system history, 16 in both.
 *
 * ── PROVENANCE IS PART OF THE ANSWER ───────────────────────────────────────────────────────────────
 * Nothing here merges two records into an anonymous count. Every occurrence keeps its `source`, and each
 * result reports `sheet_count` / `system_count` alongside the total plus a `source_code` the UI renders
 * (SHEET / SYSTEM / BOTH). A fault found in both ledgers says so — that is stronger evidence, not a
 * duplicate to be hidden. See [[traceability-visibility-requirement]].
 *
 * ── MATCHING: TWO CLAIMS, NEVER CONFLATED ──────────────────────────────────────────────────────────
 * Identity comes from FaultVocabulary, the existing bridge between the sheet's shorthand and the ticket
 * catalog's wording ([[sheet-label-kind-map]]). Two levels are reported SEPARATELY and never summed:
 *
 *   • `exact`   — the same specific finding. Normalised wording, so the sheet's "Battery Weak or Dead"
 *                 and a ticket symptom of the same name are one fault across the two ledgers.
 *   • `related` — a different finding in the same system. "Brake Pad Wear" against a history of
 *                 "Squeaking or Grinding Noise" is worth knowing and is NOT the same fault.
 *
 * A bare sheet CATEGORY word ("Engine") can only ever land in `related`: its identity key is the
 * category, so it can never equal a specific finding's key. That is the guard against the failure mode
 * this service was written to stop — every future Engine visit reading as a recurrence of every past
 * engine issue. FaultVocabulary::sheetFaultLabels() additionally refuses to emit the category word at
 * all when the row wrote something specific, which is what keeps 2,640 oil-change rows out of the
 * engine-fault history.
 *
 * HISTORICAL VIEW, deliberately. Like VehicleFaultRecurrenceService, this measures WHAT HAPPENED: a
 * retired ticket is still a repair that occurred, so soft-deleted rows are not filtered out of the
 * sheet side. See docs/Maintenance-Deletion-Model.md.
 */
class VehicleFaultHistoryService
{
    public const SOURCE_SHEET  = 'sheet';
    public const SOURCE_SYSTEM = 'system';

    /** How the UI names the pair without inventing wording per screen. */
    public const SOURCE_CODES = [
        'SHEET'  => [self::SOURCE_SHEET],
        'SYSTEM' => [self::SOURCE_SYSTEM],
        'BOTH'   => [self::SOURCE_SHEET, self::SOURCE_SYSTEM],
    ];

    /**
     * The history behind a set of labels the technician just picked, one entry per label.
     *
     * Labels with no history at all are still returned (`seen` = 0) so a caller can distinguish "we
     * checked and this car is clean" from "we did not check" — the two are very different at a
     * test-drive bench, and only one of them is reassuring.
     *
     * @param  array<int,string>  $labels  raw wording, as picked (sheet shorthand or catalog wording)
     * @return array<int,array<string,mixed>>
     */
    public function lookup(int $vehicleId, array $labels, ?int $excludeTicketId = null, int $perLabel = 12): array
    {
        $labels = collect($labels)
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->unique(fn ($l) => FaultVocabulary::normalise($l))
            ->values();

        if ($labels->isEmpty()) {
            return [];
        }

        $events = $this->events($vehicleId, $excludeTicketId);

        return $labels->map(function (string $label) use ($events, $perLabel) {
            $identity = $this->identify($label);

            $exact = array_values(array_filter($events, fn ($e) => $e['key'] === $identity['key']));
            $related = array_values(array_filter(
                $events,
                fn ($e) => $e['key'] !== $identity['key']
                    && $identity['category_key'] !== null
                    && $e['category_key'] === $identity['category_key']
            ));

            return $this->describe($label, $identity, $exact, $related, $perLabel);
        })->all();
    }

    /**
     * EVERY fault occurrence recorded for one car, from both ledgers, oldest first.
     *
     * Public because the same merged timeline is the honest input for anything that reasons over a car's
     * fault past — not just the two callers that exist today.
     *
     * @return array<int,array<string,mixed>>
     */
    public function events(int $vehicleId, ?int $excludeTicketId = null): array
    {
        $events = array_merge(
            $this->sheetEvents($vehicleId, $excludeTicketId),
            $this->systemEvents($vehicleId, $excludeTicketId),
        );

        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $events;
    }

    // ── The two ledgers ────────────────────────────────────────────────────────────────────────────

    /**
     * SHEET HISTORY. Collapsed to one VISIT per (car, out_date) before any label is read — the log writes
     * one visit as several rows (OUT / follow-up / IN, plus a row per garage the car moved between), and
     * counting rows would report a fault "seen 4 times" for one afternoon in the workshop. The same
     * collapse VehicleFaultRecurrenceService applies, for the same reason.
     *
     * Rows the ticket workflow has taken over are skipped: they arrive through systemEvents() instead, so
     * a fault living in both places is one occurrence with two sources, never two occurrences.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sheetEvents(int $vehicleId, ?int $excludeTicketId): array
    {
        $ticketOwned = MaintenanceTask::where('vehicle_id', $vehicleId)
            ->whereNotNull('maintenance_id')
            ->distinct()
            ->pluck('maintenance_id')
            ->all();

        $rows = DB::table('maintenances as m')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->where('m.vehicle_id', $vehicleId)
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            // LIVE VIEW, and deliberately the OPPOSITE of VehicleFaultRecurrenceService, which includes
            // retired tickets because it measures what happened and history must not change when someone
            // tidies the board. This service drives DECISIONS — what a technician is told at the bench,
            // and what a recurrence detector would act on — and a record a human deleted is one they said
            // should not exist. Acting on it would be acting on a retraction. The raw query does not
            // inherit the model's scope, so the filter is explicit ([[softdelete-bypassed-by-raw-queries]]).
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.out_date')
            ->when($ticketOwned, fn ($q) => $q->whereNotIn('m.id', $ticketOwned))
            ->when($excludeTicketId, fn ($q) => $q->where('m.id', '!=', $excludeTicketId))
            ->orderBy('m.out_date')
            ->get(['m.id', 'm.out_date', 'm.actual_in_date', 'm.service_main', 'm.service_sup',
                   'm.garage', 'vd.name as vendor']);

        // visits[day] = ['labels' => [key => label row], 'garage' => …, 'ref_ids' => […]]
        $visits = [];
        foreach ($rows as $r) {
            $day = substr((string) $r->out_date, 0, 10);
            if ($day === '') {
                continue;
            }
            // `label_rows` = which ROW carried which label. One visit is several `maintenances` rows and
            // they do not all name the same thing — a car in for a coolant leak on Monday also has a
            // battery row and a scratch row under the same out_date. Attributing the whole visit's ids
            // to every label it produced made a "Coolant Leak" case link to a row reading "Battery Weak
            // or Dead", which reads as the engine being wrong about what it counted.
            $visits[$day] ??= ['garage' => null, 'ref_ids' => [], 'labels' => [], 'returned' => null,
                               'label_rows' => []];
            $visits[$day]['ref_ids'][] = (int) $r->id;
            $visits[$day]['garage'] ??= ($r->vendor ?: $r->garage) ?: null;
            // The visit ENDED — the sheet's only evidence that the car went in and came back out. It is
            // the log's nearest equivalent to a ticket's "Fixed", and the recurrence detector needs it:
            // a fault that never left the workshop has not come back, it never went away.
            $in = substr((string) $r->actual_in_date, 0, 10);
            if ($in !== '' && (! $visits[$day]['returned'] || $in > $visits[$day]['returned'])) {
                $visits[$day]['returned'] = $in;
            }

            foreach (FaultVocabulary::sheetFaultLabels($r->service_main, $r->service_sup) as $found) {
                // The row that actually said it — recorded per label, so a link opens the row that
                // evidenced THIS fault rather than an arbitrary sibling from the same visit.
                $visits[$day]['label_rows'][$found['key']][] = (int) $r->id;

                // A visit that names the fault specifically on ANY of its rows must not also be credited
                // with the bare category word from a sibling row. Specific wins per visit, not per row.
                $existing = $visits[$day]['labels'][$found['key']] ?? null;
                if ($existing && $existing['grain'] === FaultVocabulary::GRAIN_SPECIFIC) {
                    continue;
                }
                $visits[$day]['labels'][$found['key']] = $found;
            }
        }

        $events = [];
        foreach ($visits as $day => $visit) {
            $specific = array_filter($visit['labels'], fn ($l) => $l['grain'] === FaultVocabulary::GRAIN_SPECIFIC);
            $keep = $specific ?: $visit['labels'];

            foreach ($keep as $found) {
                $events[] = [
                    'source'         => self::SOURCE_SHEET,
                    'key'            => $found['key'],
                    // Carried through so a consumer can tell WHICH rung of the identity ladder matched:
                    // a catalog slug is the same fault across both vocabularies, bare wording is only
                    // the same fault as itself.
                    'catalog_slug'   => $found['catalog_slug'],
                    'category_key'   => $found['category_key'],
                    'label'          => $found['label'],
                    'grain'          => $found['grain'],
                    'at'             => $day,
                    'garage'         => $visit['garage'],
                    // The rows that named THIS fault, not every row of the visit. Falls back to the
                    // whole visit only if attribution is somehow missing, so a link always has a target.
                    'ref_ids'        => $visit['label_rows'][$found['key']] ?? $visit['ref_ids'],
                    'ticket_id'      => null,
                    // "The car went in for this and came back" — the sheet's stand-in for a closed repair.
                    'closed'         => $visit['returned'] !== null,
                    'closed_at'      => $visit['returned'],
                ];
            }
        }

        return $events;
    }

    /**
     * SYSTEM HISTORY — one event per fault task raised in the application, whatever raised it: a
     * test-drive finding, an inspection, a diagnostic gate or a repair.
     *
     * Faults the workshop ruled cancelled or never-found are excluded: they did not happen, so they are
     * not history. Planned services and inspections are excluded by the same reliability predicate the
     * recurrence engine uses, so a scheduled oil change never reads as a fault this car has "had before".
     *
     * `maintenances.findings` is deliberately NOT read as a second system ledger — 69 of the 70 tickets
     * carrying findings also carry tasks, so reading both would double-count almost every one of them.
     *
     * @return array<int,array<string,mixed>>
     */
    private function systemEvents(int $vehicleId, ?int $excludeTicketId): array
    {
        $tasks = MaintenanceTask::query()
            ->where('maintenance_tasks.vehicle_id', $vehicleId)
            ->whereNotIn('maintenance_tasks.status', MaintenanceTask::NON_REPAIR_TERMINAL)
            ->affectingReliability()
            ->when($excludeTicketId, fn ($q) => $q->where('maintenance_tasks.maintenance_id', '!=', $excludeTicketId))
            ->leftJoin('maintenances as m', 'm.id', '=', 'maintenance_tasks.maintenance_id')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'maintenance_tasks.current_vendor_id')
            ->leftJoin('vendors as mv', 'mv.id', '=', 'm.vendor_id')
            ->get([
                'maintenance_tasks.id', 'maintenance_tasks.maintenance_id', 'maintenance_tasks.symptom',
                'maintenance_tasks.category_key', 'maintenance_tasks.identified_at',
                'maintenance_tasks.resolved_at', 'maintenance_tasks.status',
                'm.out_date', 'm.garage',
                'vd.name as task_vendor', 'mv.name as ticket_vendor',
            ]);

        $day = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        $events = [];
        foreach ($tasks as $t) {
            $at = $day($t->out_date) ?: $day($t->identified_at) ?: $day($t->resolved_at);
            if (! $at) {
                continue;   // undateable — it cannot be placed on a timeline
            }

            // Identity is resolved by exactly the rules the sheet side uses — catalog slug first, own
            // wording second. Anything else and the two ledgers would be keyed differently and could
            // never match, which is the bug this service exists to fix.
            $wording  = trim((string) $t->symptom);
            $subject  = $wording ?: (string) $t->category_key;
            $slug     = FaultVocabulary::catalogSlugOf($subject);
            $resolved = FaultVocabulary::resolveCategory($subject);

            $events[] = [
                'source'       => self::SOURCE_SYSTEM,
                'key'          => $slug ?: FaultVocabulary::normalise($subject),
                'catalog_slug' => $slug,
                'category_key' => $t->category_key ?: ($resolved['key'] ?? null),
                'label'        => $subject,
                'grain'        => FaultVocabulary::GRAIN_SPECIFIC,
                'at'           => $at,
                'garage'       => $t->task_vendor ?: $t->ticket_vendor ?: $t->garage,
                'ref_ids'      => [(int) $t->id],
                'ticket_id'    => $t->maintenance_id ? (int) $t->maintenance_id : null,
                // The ticket side's own "it was repaired": status Fixed WITH a resolution stamped. The
                // same predicate RecurringFaultService::detectPriorFix() has always required.
                'closed'       => $t->status === MaintenanceTask::STATUS_COMPLETED && $t->resolved_at !== null,
                'closed_at'    => $t->resolved_at ? $day($t->resolved_at) : null,
                'resolved'     => $t->status === MaintenanceTask::STATUS_COMPLETED && $t->resolved_at !== null,
            ];
        }

        return $events;
    }

    // ── Recurrence detection (READ-ONLY) ───────────────────────────────────────────────────────────

    /** Two occurrences of one fault this close together are ONE repair, not a return. */
    public const SAME_REPAIR_DAYS = 7;

    /** How the two occurrences were recognised as the same fault — strongest first. */
    public const MATCH_CATALOG  = 'catalog';   // both resolve to one fault_catalog slug
    public const MATCH_WORDING  = 'wording';   // identical normalised wording
    public const MATCH_CATEGORY = 'category';  // same system only — NOT the same fault

    /**
     * RECURRENCE CANDIDATES for one car, read from both ledgers. WRITES NOTHING.
     *
     * The existing detector (RecurringFaultService::detectPriorFix) answers the same question against
     * `maintenance_tasks` alone, so on a fleet whose fault history is 21,928 sheet rows against 167
     * tasks it has produced zero cases in its lifetime. This is that question asked of the merged
     * timeline instead — and deliberately kept as a separate, read-only reader so the answer can be
     * INSPECTED before anything is allowed to open a management case, block a repair, or notify anyone.
     *
     * The rules, and why each one is here:
     *   • ONE OCCURRENCE PER REPAIR. Events of the same fault within SAME_REPAIR_DAYS collapse. A car
     *     shuffling in and out of the shop on one job is one repair, and counting its rows would report
     *     a recurrence at a gap of zero days.
     *   • THE PREVIOUS ONE MUST HAVE CLOSED. A fault that never left the workshop has not come back —
     *     it never went away. Sheet side: the visit has a return date. Ticket side: status Fixed with a
     *     resolution stamped, the same predicate the existing detector requires.
     *   • INSIDE THE WINDOW. A fault returning 3 years later is a different story from one returning in
     *     3 weeks; `$windowDays` is the same 90-day default the parts and review engines share.
     *   • EXACT FAULT FIRST. Candidates are built per IDENTITY key, so "Brake Pad Wear" recurs against
     *     "Brake Pad Wear" (or its catalog twin "Worn pads / discs"), never against "Brake noise".
     *     Same-system-different-fault pairs are returned too, tagged MATCH_CATEGORY, so a review of this
     *     output can see them and rule them out rather than never being shown them.
     *
     * @return array<int,array<string,mixed>>  one entry per (previous → latest) pair
     */
    public function recurrenceCandidates(int $vehicleId, int $windowDays = 90, bool $includeCategory = false): array
    {
        $events = $this->events($vehicleId);
        if (count($events) < 2) {
            return [];
        }

        $byKey = [];
        foreach ($events as $e) {
            $byKey[$e['key']][] = $e;
        }

        $out = [];
        foreach ($byKey as $key => $group) {
            foreach ($this->pairsFor($this->collapse($group), $windowDays) as $pair) {
                $out[] = $pair + [
                    'vehicle_id' => $vehicleId,
                    'match'      => $group[0]['catalog_slug'] ? self::MATCH_CATALOG : self::MATCH_WORDING,
                    'key'        => $key,
                ];
            }
        }

        if ($includeCategory) {
            $out = array_merge($out, $this->categoryPairs($events, $windowDays));
        }

        usort($out, fn ($a, $b) => $b['latest']['at'] <=> $a['latest']['at']);

        return $out;
    }

    /**
     * Collapse one fault's events into OCCURRENCES — consecutive events inside SAME_REPAIR_DAYS are the
     * same repair. Keeps every contributing event so a candidate can be audited back to its rows.
     *
     * @param  array<int,array<string,mixed>>  $events
     * @return array<int,array<string,mixed>>
     */
    private function collapse(array $events): array
    {
        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        $occurrences = [];
        foreach ($events as $e) {
            $last = $occurrences ? $occurrences[count($occurrences) - 1] : null;
            if ($last && (int) Carbon::parse($last['last_at'])->diffInDays(Carbon::parse($e['at'])) <= self::SAME_REPAIR_DAYS) {
                $i = count($occurrences) - 1;
                $occurrences[$i]['last_at'] = $e['at'];
                $occurrences[$i]['events'][] = $e;
                // Closed if ANY row of the repair recorded a return.
                $occurrences[$i]['closed'] = $occurrences[$i]['closed'] || ! empty($e['closed']);
                $occurrences[$i]['sources'][$e['source']] = true;
                if ($e['ticket_id']) {
                    $occurrences[$i]['ticket_ids'][] = $e['ticket_id'];
                }
                // A repair described specifically anywhere is a specific repair.
                if ($e['grain'] === FaultVocabulary::GRAIN_SPECIFIC) {
                    $occurrences[$i]['grain'] = FaultVocabulary::GRAIN_SPECIFIC;
                }
                continue;
            }
            $occurrences[] = [
                'at' => $e['at'], 'last_at' => $e['at'], 'events' => [$e],
                'closed' => ! empty($e['closed']), 'sources' => [$e['source'] => true],
                'label' => $e['label'], 'garage' => $e['garage'], 'ticket_id' => $e['ticket_id'],
                'ref_ids' => $e['ref_ids'], 'grain' => $e['grain'],
                // Every ticket this repair touched, so a caller can tell demo-seeded tasks from real
                // ones without re-querying the events.
                'ticket_ids' => $e['ticket_id'] ? [$e['ticket_id']] : [],
            ];
        }

        return $occurrences;
    }

    /**
     * Consecutive occurrence pairs that qualify as a return. Non-qualifying pairs are returned too, with
     * `qualifies` false and a REASON CODE, because a dry run that only shows what passed cannot be
     * checked — the interesting question is usually what was rejected and why ([[reason-code-contract]]).
     *
     * @param  array<int,array<string,mixed>>  $occurrences
     * @return array<int,array<string,mixed>>
     */
    private function pairsFor(array $occurrences, int $windowDays): array
    {
        $out = [];
        for ($i = 1; $i < count($occurrences); $i++) {
            $prev = $occurrences[$i - 1];
            $curr = $occurrences[$i];
            $gap  = (int) Carbon::parse($prev['last_at'])->diffInDays(Carbon::parse($curr['at']));

            $reasons = [];
            if (! $prev['closed']) {
                $reasons[] = 'PREVIOUS_NEVER_CLOSED';
            }
            if ($gap > $windowDays) {
                $reasons[] = 'OUTSIDE_WINDOW';
            }

            $out[] = [
                'label'       => $curr['label'],
                'previous'    => $this->occurrenceShape($prev),
                'latest'      => $this->occurrenceShape($curr),
                'gap_days'    => $gap,
                'occurrence'  => $i + 1,          // this is the Nth time the fault has been recorded
                'qualifies'   => $reasons === [],
                'rejected_by' => $reasons,
            ];
        }

        return $out;
    }

    /**
     * SAME SYSTEM, DIFFERENT FAULT — the weaker tier, reported separately and never merged into the
     * exact one. Only consecutive pairs whose faults genuinely differ; a category pair that is really
     * the same fault has already been caught above.
     *
     * @param  array<int,array<string,mixed>>  $events
     * @return array<int,array<string,mixed>>
     */
    private function categoryPairs(array $events, int $windowDays): array
    {
        $byCategory = [];
        foreach ($events as $e) {
            if ($e['category_key']) {
                $byCategory[$e['category_key']][] = $e;
            }
        }

        $out = [];
        foreach ($byCategory as $category => $group) {
            usort($group, fn ($a, $b) => $a['at'] <=> $b['at']);
            for ($i = 1; $i < count($group); $i++) {
                $prev = $group[$i - 1];
                $curr = $group[$i];
                if ($prev['key'] === $curr['key']) {
                    continue;   // same fault — already reported at the exact tier
                }
                $gap = (int) Carbon::parse($prev['at'])->diffInDays(Carbon::parse($curr['at']));
                if ($gap > $windowDays || $gap <= self::SAME_REPAIR_DAYS) {
                    continue;
                }
                $out[] = [
                    'label'       => $curr['label'],
                    'key'         => $curr['key'],
                    'category'    => $category,
                    'match'       => self::MATCH_CATEGORY,
                    'previous'    => $this->occurrenceShape(['at' => $prev['at'], 'last_at' => $prev['at'],
                        'closed' => ! empty($prev['closed']), 'sources' => [$prev['source'] => true],
                        'label' => $prev['label'], 'garage' => $prev['garage'],
                        'ticket_id' => $prev['ticket_id'], 'ref_ids' => $prev['ref_ids']]),
                    'latest'      => $this->occurrenceShape(['at' => $curr['at'], 'last_at' => $curr['at'],
                        'closed' => ! empty($curr['closed']), 'sources' => [$curr['source'] => true],
                        'label' => $curr['label'], 'garage' => $curr['garage'],
                        'ticket_id' => $curr['ticket_id'], 'ref_ids' => $curr['ref_ids']]),
                    'gap_days'    => $gap,
                    'occurrence'  => null,
                    // NEVER auto-qualifying. Two different faults in one system is a prompt for a human
                    // to look, not evidence that a repair failed.
                    'qualifies'   => false,
                    'rejected_by' => ['SAME_SYSTEM_NOT_SAME_FAULT'],
                ];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function occurrenceShape(array $o): array
    {
        return [
            'at'         => $o['at'],
            'label'      => $o['label'],
            'closed'     => (bool) $o['closed'],
            'sources'    => array_keys($o['sources']),
            'garage'     => $o['garage'],
            'ticket_id'  => $o['ticket_id'],
            'ticket_ids' => array_values(array_unique($o['ticket_ids'] ?? array_filter([$o['ticket_id']]))),
            'ref_ids'    => $o['ref_ids'],
            // 'category' here means the visit recorded only the SYSTEM word — real history, thinner
            // evidence. A consumer that opens management cases should weigh that differently.
            'grain'      => $o['grain'] ?? FaultVocabulary::GRAIN_SPECIFIC,
        ];
    }

    // ── Identity + shaping ─────────────────────────────────────────────────────────────────────────

    /**
     * The identity of a label the caller asked about: its specific key, and the broader category it
     * belongs to. Mirrors exactly how both ledgers key their own events, so a lookup and the history it
     * searches can never be keyed by different rules.
     *
     * @return array{key:string, category_key:?string, category_label:?string}
     */
    /**
     * The provenance code for a set of contributing ledgers.
     *
     * 'NONE' is a first-class answer and is NOT the same as 'SHEET' with a zero count: it means the
     * question was asked and this car has no record of that fault anywhere. A bench that cannot tell
     * "we checked, it's clean" from "we didn't check" is worse than one that says nothing.
     *
     * @param  array<int,string>  $sources  in ledger order — sheet before system
     */
    public static function sourceCode(array $sources): string
    {
        foreach (self::SOURCE_CODES as $code => $set) {
            if ($set === $sources) {
                return $code;
            }
        }

        return 'NONE';
    }

    public function identify(string $label): array
    {
        $slug     = FaultVocabulary::catalogSlugOf($label);
        $category = FaultVocabulary::resolveCategory($label);

        return [
            'key'            => $slug ?: FaultVocabulary::normalise($label),
            'catalog_slug'   => $slug,
            'category_key'   => $category['key'] ?? null,
            'category_label' => $category['label'] ?? null,
        ];
    }

    /**
     * One label's answer. `seen` counts EXACT occurrences only — the number a technician reads as "this
     * problem is not new for this car". Same-system-different-fault history is reported beside it under
     * `related`, never added in, so a headline count can never be inflated by a neighbouring fault.
     *
     * @param  array<int,array<string,mixed>>  $exact
     * @param  array<int,array<string,mixed>>  $related
     * @return array<string,mixed>
     */
    private function describe(string $label, array $identity, array $exact, array $related, int $perLabel): array
    {
        $sheet  = array_values(array_filter($exact, fn ($e) => $e['source'] === self::SOURCE_SHEET));
        $system = array_values(array_filter($exact, fn ($e) => $e['source'] === self::SOURCE_SYSTEM));

        $sources = [];
        if ($sheet) {
            $sources[] = self::SOURCE_SHEET;
        }
        if ($system) {
            $sources[] = self::SOURCE_SYSTEM;
        }

        $code = self::sourceCode($sources);

        $dates = array_column($exact, 'at');
        sort($dates);
        $last = $dates ? end($dates) : null;

        // Newest first: the last time this happened is the fact that changes a decision.
        $recent = $exact;
        usort($recent, fn ($a, $b) => $b['at'] <=> $a['at']);

        // The distinct neighbouring faults, not every one of their occurrences — "3 other brake faults"
        // is the useful shape, and a list of 40 rows is not.
        $relatedLabels = [];
        foreach ($related as $e) {
            $relatedLabels[$e['key']] ??= ['label' => $e['label'], 'source' => $e['source'], 'count' => 0, 'last' => null];
            $relatedLabels[$e['key']]['count']++;
            if ($relatedLabels[$e['key']]['last'] === null || $e['at'] > $relatedLabels[$e['key']]['last']) {
                $relatedLabels[$e['key']]['last'] = $e['at'];
            }
        }
        uasort($relatedLabels, fn ($a, $b) => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);

        return [
            'label'          => $label,
            'key'            => $identity['key'],
            'category_key'   => $identity['category_key'],
            'category_label' => $identity['category_label'],

            // The headline.
            'seen'           => count($exact),
            'first_seen'     => $dates[0] ?? null,
            'last_seen'      => $last,
            'days_since_last' => $last
                ? (int) Carbon::parse($last)->startOfDay()->diffInDays(Carbon::now()->startOfDay())
                : null,

            // Provenance — never collapsed into the total above.
            'sheet_count'    => count($sheet),
            'system_count'   => count($system),
            'sources'        => $sources,
            'source_code'    => $code,

            // The records themselves, so a count is auditable rather than asserted.
            'occurrences'    => array_map(fn ($e) => [
                'at'        => $e['at'],
                'source'    => $e['source'],
                'label'     => $e['label'],
                'grain'     => $e['grain'],
                'garage'    => $e['garage'],
                'ticket_id' => $e['ticket_id'],
                'ref_ids'   => $e['ref_ids'],
            ], array_slice($recent, 0, $perLabel)),
            'occurrences_truncated' => max(0, count($exact) - $perLabel),

            // Same system, different fault. A separate, weaker claim — labelled as such.
            'related_count'  => count($related),
            'related'        => array_slice(array_values($relatedLabels), 0, 5),
        ];
    }
}
