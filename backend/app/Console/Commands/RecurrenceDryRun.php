<?php

namespace App\Console\Commands;

use App\Models\RecurringFaultReview;
use App\Models\Vehicle;
use App\Services\VehicleFaultHistoryService;
use App\Services\Knowledge\RepairHistoryQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WHAT WOULD THE RECURRING-FAULT DETECTOR OPEN IF IT COULD SEE THE SHEET? — a dry run that writes
 * NOTHING.
 *
 * `RecurringFaultService::detectPriorFix()` reads `maintenance_tasks` only. On a fleet whose fault
 * history is 21,928 imported sheet rows against 167 tasks, it has opened zero cases in its lifetime;
 * every one of the 39 rows in `recurring_fault_reviews` came from RecurringFaultDemoSeeder in a
 * four-second burst on 2026-08-01.
 *
 * Pointing that detector at the merged history is a one-line change. Doing it blind is not acceptable:
 * the detector WRITES — it opens management cases, notifies super-admin and admin, and BLOCKS the repair
 * pending a ruling. So this command asks the same question against the same merged timeline and prints
 * the answer, so the volume and the quality can be judged before anything is allowed to act on it.
 *
 * Every number here is counted from records. Nothing is scored, inferred or predicted
 * ([[treat-data-as-source-of-truth]]).
 *
 *   php artisan recurrence:dry-run                 # summary + breakdown
 *   php artisan recurrence:dry-run --window=180    # widen the return window
 *   php artisan recurrence:dry-run --vehicle=1794  # one car, every candidate with its evidence
 *   php artisan recurrence:dry-run --rejected      # also show what did NOT qualify, and why
 *   php artisan recurrence:dry-run --category      # also show same-system-different-fault pairs
 *   php artisan recurrence:dry-run --csv=path.csv  # full detail for review outside the terminal
 */
class RecurrenceDryRun extends Command
{
    protected $signature = 'recurrence:dry-run
        {--window=90 : Days within which a returning fault counts as a recurrence}
        {--vehicle= : Limit to one vehicle id}
        {--rejected : Also list pairs that did NOT qualify, with the reason}
        {--category : Also list same-system-different-fault pairs (never auto-qualifying)}
        {--limit=25 : How many rows to print per section}
        {--csv= : Write every candidate to this CSV instead of truncating}';

    protected $description = 'Dry-run the recurring-fault detector over BOTH ledgers. Writes nothing.';

    /** Reason codes, spelled out for the terminal only — the codes are what any caller would consume. */
    private const REASONS = [
        'PREVIOUS_NEVER_CLOSED'      => 'the earlier visit never recorded a return — the fault never went away',
        'OUTSIDE_WINDOW'             => 'came back later than the window allows',
        'SAME_SYSTEM_NOT_SAME_FAULT' => 'same system, different fault — a prompt to look, not a failed repair',
    ];

    /** @var array<int,array<string,mixed>> candidates driven by demo-seeded tickets */
    private array $demoCandidates = [];

