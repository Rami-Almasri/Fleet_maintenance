<?php

namespace App\Console\Commands;

use App\Models\MaintenanceTask;
use App\Services\DatabaseBackup;
use App\Services\EventClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Event Type layer — backfill `kind` (fault | service | inspection) onto existing maintenance_tasks.
 *
 * Runs the EventClassificationService SHIELD (resolveLegacyKind) once over historical rows and persists
 * the result. This is the ONLY place legacy text-resolution happens; readers never do it. Rows already
 * classified from a catalog pick (classification_source = catalog) or by an admin (manual) are left
 * untouched. Deterministic + idempotent: a second run writes nothing (same input → same output).
 *
 * Writes go via the query builder (not model save) so the CHECK constraint still enforces integrity but
 * the per-task ticket cost roll-up is NOT re-triggered — a `kind` change moves no money.
 *
 * Safety:
 *   --dry-run       classify inside a rolled-back transaction and report counts, writing nothing
 *   --skip-backup   skip the pre-flight db:backup (for a scratch/rehearsal DB)
 *   --from= --to=   limit to tasks created in [from,to] (Y-m-d), e.g. a phased backfill
 */
class EventKindBackfill extends Command
{
    protected $signature = 'events:backfill-kind
                            {--dry-run : Classify inside a rolled-back transaction and report, writing nothing}
                            {--skip-backup : Skip the automatic pre-flight db:backup}
                            {--from= : Only tasks created on/after this date (Y-m-d)}
                            {--to= : Only tasks created on/before this date (Y-m-d)}';

    protected $description = 'Backfill maintenance_tasks.kind (fault/service/inspection) via the legacy resolver';

    public function handle(EventClassificationService $classifier, DatabaseBackup $backup): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('skip-backup')) {
            $this->info('Pre-flight backup (safety gate before a live write)...');
            try {
                $file = $backup->run();
                $this->line("  backup written: {$file}");
            } catch (\Throwable $e) {
                $this->error('Backup failed — aborting. Re-run with --skip-backup only on a scratch DB. ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // Only classify rows the resolver owns — never overwrite a user catalog pick or an admin override.
        $query = MaintenanceTask::query()
            ->with('maintenance')
            ->whereNotIn('classification_source', [MaintenanceTask::CLS_CATALOG, MaintenanceTask::CLS_MANUAL]);

        if ($from = $this->option('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $this->option('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $total = (clone $query)->count();
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Classifying {$total} maintenance_task(s)...");

        $tally = [
            MaintenanceTask::KIND_FAULT      => 0,
            MaintenanceTask::KIND_SERVICE    => 0,
            MaintenanceTask::KIND_INSPECTION => 0,
        ];
        $changed = 0;
        $needsReview = 0;

        $dryRun ? DB::beginTransaction() : null;

        try {
            $query->chunkById(500, function ($tasks) use ($classifier, &$tally, &$changed, &$needsReview) {
                foreach ($tasks as $task) {
                    $attrs = $classifier->resolveLegacyKind($task);
                    $tally[$attrs['kind']]++;
                    if ($attrs['needs_review']) {
                        $needsReview++;
                    }

                    // Write only when something actually differs (keeps the second run a true no-op).
                    $differs = $task->kind !== $attrs['kind']
                        || (int) $task->fault_catalog_id !== (int) $attrs['fault_catalog_id']
                        || (int) $task->service_catalog_id !== (int) $attrs['service_catalog_id']
                        || (int) $task->inspection_type_id !== (int) $attrs['inspection_type_id']
                        || (bool) $task->needs_review !== (bool) $attrs['needs_review']
                        || $task->classification_source !== $attrs['classification_source'];

                    if ($differs) {
                        DB::table('maintenance_tasks')->where('id', $task->id)->update($attrs);
                        $changed++;
                    }
                }
            });
        } catch (\Throwable $e) {
            $dryRun ? DB::rollBack() : null;
            $this->error('Backfill failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $dryRun ? DB::rollBack() : null;

        $this->newLine();
        $this->line('  ' . ($dryRun ? '[DRY-RUN] would classify' : 'classified') . " — fault: {$tally['fault']}, service: {$tally['service']}, inspection: {$tally['inspection']}");
        $this->line("  rows changed: {$changed} · needs_review: {$needsReview}");
        $this->info('Done.' . ($dryRun ? ' (nothing written)' : ''));

        return self::SUCCESS;
    }
}
