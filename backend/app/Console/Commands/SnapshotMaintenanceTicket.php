<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\OperationsService;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SNAPSHOT / RESTORE a single maintenance ticket's REAL state.
 *
 * Use it as a safety net around hand-editing a live ticket: snapshot the ticket now, poke at it through
 * the UI (change status, cost, findings, tasks, line items …), then restore it verbatim when you're done.
 * Unlike maintenance:seed-workflow (which fabricates test tickets), this preserves YOUR real row exactly.
 *
 * It captures the `maintenances` row itself plus every child table that hangs off it — tasks (+ their
 * assignments), line items, media, watchers, logistics moves (+ their events), the vehicle_log_events
 * audit trail and maintenance_invoices — to a JSON file, and on --restore re-inserts them verbatim,
 * PRESERVING primary keys, so nothing that references the ticket by id breaks.
 *
 * Raw DB::table read/writes are used on purpose (no Eloquent casts / mutators / booted() hooks) so the
 * bytes on disk match the bytes in the DB — a restore is a true rollback, not a re-derivation.
 *
 *   php artisan maintenance:snapshot-ticket 1234              # save ticket 1234's real state
 *   php artisan maintenance:snapshot-ticket PLATE-123        # save (car's single open ticket)
 *   php artisan maintenance:snapshot-ticket 1234 --restore   # put it back exactly
 *   php artisan maintenance:snapshot-ticket 1234 --restore --dry-run
 */
class SnapshotMaintenanceTicket extends Command
{
    protected $signature = 'maintenance:snapshot-ticket
                            {ticket : Ticket id, or a plate_no whose single open ticket to capture}
                            {--restore : Restore the ticket from its saved snapshot instead of saving}
                            {--file= : Snapshot file path (default: ticket-snapshots/ticket-{id}.json on the local disk)}
                            {--dry-run : On --restore, run inside a rolled-back transaction and report only}';

    protected $description = 'Snapshot a maintenance ticket to JSON and restore it verbatim later (real-state safety net)';

    /**
     * child table => foreign key that points back at the ticket (maintenances.id).
     * Order matters on restore: parents before their grandchildren are handled explicitly below.
     */
    private const CHILD_TABLES = [
        'maintenance_tasks'      => 'maintenance_id',
        'maintenance_line_items' => 'maintenance_id',
        'maintenance_media'      => 'maintenance_id',
        'maintenance_watchers'   => 'maintenance_id',
        'logistics_tasks'        => 'maintenance_id',
        'vehicle_log_events'     => 'maintenance_id',
        'maintenance_invoices'   => 'maintenance_id',
    ];

    public function __construct(private OperationsService $operations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ticketId = $this->resolveTicketId($this->argument('ticket'));
        if (! $ticketId) {
            return self::FAILURE;
        }

        return $this->option('restore')
            ? $this->restore($ticketId)
            : $this->save($ticketId);
    }

    /** Accept a numeric ticket id directly, or a plate whose single OPEN workflow ticket we capture. */
    private function resolveTicketId(string $arg): ?int
    {
        if (is_numeric($arg)) {
            if (! DB::table('maintenances')->where('id', (int) $arg)->exists()) {
                $this->error("No ticket #{$arg}.");
                return null;
            }
            return (int) $arg;
        }

        $vehicleId = PlateResolver::resolve($arg, withTrashed: true)?->id;
        if (! $vehicleId) {
            $this->error("No vehicle with plate '{$arg}'.");
            return null;
        }

        $ids = Maintenance::openWorkflow()->where('vehicle_id', $vehicleId)->pluck('id');
        if ($ids->count() !== 1) {
            $this->error($ids->isEmpty()
                ? "Car '{$arg}' has no open ticket — pass a ticket id instead."
                : "Car '{$arg}' has {$ids->count()} open tickets — pass a specific ticket id.");
            return null;
        }

        return (int) $ids->first();
    }

    private function filePath(int $ticketId): string
    {
        return $this->option('file') ?: "ticket-snapshots/ticket-{$ticketId}.json";
    }

