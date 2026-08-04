<?php

namespace App\Console\Commands;

use App\Kpi\Kpi;
use App\Kpi\OperationalKpiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Print the operational scoreboard, and optionally freeze it.
 *
 * Freezing matters more than printing. The one measurement that cannot be taken retroactively is the
 * one from before a change — so the baseline has to be stored the day before the work starts, not
 * reconstructed afterwards from memory and optimism.
 */
class KpiSnapshot extends Command
{
    protected $signature = 'kpi:snapshot {--save : Store this snapshot as a baseline for later comparison}
                                         {--label= : A name for the snapshot, e.g. "before-capture-workflow"}
                                         {--reason= : WHY this baseline was taken — required when a metric definition changed}';

    protected $description = 'Report the operational KPIs, with sample sizes and what is not yet measurable';

    public function handle(OperationalKpiService $kpis): int
    {
        $all = $kpis->all();

        $measured = array_values(array_filter($all, fn (Kpi $k) => $k->available));
        $blocked  = array_values(array_filter($all, fn (Kpi $k) => ! $k->available));

        $this->newLine();
        $this->info('MEASURED');
        $this->table(
            ['KPI', 'Value', 'Sample', 'Better'],
            array_map(fn (Kpi $k) => [
                $k->label,
                $this->format($k),
                number_format($k->sampleSize),
                $k->direction === Kpi::HIGHER_BETTER ? '↑' : ($k->direction === Kpi::LOWER_BETTER ? '↓' : '–'),
            ], $measured),
        );

        foreach ($measured as $kpi) {
            if ($kpi->context !== []) {
                $this->line('  <fg=gray>'.$kpi->label.': '.collect($kpi->context)->map(fn ($v, $k) => "{$k}={$v}")->implode(' · ').'</>');
            }
        }

        if ($blocked !== []) {
            $this->newLine();
            $this->warn('NOT YET MEASURABLE — each of these is a work item, not a zero');
            foreach ($blocked as $kpi) {
                $this->line("  <fg=yellow>· {$kpi->label}</>");
                $this->line("    {$kpi->blockedReason}");
            }
        }

        $this->newLine();
        $this->line(sprintf('  %d of %d KPIs measurable today.', count($measured), count($all)));

        if ($this->option('save')) {
            $this->save($all);
        }

        return self::SUCCESS;
    }

    /** @param array<int,Kpi> $all */
    private function save(array $all): void
    {
        if (! DB::getSchemaBuilder()->hasTable('kpi_snapshots')) {
            $this->error('kpi_snapshots table is missing — run migrations first.');

            return;
        }

        $label = $this->option('label') ?: 'snapshot-'.now()->format('Y-m-d');

        $source = $this->sourceFingerprint();

        // The metric version is what makes two snapshots comparable — or proves they are not.
        // Without it, a reader comparing 59.37% with 53.49% cannot tell whether the fleet changed
        // or the definition did, and every before/after argument becomes unfalsifiable.
        $version   = (string) config('metrics.recurrence.version', 'unknown');
        $supersede = DB::table('kpi_snapshots')->orderByDesc('id')->value('id');

        DB::table('kpi_snapshots')->insert([
            'label'          => $label,
            'metric_version' => $version,
            'reason'         => $this->option('reason'),
            'supersedes_snapshot_id' => $supersede,
            'commit_ref'     => $this->commitRef(),
            'metrics'        => json_encode(array_map(fn (Kpi $k) => $k->toArray(), $all)),
            'source_state'   => json_encode($source),
            'captured_at'    => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->info("Baseline stored as '{$label}' (metric v{$version}).");
        if ($supersede) {
            $this->line("  <fg=gray>supersedes snapshot #{$supersede} — the earlier row is KEPT, never rewritten</>");
        }
        if (! $this->option('reason')) {
            $this->warn('  No --reason given. A baseline without a stated reason is hard to argue from later.');
        }
        $this->line('  <fg=gray>source state: '.collect($source)->map(fn ($v, $k) => "{$k}={$v}")->implode(' · ').'</>');
    }

    /** The commit this baseline was taken at, so a figure can always be traced to the code that made it. */
    private function commitRef(): ?string
    {
        $head = base_path('../.git/HEAD');
        if (! is_readable($head)) {
            return null;
        }
        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $path = base_path('../.git/'.substr($ref, 5));
            $ref  = is_readable($path) ? trim((string) file_get_contents($path)) : $ref;
        }

        return substr($ref, 0, 40) ?: null;
    }

    /**
     * What the numbers were computed OVER.
     *
     * Learned the hard way: `maintenance_signatures` is a derived projection, and a rebuild during a
     * single session moved the qualifying population from 31,782 to 33,040 rows — shifting the
     * first-time-fix rate without a single repair changing. Without this fingerprint a later
     * comparison cannot distinguish "the fleet improved" from "the projection was regenerated", which
     * would make every before/after argument unfalsifiable.
     *
     * @return array<string,mixed>
     */
    private function sourceFingerprint(): array
    {
        return [
            'signatures'          => DB::table('maintenance_signatures')->count(),
            'signatures_eligible' => DB::table('maintenance_signatures')
                ->where('is_exposure', 0)->whereNotNull('vehicle_id')->whereNotNull('occurred_at')->count(),
            'signatures_last_built' => (string) DB::table('maintenance_signatures')->max('updated_at'),
            'maintenances'        => DB::table('maintenances')->count(),
            'tasks'               => DB::table('maintenance_tasks')->count(),
            'task_actions'        => DB::table('maintenance_task_actions')->count(),
        ];
    }

    private function format(Kpi $kpi): string
    {
        return match ($kpi->unit) {
            'percent' => number_format((float) $kpi->value, 1).'%',
            'days'    => number_format((float) $kpi->value, 1).' days',
            'hours'   => number_format((float) $kpi->value, 1).' h',
            'seconds' => number_format((float) $kpi->value, 1).' s',
            default   => (string) round((float) $kpi->value, 2),
        };
    }
}
