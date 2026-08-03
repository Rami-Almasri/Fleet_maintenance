<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceTombstone;
use App\Models\Vehicle;
use App\Models\Vendor;
use RuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Create / edit / delete WORKSHOP EVENTS (rows in `maintenances`, origin = 'manual')
 * straight from the dashboard — the system, not the Google Sheet, owning the garage log.
 *
 * Each event is anchored to a VEHICLE (never a contract_id, to stay clear of the 1:1
 * contract-header unique index) and is matched to a visit by the same date-window rule
 * the imported sheet events use, so manual and synced events flow through one board.
 *
 * The system manages the event's STATE:
 *   - event_status is the workshop stage (OUT / IN / Follow up / Change / Delay / Test).
 *   - choosing 'IN' records the return (actual_in_date); any other stage clears it, so a
 *     car that isn't back can never carry a return date.
 *   - the chosen issue KEYWORD is resolved to a controlled maintenance_reason (authoritative
 *     maintenance_reason_id), so the board's priority/level follows the keyword — not a guess.
 */
class WorkshopEventService
{
    public function __construct(
        protected MaintenanceAnalyticsService $analytics,
        protected OperationsService $operations,
    ) {
    }

    /**
     * Workshop events for a vehicle (and optionally narrowed to one contract's visit
     * window), newest stage first. Includes both hand-entered and synced sheet events.
     *
     * @param  array{vehicle_id?:int, contract_id?:int}  $filters
     */
    public function index(array $filters = [])
    {
        $query = Maintenance::query()
            ->workshopEvents()
            ->with(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);

        // Narrow to ONE contract's visit window — the same date-window link rule the board
        // uses — so the contract page shows only what happened on THIS visit (the problem
        // and the fix), not the car's whole lifetime garage history.
        if ($contractId = $filters['contract_id'] ?? null) {
            $contract = Contract::find($contractId);
            if (! $contract || ! $contract->vehicle_id || ! $contract->out_date) {
                return new Collection();   // can't window-match → no events for this contract
            }
            $start = $contract->out_date->copy()->subDays(MaintenanceAnalyticsService::LINK_BUFFER_DAYS);
            $query->where('vehicle_id', $contract->vehicle_id)
                  ->whereNotNull('out_date')
                  ->whereDate('out_date', '>=', $start->toDateString());
            if ($contract->in_date) {              // closed visit → hard cutoff at the return date
                $query->whereDate('out_date', '<=', $contract->in_date->toDateString());
            }
        } elseif ($vehicleId = $filters['vehicle_id'] ?? null) {
            $query->where('vehicle_id', $vehicleId);
        }

        return $query
            ->orderByRaw('out_date IS NULL, out_date DESC')
            ->orderByDesc('id')
            ->get();
    }

    public function store(array $data): Maintenance
    {
        return DB::transaction(function () use ($data) {
            $event = new Maintenance();
            $event->origin = Maintenance::ORIGIN_MANUAL;
            $this->fill($event, $data);
            $event->save();

            // Cascade: a new garage event drives the car's live availability (in garage / freed).
            $this->cascadeOperationalStatus($event->vehicle_id);

            return $event->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
        });
    }

    public function update(array $data, Maintenance $event): Maintenance
    {
        return DB::transaction(function () use ($data, $event) {
            $previousVehicleId = $event->vehicle_id;
            $this->fill($event, $data);
            $event->save();

            // Cascade for both the new vehicle and (if it was re-pointed) the old one, so neither
            // is left showing a stale "In Maintenance" after the event moved or closed ('IN').
            $this->cascadeOperationalStatus($event->vehicle_id);
            if ($previousVehicleId && $previousVehicleId !== $event->vehicle_id) {
                $this->cascadeOperationalStatus($previousVehicleId);
            }

            return $event->refresh()->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
        });
    }

    /**
     * @deprecated Use tombstone() — the single deletion path. Kept only so any caller outside this
     *             codebase's own controller does not silently change behaviour; it now delegates.
     *
     * This used to be the "hand-entered events are ours to destroy" branch. It was the wrong half of
     * the system to treat as disposable: a sheet event can be re-imported from Google Sheets, a manual
     * one exists nowhere else. Two deletion paths also meant two behaviours to keep in step, which is
     * how one of them ended up nulling the vehicle timeline while the other did not.
     */
    public function destroy(Maintenance $event, ?int $userId = null): void
    {
        $this->tombstone($event, $userId);
    }