    private function save(int $ticketId): int
    {
        $snapshot = [
            'ticket_id'   => $ticketId,
            'captured_at' => now()->toIso8601String(),
            'maintenance' => (array) DB::table('maintenances')->where('id', $ticketId)->first(),
            'children'    => [],
        ];

        foreach (self::CHILD_TABLES as $table => $fk) {
            $snapshot['children'][$table] = DB::table($table)->where($fk, $ticketId)->get()
                ->map(fn ($r) => (array) $r)->all();
        }

        // Grandchildren keyed by a parent row's id (no direct maintenance_id).
        $taskIds = array_column($snapshot['children']['maintenance_tasks'], 'id');
        $snapshot['children']['maintenance_task_assignments'] = $taskIds
            ? DB::table('maintenance_task_assignments')->whereIn('maintenance_task_id', $taskIds)
                ->get()->map(fn ($r) => (array) $r)->all()
            : [];

        $logisticsIds = array_column($snapshot['children']['logistics_tasks'], 'id');
        $snapshot['children']['logistics_task_events'] = $logisticsIds
            ? DB::table('logistics_task_events')->whereIn('logistics_task_id', $logisticsIds)
                ->get()->map(fn ($r) => (array) $r)->all()
            : [];

        $path = $this->filePath($ticketId);
        Storage::put($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Snapshot saved: {$path}");
        $this->table(
            ['table', 'rows'],
            collect($snapshot['children'])->map(fn ($rows, $t) => [$t, count($rows)])
                ->prepend(['maintenances', 1])->values()->all()
        );
        $this->line('Restore later with: php artisan maintenance:snapshot-ticket ' . $ticketId . ' --restore');

        return self::SUCCESS;
    }

    /**
     * Every table that references `maintenances` but is NOT in CHILD_TABLES, together with the live rows
     * it holds for this ticket — i.e. exactly what a restore would destroy without being able to replace.
     *
     * The list is written out rather than read from information_schema so it stays readable and reviewable
     * alongside the migrations; a table missing from the environment is skipped, never fatal. Keep it in
     * sync when a new table gains a `maintenance_id`.
     *
     * @return array<string,int> table => live rows not covered by the snapshot (zero-counts dropped)
     */
    private function uncoveredRows(int $ticketId): array
    {
        // Cascade-deleted or nulled by the header delete, and absent from CHILD_TABLES.
        $related = [
            'maintenance_signatures', 'repair_inspections', 'recurring_fault_reviews',
            'garage_recommendation_decisions', 'maintenance_required_parts',
            'garage_invoice_submissions', 'resolved_transfer_flags',
            'maintenance_checkpoints', 'maintenance_handovers', 'maintenance_handover_comparisons',
            'maintenance_incidents', 'maintenance_temporary_releases', 'maintenance_responsibles',
            'maintenance_task_actions', 'part_requests', 'part_purchases',
            'odometer_block_events', 'contact_reminders', 'component_events', 'service_records',
            'complaints', 'complaint_events', 'capture_friction', 'domain_events',
        ];

        $uncovered = [];

        foreach ($related as $table) {
            if (array_key_exists($table, self::CHILD_TABLES)) {
                continue; // the snapshot carries it
            }
            try {
                $n = DB::table($table)->where('maintenance_id', $ticketId)->count();
            } catch (\Throwable) {
                continue; // table or column not present in this environment
            }
            if ($n > 0) {
                $uncovered[$table] = $n;
            }
        }

        arsort($uncovered);

        return $uncovered;
    }

    private function restore(int $ticketId): int
    {
        $path = $this->filePath($ticketId);
        if (! Storage::exists($path)) {
            $this->error("No snapshot at {$path}. Save one first (without --restore).");
            return self::FAILURE;
        }

        $snapshot = json_decode(Storage::get($path), true);
        if (! $snapshot || ($snapshot['ticket_id'] ?? null) !== $ticketId) {
            $this->error('Snapshot file is unreadable or is for a different ticket.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line("Restoring ticket #{$ticketId} from snapshot taken {$snapshot['captured_at']}"
            . ($dryRun ? ' [DRY RUN]' : '') . '…');

        // ── COVERAGE GATE ────────────────────────────────────────────────────────────────────────────
        // Restore deletes the header row, and that delete cascades through EVERY table that references
        // `maintenances` — not merely the ones in CHILD_TABLES. Any related table this command does not
        // know about is therefore destroyed and never put back: a "safety net" that quietly eats data it
        // was never taught to carry. So before touching anything, look for live rows in the related
        // tables that are NOT covered by the snapshot, and refuse if any exist.
        $uncovered = $this->uncoveredRows($ticketId);

        if ($uncovered !== []) {
            $this->newLine();
            $this->error('REFUSING TO RESTORE — this ticket has rows in tables the snapshot does not carry.');
            $this->error('Deleting the header would cascade these away with no copy to restore from:');
            $this->table(
                ['Uncovered table', 'Live rows that would be lost'],
                array_map(fn ($t, $n) => [$t, number_format($n)], array_keys($uncovered), $uncovered)
            );
            $this->line('Fix: add the table to CHILD_TABLES (and re-take the snapshot), or clear those rows deliberately.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($ticketId, $snapshot, $dryRun) {
                // 1) Wipe the current live state (grandchildren first, respecting FKs).
                $liveTaskIds = DB::table('maintenance_tasks')->where('maintenance_id', $ticketId)->pluck('id')->all();
                if ($liveTaskIds) {
                    DB::table('maintenance_task_assignments')->whereIn('maintenance_task_id', $liveTaskIds)->delete();
                }
                $liveLogisticsIds = DB::table('logistics_tasks')->where('maintenance_id', $ticketId)->pluck('id')->all();
                if ($liveLogisticsIds) {
                    DB::table('logistics_task_events')->whereIn('logistics_task_id', $liveLogisticsIds)->delete();
                }
                foreach (self::CHILD_TABLES as $table => $fk) {
                    DB::table($table)->where($fk, $ticketId)->delete();
                }

                // 2) Restore the header row verbatim (upsert on the primary key).
                DB::table('maintenances')->where('id', $ticketId)->delete();
                DB::table('maintenances')->insert($snapshot['maintenance']);

                // 3) Re-insert every child + grandchild verbatim, PKs preserved.
                foreach ($snapshot['children'] as $table => $rows) {
                    if ($rows) {
                        DB::table($table)->insert($rows);
                    }
                }

                // 4) Re-derive the car's live operational status from the restored ticket.
                $vehicleId = $snapshot['maintenance']['vehicle_id'] ?? null;
                if ($vehicleId && $vehicle = Vehicle::find($vehicleId)) {
                    $status = $this->operations->reconcileVehicleOperationalStatus($vehicle);
                    $this->line("  · vehicle {$vehicleId} → {$status}");
                }

                if ($dryRun) {
                    throw new RestoreDryRunRollback();
                }
            });
        } catch (RestoreDryRunRollback $e) {
            // expected: the transaction rolled back, nothing was written.
        }

        if ($dryRun) {
            $this->warn('DRY RUN — everything was rolled back. Re-run without --dry-run to apply.');
        } else {
            $this->info("Ticket #{$ticketId} restored to its snapshot state. Refresh the board.");
        }

        return self::SUCCESS;
    }
}

/** Internal marker used to roll back a --dry-run restore. */
class RestoreDryRunRollback extends \RuntimeException
{
}