    public function handle(VehicleFaultHistoryService $history): int
    {
        $window   = max(1, (int) $this->option('window'));
        $limit    = max(1, (int) $this->option('limit'));
        $withCat  = (bool) $this->option('category');

        $this->demoAudit();

        $vehicles = Vehicle::query()
            ->when($this->option('vehicle'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get(['id', 'plate_no', 'make', 'model']);

        $this->line('');
        $this->info("Scanning {$vehicles->count()} vehicles · return window {$window} days · WRITES NOTHING");

        // Tickets the demo seeder created. Their tasks are real rows in `maintenance_tasks`, so the
        // detector sees them exactly as it would see genuine work — which would have made 47 seeded
        // cases read as fleet reality in the summary below. Separated, never silently dropped: the
        // count of demo-driven candidates is itself a useful check that the seeder is what we think.
        $demoTickets = DB::table('maintenances')->where('garage_feedback', 'RECUR_DEMO')->pluck('id')->flip();

        $qualifying = [];
        $rejected   = [];
        $categoryOnly = [];
        $demo       = [];

        foreach ($vehicles as $v) {
            foreach ($history->recurrenceCandidates($v->id, $window, $withCat) as $c) {
                $tickets = array_merge($c['previous']['ticket_ids'] ?? [], $c['latest']['ticket_ids'] ?? []);
                $isDemo = (bool) array_filter($tickets, fn ($id) => $demoTickets->has($id));

                $row = $c + [
                    'plate'    => $v->plate_no ?: '#' . $v->id,
                    'car'      => trim(($v->make ?? '') . ' ' . ($v->model ?? '')),
                    'is_demo'  => $isDemo,
                ];

                if ($isDemo) {
                    $demo[] = $row;
                } elseif (($c['match'] ?? null) === VehicleFaultHistoryService::MATCH_CATEGORY) {
                    $categoryOnly[] = $row;
                } elseif ($c['qualifies']) {
                    $qualifying[] = $row;
                } else {
                    $rejected[] = $row;
                }
            }
        }

        $this->demoCandidates = $demo;

        $this->summary($qualifying, $rejected, $categoryOnly, $window);
        $this->byFault($qualifying, $limit);
        $this->byVehicle($qualifying, $limit);
        $this->evidence($qualifying, $limit);

        if ($this->option('rejected')) {
            $this->rejectedTable($rejected, $limit);
        }
        if ($withCat) {
            $this->categoryTable($categoryOnly, $limit);
        }
        if ($path = $this->option('csv')) {
            $this->writeCsv($path, array_merge($qualifying, $rejected, $categoryOnly));
        }

        $this->line('');
        $this->warn('Nothing was created. No review case was opened, no notification sent, no repair blocked.');

        return self::SUCCESS;
    }

    /**
     * THE EXISTING TABLE, audited. Demo rows are identified by the seeder's own marker
     * (`maintenances.garage_feedback = RECUR_DEMO`) rather than by their timestamps, so the separation
     * is a fact about how they were created, not a guess from when.
     */
    private function demoAudit(): void
    {
        $marked = DB::table('maintenances')->where('garage_feedback', 'RECUR_DEMO')->pluck('id');

        $total = RecurringFaultReview::count();
        $demo  = RecurringFaultReview::where(function ($q) use ($marked) {
            $q->whereIn('maintenance_id', $marked)->orWhereIn('previous_maintenance_id', $marked);
        })->count();

        $this->line('');
        $this->line('<options=bold>EXISTING recurring_fault_reviews</>');
        $this->table(
            ['', 'rows'],
            [
                ['total', $total],
                ['DEMO (RecurringFaultDemoSeeder)', $demo],
                ['REAL (opened by the workflow)', $total - $demo],
            ],
        );

        if ($demo === $total && $total > 0) {
            $this->warn("  Every row is demo data. Remove with: php artisan db:seed --class=RecurringFaultDemoSeeder --no-interaction");
            $this->line('  (the seeder is idempotent and its teardown() clears exactly these rows)');
        }
    }

    /** @param array<int,array<string,mixed>> $q */
    private function summary(array $q, array $rejected, array $category, int $window): void
    {
        $bySource = ['sheet' => 0, 'system' => 0, 'both' => 0];
        foreach ($q as $c) {
            $sources = array_unique(array_merge($c['previous']['sources'], $c['latest']['sources']));
            sort($sources);
            $bySource[count($sources) > 1 ? 'both' : $sources[0]]++;
        }

        $byMatch = [];
        foreach ($q as $c) {
            $byMatch[$c['match']] = ($byMatch[$c['match']] ?? 0) + 1;
        }

        // How much of the evidence is the bare system word rather than a named fault.
        $categoryGrain = count(array_filter(
            $q,
            fn ($c) => ($c['latest']['grain'] ?? '') === 'category' || ($c['previous']['grain'] ?? '') === 'category',
        ));

        $this->line('');
        $this->line('<options=bold>WOULD BE DETECTED</> — REAL data only, demo excluded');
        $this->table(['', 'cases'], [
            ['QUALIFYING (a review would open)', count($q)],
            ['  ...matched by catalog fault', $byMatch[VehicleFaultHistoryService::MATCH_CATALOG] ?? 0],
            ['  ...matched by identical wording', $byMatch[VehicleFaultHistoryService::MATCH_WORDING] ?? 0],
            ['  ...where one side is only a SYSTEM word', $categoryGrain],
            ['  ...evidenced by sheet history only', $bySource['sheet']],
            ['  ...evidenced by system records only', $bySource['system']],
            ['  ...evidenced by BOTH ledgers', $bySource['both']],
            ['cars affected', count(array_unique(array_column($q, 'vehicle_id')))],
            ['distinct faults', count(array_unique(array_column($q, 'key')))],
            ['REJECTED (same fault, failed a rule)', count($rejected)],
            ['SAME-SYSTEM pairs (never auto-qualify)', count($category)],
            ['EXCLUDED as demo-seeded', count($this->demoCandidates)],
        ]);
        $this->line("  A case qualifies when the SAME fault returned within {$window} days of a previous visit that recorded a return.");
        if ($this->demoCandidates) {
            $demoFaults = array_unique(array_column($this->demoCandidates, 'label'));
            $this->warn('  ' . count($this->demoCandidates) . ' further candidates come from RecurringFaultDemoSeeder tickets and are NOT counted above: '
                . implode(', ', array_slice($demoFaults, 0, 6)));
        }
    }

    /** @param array<int,array<string,mixed>> $q */
    private function byFault(array $q, int $limit): void
    {
        // Grouped by IDENTITY KEY, not by the wording that happened to be typed. The sheet writes
        // "Engine Oil leak" and "Engine Oil Leak" on different rows and the catalog calls the same
        // fault "Oil leak"; grouping on the raw label split one fault across three lines and made each
        // look rarer than it is. The most-used wording is shown, with a note when there are others.
        $by = [];
        foreach ($q as $c) {
            $k = $c['key'];
            $by[$k] ??= ['key' => $k, 'labels' => [], 'cases' => 0, 'cars' => [], 'fastest' => null, 'grain' => 'specific'];
            $by[$k]['cases']++;
            $by[$k]['cars'][$c['vehicle_id']] = true;
            $by[$k]['labels'][$c['label']] = ($by[$k]['labels'][$c['label']] ?? 0) + 1;
            if ($by[$k]['fastest'] === null || $c['gap_days'] < $by[$k]['fastest']) {
                $by[$k]['fastest'] = $c['gap_days'];
            }
            if (($c['latest']['grain'] ?? '') === 'category') {
                $by[$k]['grain'] = 'category';
            }
        }
        uasort($by, fn ($a, $b) => $b['cases'] <=> $a['cases']);

        $this->line('');
        $this->line('<options=bold>BY EXACT FAULT</> (grouped on fault identity, not on how it was typed)');
        $this->table(
            ['fault', 'cases', 'cars', 'fastest return', 'grain', 'other wordings'],
            array_map(function ($r) {
                arsort($r['labels']);
                $names = array_keys($r['labels']);

                return [
                    $names[0],
                    $r['cases'],
                    count($r['cars']),
                    $r['fastest'] . 'd',
                    $r['grain'] === 'category' ? 'SYSTEM WORD' : 'named fault',
                    count($names) > 1 ? implode(', ', array_slice($names, 1)) : '',
                ];
            }, array_slice($by, 0, $limit)),
        );
        if (count($by) > $limit) {
            $this->line('  … ' . (count($by) - $limit) . ' more faults not shown (raise --limit or use --csv)');
        }
    }

    /** @param array<int,array<string,mixed>> $q */
    private function byVehicle(array $q, int $limit): void
    {
        $by = [];
        foreach ($q as $c) {
            $k = $c['vehicle_id'];
            $by[$k] ??= ['plate' => $c['plate'], 'car' => $c['car'], 'cases' => 0, 'faults' => []];
            $by[$k]['cases']++;
            $by[$k]['faults'][$c['label']] = true;
        }
        uasort($by, fn ($a, $b) => $b['cases'] <=> $a['cases']);

        $this->line('');
        $this->line('<options=bold>BY VEHICLE</>');
        $this->table(
            ['plate', 'car', 'cases', 'faults'],
            array_map(
                fn ($r) => [$r['plate'], mb_substr($r['car'], 0, 22), $r['cases'], implode(', ', array_slice(array_keys($r['faults']), 0, 3))],
                array_slice($by, 0, $limit),
            ),
        );
        if (count($by) > $limit) {
            $this->line('  … ' . (count($by) - $limit) . ' more cars not shown');
        }
    }

    /** The per-case evidence: both dates, both sources, and why it qualified. */
    private function evidence(array $q, int $limit): void
    {
        $this->line('');
        $this->line('<options=bold>THE CASES THEMSELVES</> (newest first)');
        $this->table(
            ['plate', 'exact fault', 'previous', 'src', 'latest', 'src', 'gap', 'occ', 'matched by'],
            array_map(fn ($c) => [
                $c['plate'],
                mb_substr($c['label'], 0, 26),
                $c['previous']['at'],
                implode('+', $c['previous']['sources']),
                $c['latest']['at'],
                implode('+', $c['latest']['sources']),
                $c['gap_days'] . 'd',
                $c['occurrence'] ? '#' . $c['occurrence'] : '—',
                $c['match'],
            ], array_slice($q, 0, $limit)),
        );
        if (count($q) > $limit) {
            $this->line('  … ' . (count($q) - $limit) . ' more cases not shown (raise --limit or use --csv)');
        }
    }

    private function rejectedTable(array $rows, int $limit): void
    {
        $this->line('');
        $this->line('<options=bold>REJECTED</> — the same fault twice, but a rule said no');
        $this->table(
            ['plate', 'exact fault', 'previous', 'latest', 'gap', 'why not'],
            array_map(fn ($c) => [
                $c['plate'], mb_substr($c['label'], 0, 26), $c['previous']['at'], $c['latest']['at'],
                $c['gap_days'] . 'd',
                implode('; ', array_map(fn ($r) => self::REASONS[$r] ?? $r, $c['rejected_by'])),
            ], array_slice($rows, 0, $limit)),
        );
        if (count($rows) > $limit) {
            $this->line('  … ' . (count($rows) - $limit) . ' more');
        }
    }

    private function categoryTable(array $rows, int $limit): void
    {
        $this->line('');
        $this->line('<options=bold>SAME SYSTEM, DIFFERENT FAULT</> — shown so they can be ruled out, never auto-opened');
        $this->table(
            ['plate', 'system', 'previous fault', 'then', 'gap'],
            array_map(fn ($c) => [
                $c['plate'], $c['category'], mb_substr($c['previous']['label'], 0, 24),
                mb_substr($c['latest']['label'], 0, 24), $c['gap_days'] . 'd',
            ], array_slice($rows, 0, $limit)),
        );
        if (count($rows) > $limit) {
            $this->line('  … ' . (count($rows) - $limit) . ' more');
        }
    }

    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['vehicle_id', 'plate', 'car', 'exact_fault', 'match', 'qualifies', 'rejected_by',
                      'previous_at', 'previous_sources', 'previous_closed', 'previous_garage',
                      'latest_at', 'latest_sources', 'latest_garage', 'gap_days', 'occurrence']);
        foreach ($rows as $c) {
            fputcsv($fh, [
                $c['vehicle_id'], $c['plate'], $c['car'], $c['label'], $c['match'],
                $c['qualifies'] ? 'yes' : 'no', implode('|', $c['rejected_by']),
                $c['previous']['at'], implode('|', $c['previous']['sources']),
                $c['previous']['closed'] ? 'yes' : 'no', $c['previous']['garage'],
                $c['latest']['at'], implode('|', $c['latest']['sources']), $c['latest']['garage'],
                $c['gap_days'], $c['occurrence'],
            ]);
        }
        fclose($fh);
        $this->info('CSV written: ' . $path . ' (' . count($rows) . ' rows)');
    }
}