    /**
     * Write the "this ticket was destroyed" marker onto the vehicle's trail.
     *
     * Runs INSIDE the caller's transaction and BEFORE the delete, for the obvious reason that a row
     * cannot be inspected once it is gone — the child counts below are the last chance to record what
     * the cascade is about to take. The ticket's identity goes into `meta` because this event row's own
     * `maintenance_id` is nulled by that same cascade (see VehicleLogEvent::EVENT_TICKET_DELETED).
     *
     * Deliberately NOT best-effort. Everywhere else an audit write is swallowed so it can never break a
     * real workflow transition; here the audit row IS the point — losing it silently would recreate the
     * exact blind spot this event exists to close, so a failure aborts the enclosing transaction and the
     * delete does not happen.
     *
     * PUBLIC because every path that destroys a ticket must produce the SAME record: the UI delete and
     * the tombstone below, plus the bulk `maintenance:reset-workflow` reset. One definition of what a
     * deletion looks like is what lets a completeness check read them all the same way.
     *
     * @param  string  $mode  hard_delete | tombstone | bulk_reset — which path destroyed it
     */
    public function logDestruction(Maintenance $event, string $mode, ?int $userId = null): void
    {
        if (! $event->vehicle_id) {
            return; // nothing to hang the trail on
        }

        \App\Models\VehicleLogEvent::create([
            'vehicle_id'      => $event->vehicle_id,
            'maintenance_id'  => $event->id,   // nulled by the cascade — meta.ticket_id is the durable copy
            'event_type'      => \App\Models\VehicleLogEvent::EVENT_TICKET_DELETED,
            'source_tag'      => Maintenance::FINDING_INSPECTOR,
            'workflow_status' => $event->workflow_status,
            'description'     => $mode === 'tombstone'
                ? 'Sheet-synced workshop event removed (tombstoned — future syncs will skip it).'
                : 'Workshop event permanently deleted.',
            'meta'            => [
                'ticket_id'       => $event->id,
                'mode'            => $mode,
                'origin'          => $event->origin,
                'workflow_status' => $event->workflow_status,
                'out_date'        => optional($event->out_date)->toDateString(),
                'garage'          => $event->garage,
                'row_hash'        => $event->row_hash,
                // What the ON DELETE CASCADE is about to destroy. Recorded so a later integrity audit
                // can tell "this ticket never had faults" from "its faults were deleted with it".
                'cascade_lost'    => $this->cascadeCounts($event->id),
            ],
            'actor_id'        => $userId,
            'occurred_at'     => Carbon::now(),
        ]);
    }

    /**
     * Child rows that will die with this ticket, table => count (zero-counts omitted).
     * Mirrors the ON DELETE CASCADE constraints; a table absent in this environment is skipped rather
     * than fataling, so an older migration set can never block a delete.
     *
     * @return array<string,int>
     */
    private function cascadeCounts(int $ticketId): array
    {
        $lost = [];

        foreach ([
            'maintenance_tasks', 'maintenance_line_items', 'maintenance_invoices',
            'repair_inspections', 'maintenance_signatures', 'recurring_fault_reviews',
            'garage_recommendation_decisions', 'maintenance_required_parts',
            'garage_invoice_submissions', 'resolved_transfer_flags', 'maintenance_watchers',
        ] as $table) {
            try {
                $n = DB::table($table)->where('maintenance_id', $ticketId)->count();
            } catch (\Throwable) {
                continue;
            }
            if ($n > 0) {
                $lost[$table] = $n;
            }
        }

        // Grandchildren — the per-garage stints hang off the faults, so they die two levels down.
        try {
            $taskIds = DB::table('maintenance_tasks')->where('maintenance_id', $ticketId)->pluck('id');
            if ($taskIds->isNotEmpty()) {
                $n = DB::table('maintenance_task_assignments')->whereIn('maintenance_task_id', $taskIds)->count();
                if ($n > 0) {
                    $lost['maintenance_task_assignments'] = $n;
                }
            }
        } catch (\Throwable) {
            // nothing to add
        }

        return $lost;
    }

