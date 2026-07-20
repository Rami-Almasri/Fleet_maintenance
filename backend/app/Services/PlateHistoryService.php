<?php

namespace App\Services;

use App\Models\PlateAssignment;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the "Plate History" view for a vehicle: every car that has ever carried the same
 * plate, current holder first, each tagged with the volume of history it owns.
 *
 * The golden rule of this feature — history is DISCOVERABLE, never TRANSFERRED. This service
 * only READS the plate_assignments timeline + per-vehicle counts; it never moves a maintenance,
 * inspection, repair or cost row between vehicles. Each holder keeps its own vehicle_id forever;
 * the only thing linking them is that they shared a plate over time.
 */
class PlateHistoryService
{
    /**
     * Build the plate-history payload for one vehicle.
     *
     * Resolves the vehicle's canonical plate_key (from the backfilled column, or freshly from
     * the plate digits as a fallback), gathers every vehicle sharing that key via the
     * plate_assignments timeline, and returns them ordered current-holder-first then newest.
     *
     * @return array{
     *   plate_key: ?string, plate_no: ?string, is_reused: bool, holder_count: int,
     *   current: ?array, holders: array<int, array<string, mixed>>
     * }
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $key = $vehicle->plate_key ?: PlateResolver::plateDigits($vehicle->plate_no);

        // No plate at all — nothing to show.
        if ($key === '' || $key === null) {
            return [
                'plate_key'    => null,
                'plate_no'     => $vehicle->plate_no,
                'is_reused'    => false,
                'holder_count' => 0,
                'current'      => null,
                'holders'      => [],
            ];
        }

        // Every timeline row for this plate, with its vehicle. withTrashed() so a soft-deleted
        // previous holder still appears in the history (the point of the feature).
        $assignments = PlateAssignment::query()
            ->where('plate_key', $key)
            ->with(['vehicle' => fn ($q) => $q->withTrashed()])
            ->get();

        // Fallback: if the timeline was never backfilled for this plate, derive holders straight
        // from the vehicles table so the UI still works (single-vehicle plates, or pre-backfill).
        if ($assignments->isEmpty()) {
            return $this->fromVehiclesOnly($key, $vehicle);
        }

        $vehicleIds = $assignments->pluck('vehicle_id')->filter()->unique()->values()->all();
        $counts     = $this->historyCounts($vehicleIds);

        $holders = $assignments
            ->filter(fn ($a) => $a->vehicle !== null)
            ->map(fn ($a) => $this->holder($a->vehicle, $a, $vehicle->id, $counts[$a->vehicle_id] ?? []))
            ->sort($this->holderOrder())
            ->values();

        $current = $holders->firstWhere('is_current', true);

        return [
            'plate_key'    => $key,
            'plate_no'     => $vehicle->plate_no ?: optional($current)['plate_no'],
            'is_reused'    => $holders->count() > 1,
            'holder_count' => $holders->count(),
            'current'      => $current,
            'holders'      => $holders->all(),
        ];
    }

    /**
     * Fallback holder list built directly from the vehicles table (no timeline rows yet).
     * Uses PlateResolver to mark the current holder so it agrees with the rest of the app.
     */
    private function fromVehiclesOnly(string $key, Vehicle $self): array
    {
        $matches = Vehicle::withTrashed()
            ->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'plate_key', 'make', 'model', 'year', 'color', 'status', 'status_no', 'car_serial', 'purchase_date', 'deleted_at'])
            ->filter(fn ($v) => ($v->plate_key ?: PlateResolver::plateDigits($v->plate_no)) === $key)
            ->values();

        if ($matches->isEmpty()) {
            $matches = collect([$self]);
        }

        $currentId = optional(PlateResolver::pickBest($matches))->id;
        $counts    = $this->historyCounts($matches->pluck('id')->all());

        $holders = $matches
            ->map(fn ($v) => $this->holder($v, null, $self->id, $counts[$v->id] ?? [], $v->id === $currentId))
            ->sort($this->holderOrder())
            ->values();

        return [
            'plate_key'    => $key,
            'plate_no'     => $self->plate_no,
            'is_reused'    => $holders->count() > 1,
            'holder_count' => $holders->count(),
            'current'      => $holders->firstWhere('is_current', true),
            'holders'      => $holders->all(),
        ];
    }

    /** Shape one holder row (a vehicle + its optional timeline assignment). */
    private function holder(Vehicle $v, ?PlateAssignment $a, int $selfId, array $counts, ?bool $isCurrentOverride = null): array
    {
        $gone = in_array($v->status, PlateResolver::GONE_STATUSES, true);
        $isCurrent = $isCurrentOverride ?? (bool) ($a?->is_current);

        return [
            'vehicle_id'  => $v->id,
            'is_self'     => $v->id === $selfId,          // the profile you're viewing
            'is_current'  => $isCurrent,                  // the plate's live holder
            'is_gone'     => $gone,                       // sold / disposed / returned
            'plate_no'    => $v->plate_no,
            'make'        => $v->make,
            'model'       => $v->model,
            'year'        => $v->year,
            'color'       => $v->color,
            'vin'         => $v->vin ?? null,
            'status'      => $v->status,
            'status_label' => Vehicle::STATUS_LABELS[$v->status] ?? $v->status,
            'car_serial'  => $v->car_serial,
            // Timeline window — when this car took the plate → when the next car took it.
            'from_date'   => $a ? optional($a->from_date)->toDateString() : $this->dateOnly($v->purchase_date),
            'to_date'     => $a ? optional($a->to_date)->toDateString() : null,
            'confidence'  => $a?->confidence ?? 'derived',
            'note'        => $a?->note,
            // How much history lives on THIS vehicle (stays here forever — never merged).
            'maintenance_count' => $counts['maintenance'] ?? 0,
            'inspection_count'  => $counts['inspection'] ?? 0,
            'repair_count'      => $counts['repair'] ?? 0,
        ];
    }

    /** Current holder first, then newest (highest car_serial, then id) — matches PlateResolver. */
    private function holderOrder(): callable
    {
        return function (array $a, array $b) {
            if ($a['is_current'] !== $b['is_current']) {
                return $a['is_current'] ? -1 : 1;   // current holder on top
            }
            return [(int) $b['car_serial'], (int) $b['vehicle_id']]
                <=> [(int) $a['car_serial'], (int) $a['vehicle_id']];
        };
    }

    /**
     * Per-vehicle history volumes in one grouped query each (no N+1). Same tables the
     * plate:review-ambiguous report counts, so the numbers agree across surfaces.
     *
     * @param  array<int, int>  $vehicleIds
     * @return array<int, array{maintenance:int, inspection:int, repair:int}>
     */
    private function historyCounts(array $vehicleIds): array
    {
        $ids = array_values(array_filter($vehicleIds));
        if (! $ids) {
            return [];
        }

        $group = fn (string $table) => DB::table($table)
            ->select('vehicle_id', DB::raw('count(*) as c'))
            ->whereIn('vehicle_id', $ids)
            ->groupBy('vehicle_id')
            ->pluck('c', 'vehicle_id');

        $maintenance = $group('maintenances');
        $inspection  = $group('inspection_records');
        $repair      = $group('maintenance_tasks');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'maintenance' => (int) ($maintenance[$id] ?? 0),
                'inspection'  => (int) ($inspection[$id] ?? 0),
                'repair'      => (int) ($repair[$id] ?? 0),
            ];
        }

        return $out;
    }

    private function dateOnly($v): ?string
    {
        return empty($v) ? null : substr((string) $v, 0, 10);
    }
}
