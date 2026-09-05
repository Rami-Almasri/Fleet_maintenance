<?php

namespace App\Services\Reports;

use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE WHOLE CAR, NOT ONE SYSTEM — "what does this car have problems with, how often, and where?"
 *
 * The per-system dashboard answers a question a reader must already have: *is the engine fixed?* This
 * answers the one they arrive with: *what is wrong with this car at all?* It reads every system with
 * the same machinery — the same events, the same evidence layer, the same period grouping — and then
 * ranks the answers against each other, so the top of the page is the car's actual problem list rather
 * than whichever system the dropdown happened to be left on.
 *
 * NOTHING IS RE-DECIDED HERE. Every fault name, occurrence, garage, severity and repeat is taken from
 * VehicleSystemPeriodService exactly as that service produced it for its own system. This class groups,
 * counts and orders — it does not read a note, split a label or judge a record. Hand it the same
 * incidents and it returns the same page.
 *
 * WHAT IT ADDS, and why each addition is a fact rather than an opinion:
 *
 *   THE SYSTEM TAG — every problem says which system it belongs to, so "Engine · Oil Leak ×4" can be
 *   read at fault grain and at system grain without the two being confused. The category word and the
 *   specific finding are different grains and the sheet mixes them; the report keeps them apart
 *   ([[sheet-label-kind-map]]).
 *
 *   THE CONTRACT — every occurrence names the Type-U maintenance contract the car was out on that day
 *   (@see VisitContractResolver). A repeat fault a manager cannot trace to trips is an assertion; with
 *   the contract numbers on it, it is a paper trail.
 *
 *   THE GARAGE ROLLUP — which workshops this car went to, how many visits each, and how many DISTINCT
 *   faults they saw. It is a count of visits, never a verdict on a garage: the scorecard is a separate,
 *   gated engine and this page must not be mistaken for it ([[garage-scorecard-page]]).
 *
 *   THE MONTH HISTOGRAM — visits per month, so a reader sees whether the trouble is spread across years
 *   or piled into one quarter. Bare months with no rows are emitted as zeroes; a chart with the quiet
 *   months missing reads as continuous trouble.
 */
class VehicleReportOverviewService
{
    public function __construct(
        private VehicleSystemDashboardService $dashboard,
        private VehicleSystemEvidenceService $evidence,
        private VehicleSystemPeriodService $period,
    ) {
    }

