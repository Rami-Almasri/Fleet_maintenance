<?php

namespace App\Console\Commands;

use App\Models\MaintenanceTask;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Event Type layer — rollback aid: reset resolver/import-classified maintenance_tasks to the
 * unclassified default (kind=fault, all catalog FKs null, needs_review=false). Undoes an
 * events:backfill-kind run WITHOUT touching rows a user classified from the catalog (catalog) or an
 * admin overrode (manual). Shipped alongside the backfill so a bad run is fully reversible.
 *
 * Safety: gated behind a pre-flight db:backup unless --skip-backup.
 */
class EventKindPurge extends Command
{
    protected $signature = 'events:purge-kind
                            {--skip-backup : Skip the automatic pre-flight db:backup}';

    protected $description = 'Reset resolver/import-classified maintenance_tasks.kind back to the default (rollback aid)';

    public function handle(DatabaseBackup $backup): int
    {
        if (! $this->option('skip-backup')) {
            $this->info('Pre-flight backup (safety gate)...');
            try {
                $file = $backup->run();
                $this->line("  backup written: {$file}");
            } catch (\Throwable $e) {
                $this->error('Backup failed — aborting. ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $affected = DB::table('maintenance_tasks')
            ->whereIn('classification_source', [MaintenanceTask::CLS_RESOLVER, MaintenanceTask::CLS_IMPORT])
            ->update([
                'kind'                  => MaintenanceTask::KIND_FAULT,
                'fault_catalog_id'      => null,
                'service_catalog_id'    => null,
                'inspection_type_id'    => null,
                'classification_source' => MaintenanceTask::CLS_RESOLVER,
                'needs_review'          => false,
            ]);

        $this->info("Purged {$affected} resolver/import-classified task(s) back to the unclassified default.");

        return self::SUCCESS;
    }
}
