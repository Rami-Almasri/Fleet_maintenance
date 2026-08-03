<?php

namespace App\Console\Commands;

use App\Models\MaintenanceTask;
use App\Support\EventKind;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Integrity check for the Event Type layer — does the data still obey the invariants the design assumes?
 *
 * WHY THIS EXISTS. The exactly-one-catalog rule is enforced by the MaintenanceTask `saving` guard plus a
 * DB CHECK constraint — but the CHECK is only installed where the platform accepts it. MySQL 8 (which is
 * what production runs) refuses a CHECK on a column that also carries a foreign key with a referential
 * action, and all three catalog FKs are ON DELETE SET NULL. So in production the model guard is the ONLY
 * enforcement, and anything that bypasses Eloquent — a raw `DB::table()->update()`, a migration, a manual
 * SQL fix — can write a row the application considers impossible. This command is how that is noticed.
 *
 * Read-only. Exits non-zero when any violation is found, so it can gate a deploy.
 *
 * @see docs/Service-Fault-Separation-Audit.md L2
 * @see docs/Service-vs-Fault-Domain-Separation.md §2
 */
class EventKindIntegrity extends Command
{
    protected $signature = 'events:kind-integrity {--sample=10 : How many offending ids to print per check}';

    protected $description = 'Verify the Service/Fault/Inspection type invariants hold in the database';

    public function handle(): int
    {
        $sample     = max(1, (int) $this->option('sample'));
        $violations = 0;

        $this->info('Event Type integrity — ' . DB::connection()->getDriverName()
            . ' · EVENT_KIND_MODE=' . EventKind::mode());
        $this->newLine();

        $violations += $this->check(
            'kind is a known type',
            MaintenanceTask::query()->whereNotIn('kind', MaintenanceTask::KINDS),
            'A row carries a kind outside [' . implode(', ', MaintenanceTask::KINDS) . '].',
            $sample
        );

        $violations += $this->check(
            'at most one catalog reference per row',
            MaintenanceTask::query()->whereRaw(
                '(fault_catalog_id IS NOT NULL) + (service_catalog_id IS NOT NULL) + (inspection_type_id IS NOT NULL) + (damage_catalog_id IS NOT NULL) > 1'
            ),
            'A row references more than one catalog, so its type is ambiguous.',
            $sample
        );

        // The whole point of the damage kind: it must never be counted as evidence about the vehicle.
        $violations += $this->check(
            'damage never counts as reliability evidence',
            MaintenanceTask::query()
                ->where('kind', MaintenanceTask::KIND_DAMAGE)
                ->where(fn ($q) => $q->where('recurrence_flagged', true)->orWhereNotNull('recurrence_previous_task_id')),
            'A damage row carries recurrence state — something wrote reliability data from a non-fault.',
            $sample
        );

        foreach (MaintenanceTask::KIND_CATALOG_FK as $kind => $column) {
            $others = array_diff(MaintenanceTask::KIND_CATALOG_FK, [$column]);

            $violations += $this->check(
                "kind={$kind} references only {$column}",
                MaintenanceTask::query()->where('kind', $kind)->where(function ($q) use ($others) {
                    foreach ($others as $other) {
                        $q->orWhereNotNull($other);
                    }
                }),
                "A {$kind} row points at another type's catalog.",
                $sample
            );
        }

        $violations += $this->check(
            'classification_source is a known provenance',
            MaintenanceTask::query()->whereNotNull('classification_source')
                ->whereNotIn('classification_source', MaintenanceTask::CLS_SOURCES),
            'A row records a provenance the application does not define.',
            $sample
        );

        // Not a violation — a health signal. A recurrence review or flag on a non-fault means something
        // wrote one before the type guard existed, or around it.
        $strayReviews = DB::table('recurring_fault_reviews as r')
            ->join('maintenance_tasks as t', 't.id', '=', 'r.maintenance_task_id')
            ->where('t.kind', '!=', MaintenanceTask::KIND_FAULT)
            ->count();
        $strayFlags = MaintenanceTask::query()
            ->where('recurrence_flagged', true)
            ->where('kind', '!=', MaintenanceTask::KIND_FAULT)
            ->count();

        if ($strayReviews || $strayFlags) {
            $violations++;
            $this->error("  ✗ recurrence belongs to faults only — {$strayReviews} review(s), {$strayFlags} flagged non-fault task(s)");
        } else {
            $this->line('  <fg=green>✓</> recurrence belongs to faults only');
        }

        $this->newLine();

        if ($violations > 0) {
            $this->error("{$violations} integrity check(s) FAILED.");

            return self::FAILURE;
        }

        $this->info('All Event Type integrity checks passed.');

        return self::SUCCESS;
    }

    /** Run one check; report and count it. Returns 1 when it failed, 0 when it passed. */
    private function check(string $label, $query, string $why, int $sample): int
    {
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->line("  <fg=green>✓</> {$label}");

            return 0;
        }

        $ids = (clone $query)->limit($sample)->pluck('id')->implode(', ');
        $this->error("  ✗ {$label} — {$count} row(s)");
        $this->line("      {$why}");
        $this->line("      ids: {$ids}" . ($count > $sample ? ' …' : ''));

        return 1;
    }
}