    /**
     * Build the whole-car report over an optional period.
     *
     * The period is applied exactly where the single-system report applies it — in the SQL that reads
     * the events — so every count on this page describes the selected window and nothing else. There is
     * deliberately NO risk score here: that number is all-history by construction and belongs to the
     * system that prints its own arithmetic beside it.
     *
     * `$only` NARROWS THE WHOLE REPORT TO ONE SYSTEM, and narrows it HERE rather than on the page.
     * Choosing "Bodywork" has to mean the counters, the ranking, the month chart, the garages and the
     * contracts all describe bodywork — a page where the list filtered and the charts did not is worse
     * than no filter, because every number beside the filtered list contradicts it. Doing it in the
     * service is also what keeps ONE definition of every count: the alternative is re-implementing the
     * garage and month rollups in the browser, where they would drift.
     *
     * `systems` is deliberately NOT narrowed. It is the picker, and a picker that lists only the option
     * already chosen cannot be used to choose anything else.
     */
    public function build(Vehicle $vehicle, ?ReportDateRange $range = null, ?string $only = null): array
    {
        $range   = $range ?: ReportDateRange::allHistory();
        $keys    = array_keys(VehicleSystemDashboardService::SYSTEMS);
        $byKey   = $this->dashboard->eventsBySystem($vehicle, $keys, $range);
        $contract= VisitContractResolver::forVehicle((int) $vehicle->id);

        $only = isset(VehicleSystemDashboardService::SYSTEMS[$only]) ? $only : null;

        $systems  = [];
        $problems = [];
        $unnamed  = [];
        $events   = collect();

        foreach ($keys as $key) {
            $systemEvents = $byKey[$key] ?? collect();

            if ($systemEvents->isEmpty()) {
                continue;   // a system this car has no record against is absent, not a zero row
            }

            $analysis = $this->evidence->analyse($systemEvents, $key);
            $view     = $this->period->build($analysis['incidents'], $analysis['durability'], $systemEvents);

            $tagged = array_map(
                fn (array $p) => $this->tagProblem($p, $key, $contract),
                $view['problems'],
            );

            // Every system is analysed either way — the picker states what this car HAS, and a system
            // missing from it because of the current filter would read as a system with no record.
            $systems[] = $this->systemRow($key, $view['summary'], $tagged);

            if ($only !== null && $key !== $only) {
                continue;
            }

            $problems   = array_merge($problems, $tagged);
            $unnamed    = array_merge($unnamed, array_map(
                fn (array $w) => $w + ['system' => $key, 'system_label' => VehicleSystemDashboardService::SYSTEMS[$key]],
                $view['workshop_only'],
            ));
            $events = $events->concat($systemEvents);
        }

        $problems = $this->mergeAcrossSystems($problems);

        // Worst first: what happens most, then what happened most recently. This ordering IS the "this
        // car has a problem with 1, 2, 3" the report exists to state.
        usort($problems, fn ($a, $b) => [$b['occurrences'], $b['last_seen'] ?? ''] <=> [$a['occurrences'], $a['last_seen'] ?? '']);
        usort($systems, fn ($a, $b) => [$b['visits'], $b['last_seen'] ?? ''] <=> [$a['visits'], $a['last_seen'] ?? '']);
        usort($unnamed, fn ($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));

        // The counters describe WHAT IS SHOWN. Summing every system's visits under a bodywork filter
        // would print a total the list underneath cannot account for.
        $counted = $only === null
            ? $systems
            : array_values(array_filter($systems, fn (array $s) => $s['key'] === $only));

        return [
            'vehicle'   => $this->dashboard->vehicleHeader($vehicle),
            'brief'     => $this->dashboard->vehicleBrief($vehicle),
            // Which system this report is narrowed to, or null for the whole car. Stated in the payload
            // so the page and the printed PDF can both say so rather than looking unfiltered.
            'system'    => $only ? ['key' => $only, 'label' => VehicleSystemDashboardService::SYSTEMS[$only]] : null,
            'period'    => $range->toArray() + [
                'covered_from' => collect($problems)->pluck('first_seen')->filter()->min(),
                'covered_to'   => collect($problems)->pluck('last_seen')->filter()->max(),
            ],
            'summary'   => $this->summary($counted, $problems, $unnamed, $events),
            'systems'   => $systems,
            'problems'  => $problems,
            'workshop_only' => $unnamed,
            'garages'   => $this->garages($problems, $unnamed),
            'months'    => $this->months($problems, $unnamed),
            'contracts' => $this->contracts($problems, $contract),
            'provenance'=> $this->provenance($vehicle, $events, $problems, $range),
        ];
    }

    // ── One problem ─────────────────────────────────────────────────────────────────────────────

    /**
     * A period-service problem, told which system it belongs to and which contracts it happened on.
     *
     * `key` is what the frontend filters and drills on. It carries the system because two systems may
     * legitimately record the same wording — "leak" under Fluids and under Engine are two problems, and
     * merging them on the bare fault key would invent a recurrence across systems.
     */
    private function tagProblem(array $problem, string $system, VisitContractResolver $contracts): array
    {
        $events = array_map(function (array $occurrence) use ($contracts) {
            return $occurrence + ['contract' => $contracts->for($occurrence['date'])];
        }, $problem['events']);

        // The distinct trips this fault happened on, in date order. A fault seen four times on two
        // contracts is a different story from one seen four times on four, and the number of contracts
        // is the honest way to say which.
        $onContracts = collect($events)
            ->pluck('contract')
            ->filter()
            ->unique('id')
            ->sortBy('out_date')
            ->values()
            ->all();

        return array_merge($problem, [
            'system'         => $system,
            'system_label'   => VehicleSystemDashboardService::SYSTEMS[$system],
            'key'            => $system . '::' . $problem['fault_key'],
            'events'         => $events,
            'contracts'      => $onContracts,
            // Occurrences with no covering maintenance contract, stated rather than left as a gap in
            // the count — see VisitContractResolver on why a null is a real answer here.
            'without_contract' => collect($events)->whereNull('contract')->count(),
            'days_since_last'=> $this->daysSince($problem['last_seen'] ?? null),
        ]);
    }

