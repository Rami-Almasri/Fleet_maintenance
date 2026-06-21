<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vendor;
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
    public function __construct(protected MaintenanceAnalyticsService $analytics)
    {
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

            return $event->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
        });
    }

    public function update(array $data, Maintenance $event): Maintenance
    {
        return DB::transaction(function () use ($data, $event) {
            $this->fill($event, $data);
            $event->save();

            return $event->refresh()->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);
        });
    }

    public function destroy(Maintenance $event): void
    {
        $event->delete();
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
            'vehicle_id', 'vendor_id', 'event_status', 'maintenance_type',
            'service_main', 'service_sup', 'damage_location', 'severity',
            'responsible', 'approved_by', 'liable_party', 'charge_to', 'driver',
            'spare_part', 'invoice_no', 'cost', 'cost_notes', 'maintenance_notes',
            'out_date', 'expected_return_date', 'follow_date', 'base_on',
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
