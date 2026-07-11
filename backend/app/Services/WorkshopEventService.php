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

    public function destroy(Maintenance $event): void
    {
        $vehicleId = $event->vehicle_id;
        DB::transaction(function () use ($event, $vehicleId) {
            $event->delete();
            // Cascade: removing the last open garage event may free the car.
            $this->cascadeOperationalStatus($vehicleId);
        });
    }

    /**
     * "Delete" a SHEET-synced event. Its source is the Google Sheet, so we can't truly
     * remove it — instead we record a TOMBSTONE keyed by the importer's row_hash and
     * hard-delete the local row. The row leaving means it drops off the board, cost and
     * utilization at once; the tombstone makes every future sync skip it. The full
     * attributes are stashed so Restore can recreate the row verbatim.
     */
    public function tombstone(Maintenance $event, ?int $userId = null, ?string $note = null): MaintenanceTombstone
    {
        if (! $event->row_hash) {
            // No stable sheet identity to skip on re-import — refuse rather than leak a
            // ghost the sync would immediately resurrect.
            throw new RuntimeException('This event has no sheet identity and cannot be tombstoned.');
        }

        return DB::transaction(function () use ($event, $userId, $note) {
            $event->loadMissing(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
            $vehicleId = $event->vehicle_id;

            $tombstone = MaintenanceTombstone::updateOrCreate(
                ['row_hash' => $event->row_hash],
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

            $event->delete();
            // Cascade: removing an open garage event may free the car.
            $this->cascadeOperationalStatus($vehicleId);

            return $tombstone;
        });
    }

    /**
     * Bring a tombstoned sheet event back: recreate its `maintenances` row from the stored
     * attributes (a raw insert, so casts can't double-encode the JSON columns) and drop the
     * tombstone so the sync resumes owning it.
     */
    public function restore(MaintenanceTombstone $tombstone): Maintenance
    {
        return DB::transaction(function () use ($tombstone) {
            $attrs = $tombstone->payload ?? [];
            unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);

            $id = DB::table('maintenances')->insertGetId($attrs);
            $tombstone->delete();

            $event = Maintenance::findOrFail($id);
            $this->cascadeOperationalStatus($event->vehicle_id);

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