    /**
     * ONE FAULT IS ONE PROBLEM, however many systems the classifier put the row under.
     *
     * A workshop row naming "Panel Misalignment" is legitimately read as both Tyres & Wheels and
     * Bodywork — extractCategories returns every category the text supports, and each system's report
     * is right to show it. On the WHOLE-CAR list that same event arrives twice, and a reader counting
     * problems counts one thing as two. So the car-level list groups by fault identity and keeps the
     * systems as a list; the per-system counts above are untouched, because "the bodywork had six
     * visits" is still true.
     *
     * Occurrences are de-duplicated on the day and the garage — the identity a visit already has (see
     * VehicleSystemDashboardService::collapseVisits) — and everything derived from the sequence is then
     * recomputed, because a merged fault's gaps and returns are properties of the merged sequence.
     *
     * @param  array<int, array>  $problems
     * @return array<int, array>
     */
    private function mergeAcrossSystems(array $problems): array
    {
        $groups = [];

        foreach ($problems as $problem) {
            $groups[$problem['fault_key']][] = $problem;
        }

        return array_values(array_map(function (array $group) {
            $lead = collect($group)->sortByDesc('occurrences')->first();

            if (count($group) === 1) {
                return array_merge($lead, ['systems' => [$lead['system']], 'system_labels' => [$lead['system_label']]]);
            }

            $events = collect($group)
                ->flatMap(fn (array $p) => $p['events'])
                ->unique(fn (array $o) => $o['date'] . '|' . implode(',', $o['garages'] ?: []))
                ->sortBy('date')
                ->values();

            // Gaps belong to the sequence, so they are re-read off the merged one rather than carried
            // over from a per-system sequence that no longer exists.
            $previous = null;
            $events = $events->map(function (array $o) use (&$previous) {
                $gap = ($previous && $o['date']) ? Carbon::parse($previous)->diffInDays(Carbon::parse($o['date'])) : null;
                $previous = $o['date'] ?: $previous;

                return array_merge($o, ['gap_days' => $gap === null ? null : (int) $gap]);
            });

            $dates = $events->pluck('date')->filter()->values();

            return array_merge($lead, [
                'systems'       => collect($group)->pluck('system')->unique()->values()->all(),
                'system_labels' => collect($group)->pluck('system_label')->unique()->values()->all(),
                'events'        => $events->all(),
                'occurrences'   => $events->count(),
                'repeated'      => $events->count() > 1,
                'first_seen'    => $dates->first(),
                'last_seen'     => $dates->last(),
                'span_days'     => $dates->count() > 1
                    ? (int) Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last()))
                    : null,
                'garages'       => $events->flatMap(fn (array $o) => $o['garages'] ?: [])->unique()->values()->all(),
                'contracts'     => $events->pluck('contract')->filter()->unique('id')->sortBy('out_date')->values()->all(),
                'without_contract' => $events->whereNull('contract')->count(),
                'repairs_recorded' => $events->whereIn('action', ['replacement', 'repair'])->count(),
                'worst_severity'=> $this->worstOf(collect($group)->pluck('worst_severity')),
                'returned'      => $this->returned($events),
                'days_since_last' => $this->daysSince($dates->last()),
            ]);
        }, $groups));
    }

    /**
     * Did the merged fault come back? Same statement the period service makes for a single system: the
     * record shows the sequence, and only the sequence is claimed.
     *
     * @param  Collection<int, array>  $events
     */
    private function returned(Collection $events): array
    {
        if ($events->count() < 2) {
            return ['status' => 'no', 'days' => null, 'from' => null, 'to' => null, 'after_repair' => false];
        }

        $last     = $events->last();
        $previous = $events->slice(-2, 1)->first();

        return [
            'status'       => 'yes',
            'days'         => $last['gap_days'],
            'from'         => $previous['date'],
            'to'           => $last['date'],
            'times'        => $events->count(),
            // True only when work was recorded BEFORE the last occurrence — the fault came back after
            // something was done about it.
            'after_repair' => $events->slice(0, -1)->whereIn('action', ['replacement', 'repair'])->isNotEmpty(),
        ];
    }

    /** @param  array<int, array>  $problems  already tagged */
    private function systemRow(string $key, array $summary, array $problems): array
    {
        $collection = collect($problems);

        return [
            'key'             => $key,
            'label'           => VehicleSystemDashboardService::SYSTEMS[$key],
            'visits'          => $summary['system_visits'],
            'named_faults'    => $summary['named_faults'],
            'fault_visits'    => $summary['named_fault_visits'],
            'repeated_faults' => $summary['repeated_faults'],
            'repairs'         => $summary['repairs'],
            'workshop_only'   => $summary['workshop_only_visits'],
            'first_seen'      => $summary['first_record'],
            'last_seen'       => $summary['last_record'],
            'worst_severity'  => $this->worstOf($collection->pluck('worst_severity')),
            // The one problem that leads this system, so a collapsed row still says something.
            'top_fault'       => $collection->sortByDesc('occurrences')->first()['fault'] ?? null,
        ];
    }

    // ── The rollups ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array>  $systems
     * @param  array<int, array>  $problems
     * @param  array<int, array>  $unnamed
     * @param  Collection<int, array>  $events
     */
    private function summary(array $systems, array $problems, array $unnamed, Collection $events): array
    {
        $problemList = collect($problems);

        return [
            // Visits across every system. One trip that named an engine fault AND a brake fault is
            // counted by both systems, so this is "system visits", never "trips to the workshop" — the
            // two are different numbers and the page must not print one as the other.
            'system_visits'        => collect($systems)->sum('visits'),
            // How many times the car was actually in a workshop — one day at one garage is one, however
            // many faults it found and however many systems those faults belonged to.
            'workshop_visits'      => $this->visitCount($problems, $unnamed),
            'named_faults'         => $problemList->count(),
            // Occurrences, NOT visits: a visit that named three faults contributes three.
            'fault_occurrences'    => $problemList->sum('occurrences'),
            'repeated_faults'      => $problemList->where('repeated', true)->count(),
            // Every occurrence beyond the first of a repeating fault: how many times this car came back
            // for something it had already been in for.
            'returns'              => $problemList->where('repeated', true)->sum(fn (array $p) => $p['occurrences'] - 1),
            'systems_affected'     => count($systems),
            'repairs'              => collect($systems)->sum('repairs'),
            'workshop_only_visits' => count($unnamed),
            'source_records'       => $events->count(),
            'garages'              => $this->garageNames($problems, $unnamed)->count(),
            'first_record'         => collect($systems)->pluck('first_seen')->filter()->min(),
            'last_record'          => collect($systems)->pluck('last_seen')->filter()->max(),
            'worst_severity'       => $this->worstOf($problemList->pluck('worst_severity')),
        ];
    }

    /**
     * WHERE THE WORK HAPPENED. Visits and distinct faults per workshop, busiest first.
     *
     * A visit is counted once per garage named on it — the log sometimes names two, and dropping one
     * would make the other look like the only place the car has been. It is counted ONCE HOWEVER MANY
     * FAULTS IT FOUND: the same day arrives here through every problem it named, and adding those up
     * would report a garage the car visited six times as having seen it sixteen times. The visit's
     * identity is the day and the garage, which is the identity a visit already has.
     *
     * @param  array<int, array>  $problems
     * @param  array<int, array>  $unnamed
     */
    private function garages(array $problems, array $unnamed): array
    {
        $rows = [];
        $seen = [];

        $add = function (?array $garages, ?string $date, ?string $faultKey, ?string $system) use (&$rows, &$seen) {
            foreach (($garages ?: []) as $garage) {
                $row = &$rows[$garage];
                $row ??= ['garage' => $garage, 'visits' => 0, 'faults' => [], 'systems' => [], 'first' => null, 'last' => null];

                $visit = $garage . '|' . ($date ?: '');
                if (! isset($seen[$visit])) {
                    $seen[$visit] = true;
                    $row['visits']++;
                }
                if ($faultKey) {
                    $row['faults'][$faultKey] = true;
                }
                if ($system) {
                    $row['systems'][$system] = true;
                }
                if ($date) {
                    $row['first'] = $row['first'] === null ? $date : min($row['first'], $date);
                    $row['last']  = $row['last'] === null ? $date : max($row['last'], $date);
                }
                unset($row);
            }
        };

        foreach ($problems as $problem) {
            foreach ($problem['events'] as $occurrence) {
                $add($occurrence['garages'], $occurrence['date'], $problem['key'], $problem['system']);
            }
        }

        foreach ($unnamed as $visit) {
            $add($visit['garages'], $visit['date'], null, $visit['system']);
        }

        $out = array_values(array_map(fn (array $r) => array_merge($r, [
            'faults'  => count($r['faults']),
            'systems' => array_keys($r['systems']),
        ]), $rows));

        usort($out, fn ($a, $b) => [$b['visits'], $b['last'] ?? ''] <=> [$a['visits'], $a['last'] ?? '']);

        return $out;
    }

    /**
     * Distinct workshop visits behind the whole page — the day and the garages on it, which is the
     * identity a visit already has everywhere else in this report.
     *
     * @param  array<int, array>  $problems
     * @param  array<int, array>  $unnamed
     */
    private function visitCount(array $problems, array $unnamed): int
    {
        $key = fn (?string $date, ?array $garages) => ($date ?: '') . '|' . implode(',', $garages ?: []);

        return collect($problems)
            ->flatMap(fn (array $p) => collect($p['events'])->map(fn ($o) => $key($o['date'], $o['garages'])))
            ->concat(collect($unnamed)->map(fn (array $v) => $key($v['date'], $v['garages'])))
            ->unique()
            ->count();
    }

    /** @return Collection<int, string> */
    private function garageNames(array $problems, array $unnamed): Collection
    {
        return collect($problems)
            ->flatMap(fn (array $p) => collect($p['events'])->flatMap(fn ($o) => $o['garages'] ?: []))
            ->concat(collect($unnamed)->flatMap(fn (array $v) => $v['garages'] ?: []))
            ->unique()
            ->values();
    }

    /**
     * VISITS PER MONTH across the covered span, quiet months included as zeroes.
     *
     * The span runs from the first record to the last — not to today. A car whose last trouble was in
     * 2024 would otherwise get two years of empty columns appended, which reads as a car that was
     * recently fine when the record simply stops.
     *
     * @param  array<int, array>  $problems
     * @param  array<int, array>  $unnamed
     */
    private function months(array $problems, array $unnamed): array
    {
        $counts = [];
        $seen   = [];

        // Two different questions, counted separately: how many FAULTS were recorded in the month, and
        // how many TIMES the car was in a workshop. One visit that found three faults is 3 and 1.
        $visit = function (?string $date, ?array $garages) use (&$counts, &$seen) {
            $key = ($date ?: '') . '|' . implode(',', $garages ?: []);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $counts[substr($date, 0, 7)]['visits']++;
        };

        foreach ($problems as $problem) {
            foreach ($problem['events'] as $occurrence) {
                if (! $occurrence['date']) {
                    continue;
                }
                $counts[substr($occurrence['date'], 0, 7)] ??= ['faults' => 0, 'other' => 0, 'visits' => 0];
                $counts[substr($occurrence['date'], 0, 7)]['faults']++;
                $visit($occurrence['date'], $occurrence['garages']);
            }
        }

        foreach ($unnamed as $row) {
            if (! $row['date']) {
                continue;
            }
            $counts[substr($row['date'], 0, 7)] ??= ['faults' => 0, 'other' => 0, 'visits' => 0];
            $counts[substr($row['date'], 0, 7)]['other']++;
            $visit($row['date'], $row['garages']);
        }

        if ($counts === []) {
            return [];
        }

        ksort($counts);
        $cursor = Carbon::parse(array_key_first($counts) . '-01');
        $end    = Carbon::parse(array_key_last($counts) . '-01');
        $out    = [];

        // A long-lived car can span years; the chart stays readable because the frontend buckets, not
        // because the data lies about which months existed.
        while ($cursor->lte($end)) {
            $month = $cursor->format('Y-m');
            $row   = $counts[$month] ?? ['faults' => 0, 'other' => 0, 'visits' => 0];

            $out[] = [
                'month'  => $month,
                'faults' => $row['faults'],
                'other'  => $row['other'],
                'visits' => $row['visits'],
            ];

            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * The maintenance contracts this car's recorded faults happened on, newest first, each with how
     * many fault occurrences fall inside it. Contracts with no recorded fault are omitted — this is the
     * report's contract ledger, not the contract module.
     *
     * @param  array<int, array>  $problems
     */
    private function contracts(array $problems, VisitContractResolver $resolver): array
    {
        $rows = [];

        foreach ($problems as $problem) {
            foreach ($problem['events'] as $occurrence) {
                if (! $occurrence['contract']) {
                    continue;
                }

                $id = $occurrence['contract']['id'];
                $rows[$id] ??= $occurrence['contract'] + ['occurrences' => 0, 'faults' => []];
                $rows[$id]['occurrences']++;
                $rows[$id]['faults'][$problem['key']] = $problem['fault'];
            }
        }

        $out = array_values(array_map(fn (array $r) => array_merge($r, ['faults' => array_values($r['faults'])]), $rows));

        usort($out, fn ($a, $b) => ($b['out_date'] ?? '') <=> ($a['out_date'] ?? ''));

        return $out;
    }

    // ── Data origin ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array>  $events
     * @param  array<int, array>       $problems
     */
    private function provenance(Vehicle $vehicle, Collection $events, array $problems, ReportDateRange $range): array
    {
        $tickets = $events->where('source', 'ticket')->count();
        $sheet   = $events->where('source', 'workshop log')->count();
        $from    = collect($problems)->pluck('first_seen')->filter()->min();
        $to      = collect($problems)->pluck('last_seen')->filter()->max();

        return [
            'source'  => 'maintenance_tasks (every category_key) + workshop-log rows whose finding resolves to a category',
            'window'  => $events->isEmpty()
                ? ($range->isActive() ? 'Selected period — no events recorded in it' : 'No events on record')
                : ($range->isActive() ? 'Selected period ' . ($range->from ?: '—') . ' to ' . ($range->to ?: '—') : 'All history')
                    . ' — records run ' . ($from ?: '—') . ' to ' . ($to ?: '—'),
            'split'   => $tickets . ' from per-fault tickets · ' . $sheet . ' from the workshop log',
            'grouping'=> 'One visit is one event, and one fault is one row across every visit that named it. A visit naming faults in two systems is counted by both, so system visits sum to more than trips.',
            'omitted' => 'Retired tickets are excluded. Entries whose finding matches no catalog keyword are attributed to no system and appear nowhere on this page.',
            'derived' => 'Nothing on this page is derived beyond counting: every fault name, date, garage and contract is reproduced from a record.',
            'i18n'    => [
                'source'  => ['code' => 'reportVehicle.provenance.source'],
                'window'  => $events->isEmpty()
                    ? ['code' => 'reportVehicle.provenance.windowNone']
                    : ['code' => 'reportVehicle.provenance.window', 'params' => [
                        'scope' => $range->isActive() ? (($range->from ?: '—') . ' → ' . ($range->to ?: '—')) : 'all history',
                        'from'  => $from ?: '—',
                        'to'    => $to ?: '—',
                    ]],
                'split'   => ['code' => 'reportVehicle.provenance.split', 'params' => ['tickets' => $tickets, 'sheet' => $sheet]],
                'grouping'=> ['code' => 'reportVehicle.provenance.grouping'],
                'omitted' => ['code' => 'reportVehicle.provenance.omitted'],
                'derived' => ['code' => 'reportVehicle.provenance.derived'],
            ],
            'counts'  => [
                'vehicle_id'  => (int) $vehicle->id,
                'events'      => $events->count(),
                'problems'    => count($problems),
            ],
        ];
    }

    // ── Small shared readings ───────────────────────────────────────────────────────────────────

    /** @param  Collection<int, ?string>  $severities */
    private function worstOf(Collection $severities): ?string
    {
        $rank = array_flip(['routine', 'moderate', 'high', 'critical']);

        return $severities->filter()->sortByDesc(fn (string $s) => $rank[$s] ?? -1)->first();
    }

    private function daysSince(?string $date): ?int
    {
        // Whole days, floored: "17 days ago" is a date arithmetic, not a duration to the hour.
        return $date ? (int) Carbon::parse($date)->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null;
    }
}