    /**
     * THE ONE DELETION PATH. Retire a workshop event — sheet-synced or hand-entered alike.
     *
     * Two things happen, and they answer two different questions:
     *   · the row is SOFT-deleted — "stop showing this". No cascade fires, no `vehicle_log_events`
     *     link is nulled, every fault / stint / inspection / signature stays attached to it.
     *   · a TOMBSTONE is written — "and stop re-importing it". This half only means anything for a
     *     sheet row (the importer checks the hash); for a manual event it is simply the restore record.
     *
     * The payload snapshot is kept for the display ghost and as a belt-and-braces copy of the row's
     * columns. It is no longer what restore() reads the ticket back FROM — the ticket itself is still
     * there. See restore().
     */
    public function tombstone(Maintenance $event, ?int $userId = null, ?string $note = null): MaintenanceTombstone
    {
        $key = $this->tombstoneKey($event);

        return DB::transaction(function () use ($event, $userId, $note, $key) {
            $event->loadMissing(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
            $vehicleId = $event->vehicle_id;

            $tombstone = MaintenanceTombstone::updateOrCreate(
                ['row_hash' => $key],
                [
                    'vehicle_id' => $vehicleId,
                    'origin'     => $event->origin,
                    'out_date'   => $event->out_date,
                    'payload'    => $event->getAttributes(),   // raw columns → lossless restore
                    'display'    => $this->displaySnapshot($event),
                    'note'       => $note,
                    'created_by' => $userId,
                ]
            );

            $this->logDestruction($event, 'tombstone', $userId);
            $event->delete();
            // Cascade: removing an open garage event may free the car.
            $this->cascadeOperationalStatus($vehicleId);

            return $tombstone;
        });
    }

    /**
     * The identity a tombstone is filed under.
     *
     * Sheet-synced events already have one — `row_hash` — and it does double duty: it is both the
     * tombstone's key and the token every future import checks before re-inserting the row.
     *
     * Hand-entered events have no `row_hash`, and that used to mean they could not be tombstoned at all,
     * so the controller hard-deleted them instead. The effect was exactly backwards: the ONE class of
     * event that can be re-synced from Google Sheets was preserved, and the one that exists nowhere else
     * was destroyed — taking its `vehicle_log_events` linkage with it via `nullOnDelete`.
     *
     * A synthetic `manual:{id}` key fixes that. It cannot collide with a real hash (those are hex
     * digests), and the "skip on future sync" half of the contract is simply inert for a row the
     * importer has never heard of. What matters is the other half: the payload is kept and the event is
     * restorable.
     */
    private function tombstoneKey(Maintenance $event): string
    {
        return $event->row_hash ?: 'manual:' . $event->id;
    }

    /**
     * Bring a tombstoned sheet event back: recreate its `maintenances` row from the stored
     * attributes (a raw insert, so casts can't double-encode the JSON columns) and drop the
     * tombstone so the sync resumes owning it.
     *
     * ── THIS IS NOW A REAL UNDO, AND THE HISTORY EXPLAINS THE SECOND BRANCH ───────────────────────
     * Since `maintenances` is soft-deleted, a retired ticket was never destroyed: restoring it clears
     * `deleted_at` and the whole graph — faults, garage stints, repair inspections, line items,
     * signatures, timeline events — is still attached, because no cascade ever fired and no link was
     * ever nulled. Identity is unchanged, so nothing needs re-associating.
     *
     * Tombstones created BEFORE that change have no row to un-trash: the delete really did destroy the
     * children and null the links. Those fall to the payload branch, which recovers the ticket's own
     * columns (and its primary key, so newer `maintenance_ref` rows still resolve) and nothing more.
     * `restored_from_payload` marks that case so the caller can say so rather than implying a clean undo.
     *
     * @return Maintenance the restored row; check `restored_from_payload` / `restored_with_new_id`
     */
    public function restore(MaintenanceTombstone $tombstone): Maintenance
    {
        return DB::transaction(function () use ($tombstone) {
            $attrs      = $tombstone->payload ?? [];
            $originalId = $attrs['id'] ?? null;

            // THE HAPPY PATH, and now the only one that should ever occur: the ticket was soft-deleted,
            // so it is still sitting there with every fault, stint, inspection and timeline event
            // attached. Restoring is un-setting `deleted_at` — the identity never changed, so nothing
            // has to be re-associated. This is what makes the operation an undo rather than a re-entry.
            $trashed = $originalId !== null
                ? Maintenance::onlyTrashed()->find($originalId)
                : null;

            if ($trashed) {
                $trashed->restore();
                $tombstone->delete();
                $this->cascadeOperationalStatus($trashed->vehicle_id);
                $trashed->restored_with_new_id = false;
                $trashed->restored_from_payload = false;

                return $trashed->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
            }

            // ── LEGACY / DEGRADED PATH ────────────────────────────────────────────────────────────
            // Tombstones written BEFORE soft delete hard-deleted their row, so there is nothing to
            // un-trash and the payload is all that survives. Re-inserting it recovers the ticket's own
            // columns and nothing else: whatever cascaded away at delete time (faults, stints,
            // signatures) is gone, and the `vehicle_log_events` links nulled back then stay nulled.
            // Reusing the primary key at least restores the ticket's IDENTITY, so `maintenance_ref`
            // rows written since can still point at it. Flagged so the caller can say all this out loud
            // instead of reporting a clean "restored".
            unset($attrs['created_at'], $attrs['updated_at']);

            $idAvailable = $originalId !== null
                && ! DB::table('maintenances')->where('id', $originalId)->exists();

            if ($idAvailable) {
                DB::table('maintenances')->insert($attrs);
                $id = (int) $originalId;
            } else {
                unset($attrs['id']);
                $id = DB::table('maintenances')->insertGetId($attrs);
            }

            $tombstone->delete();

            $event = Maintenance::findOrFail($id);
            $this->cascadeOperationalStatus($event->vehicle_id);

            // Transient markers (not columns) so the controller can describe what actually came back.
            $event->restored_with_new_id  = ! $idAvailable;
            $event->restored_from_payload = true;

            return $event->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
        });
    }

    /**
     * Tombstoned (deleted) sheet events for the same scope as index(), so the Manage-Events
     * list can show greyed "removed" ghosts with a Restore button.
     *
     * @param  array{vehicle_id?:int, contract_id?:int}  $filters
     */
    public function tombstonesFor(array $filters = []): Collection
    {
        if ($contractId = $filters['contract_id'] ?? null) {
            $contract = Contract::find($contractId);
            if (! $contract || ! $contract->vehicle_id || ! $contract->out_date) {
                return new Collection();
            }
            $start = $contract->out_date->copy()->subDays(MaintenanceAnalyticsService::LINK_BUFFER_DAYS);
            $query = MaintenanceTombstone::where('vehicle_id', $contract->vehicle_id)
                ->whereNotNull('out_date')
                ->whereDate('out_date', '>=', $start->toDateString());
            if ($contract->in_date) {
                $query->whereDate('out_date', '<=', $contract->in_date->toDateString());
            }
        } elseif ($vehicleId = $filters['vehicle_id'] ?? null) {
            $query = MaintenanceTombstone::where('vehicle_id', $vehicleId);
        } else {
            return new Collection();
        }

        return $query->orderByRaw('out_date IS NULL, out_date DESC')->orderByDesc('id')->get();
    }

    /** A small render snapshot (issues + keyword-driven priority) for the ghost row. */
    protected function displaySnapshot(Maintenance $event): array
    {
        $issues   = $this->analytics->sheetIssueTags($event);
        $byKind   = $this->analytics->sheetIssueTagsByKind($event);
        // Priority is scored from the FAULT labels only — an oil change on the same visit must not set
        // the ghost row's severity (audit M8).
        $priority = ($event->maintenance_reason_id && $event->reason)
            ? ['level' => $event->reason->level, 'matched' => $event->reason->reason_en]
            : $this->analytics->classifyPriority($issues, $event->maintenance_notes);

        return [
            'origin'               => $event->origin,
            'plate'                => $event->vehicle?->plate_no ?: $event->plate,
            'car'                  => $event->vehicle ? trim($event->vehicle->make . ' ' . $event->vehicle->model) : $event->car_label,
            'stage'                => $event->event_status,
            'garage'               => $event->vendor?->name ?: $event->garage,
            'issues'               => $issues,
            'fault_tags'           => $byKind['fault'],
            'service_tags'         => $byKind['service'],
            'damage_tags'          => $byKind['damage'],
            'context_tags'         => $byKind['context'],
            'maintenance_type'     => $event->maintenance_type,
            'out_date'             => optional($event->out_date)->toDateString(),
            'expected_return_date' => optional($event->expected_return_date)->toDateString(),
            'actual_in_date'       => optional($event->actual_in_date)->toDateString(),
            'cost'                 => $event->cost !== null ? (float) $event->cost : null,
            'responsible'          => $event->responsible,
            'notes'                => $event->maintenance_notes,
            'priority'             => $priority['level'],
            'priority_matched'     => $priority['matched'],
        ];
    }

    /**
     * Re-derive ONE vehicle's live operational_status after its garage log changed.
     * Contracts still win (an open rental/maintenance contract is the source of truth); the
     * manual event only decides availability when no governing contract is open — and the
     * fleet-wide reconcile uses the same rule, so a later sync won't undo this.
     */
    protected function cascadeOperationalStatus(?int $vehicleId): void
    {
        if (! $vehicleId) {
            return;
        }
        if ($vehicle = Vehicle::find($vehicleId)) {
            $this->operations->reconcileVehicleOperationalStatus($vehicle);
        }
    }

    /**
     * Map validated input onto the event row + run the system-managed state rules.
     * Only keys actually present in $data are touched, so a partial update (PATCH-style)
     * never blanks fields the caller didn't send.
     */
    protected function fill(Maintenance $event, array $data): void
    {
        // Direct passthrough columns (whatever the request supplied).
        foreach ([
            'vehicle_id', 'vendor_id', 'event_status', 'maintenance_type', 'visit_context',
            'service_main', 'service_sup', 'damage_location', 'severity',
            'responsible', 'approved_by', 'liable_party', 'charge_to', 'driver',
            'spare_part', 'invoice_no', 'cost', 'cost_notes', 'maintenance_notes',
            'out_date', 'expected_return_date', 'follow_date', 'actual_in_date', 'base_on',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $event->{$key} = $data[$key] === '' ? null : $data[$key];
            }
        }

        // The keyword(s): the dashboard sends `issues` (array). Store them comma-joined in
        // service_main (the board's issue source) and resolve them to a controlled reason.
        if (array_key_exists('issues', $data)) {
            $issues = collect($data['issues'] ?? [])
                ->map(fn ($t) => trim((string) $t))
                ->filter()
                ->unique()
                ->values();
            $event->service_main = $issues->isNotEmpty() ? $issues->implode(', ') : ($event->service_main ?: null);
        }

        // Stage defaults to OUT for a brand-new event with nothing chosen.
        if (! $event->event_status) {
            $event->event_status = 'OUT';
        }

        // Keep the raw garage label in step with the chosen vendor (board shows vendor ?: garage).
        if ($event->vendor_id && ! $event->garage) {
            $event->garage = optional(Vendor::find($event->vendor_id))->name;
        }

        // --- system-managed state: the keyword drives the priority/level --------------
        // Re-resolve on every save so editing the keyword re-classifies the event.
        $reason = $this->analytics->reasonFor($this->issueTags($event), $event->maintenance_notes);
        $event->maintenance_reason_id = $reason?->id;

        // --- system-managed state: 'IN' means the car is back ------------------------
        if ($event->event_status === 'IN') {
            // Record the return if the caller didn't give an explicit one.
            if (empty($event->actual_in_date)) {
                $event->actual_in_date = $event->out_date ?: Carbon::today();
            }
        } else {
            // Not back yet → it cannot hold a return date.
            $event->actual_in_date = null;
        }
    }

    /** The event's issue keywords (service_main + service_sup split on commas). */
    protected function issueTags(Maintenance $event): array
    {
        return $this->analytics->sheetIssueTags($event);
    }
}
