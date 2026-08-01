<?php

namespace App\Services\Components;

use App\Models\ComponentCatalog;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Support\ServiceTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle Installed Components — the READ side of the Asset Layer.
 *
 * This class computes and never writes. Every number it returns is DERIVED at read time from rows
 * the maintenance workflow already wrote (vehicle_components + component_events + service_records);
 * nothing here is stored, so there is no cache to invalidate and no "sync" that can drift. The
 * write side is ComponentService, reached only from the workflow — see the class docblock there.
 *
 * Two write paths feed one read:
 *   ASSETS      — vehicle_components rows: discrete parts with identity, cost and a warranty.
 *   CONSUMABLES — service_records rows: fluids and fit-and-forget items that by standing rule never
 *                 become components ({@see VehicleComponent::booted}). Operators still ask "when was
 *                 the oil last changed?", so the latest service per consumable type is merged into
 *                 the same table, tagged `kind: consumable` so the UI can say where it came from.
 *
 * DERIVED vs FACT (Evidence Layer governance): installed_at / odometer / cost / supplier are FACTS
 * copied from the purchase. age, life_used_pct, warranty status, cost_per_km and service_life are
 * DERIVED and are labelled as such in the payload so no consumer mistakes an estimate for a record.
 */
class ComponentReadModel
{
    /**
     * The lifecycle thresholds live in {@see ComponentLifecycle} (pure, unit-tested). Re-exported
     * here so API consumers can read the window off the payload without a second import.
     */
    public const WARRANTY_WINDOW_DAYS = ComponentLifecycle::WARRANTY_WINDOW_DAYS;

    /**
     * Consumable catalog slug → the service_type whose latest record refreshes it. ONLY unambiguous
     * pairs appear here: a consumable with no honest service_type (bulbs, wiper blades — replaced
     * during unrelated work and never logged as their own action) is simply absent, because showing
     * it with a guessed date would be worse than not showing it at all.
     */
    /**
     * Everything {@see present()} touches. present() reads catalog, supplier, installer, vehicle AND
     * sourcePurchase (for the install ticket id) — miss any one and every row costs an extra query,
     * which is how the fleet dashboard once issued 4,957 of them. Load this set wherever present()
     * is applied to more than a single row.
     */
    private const PRESENT_RELATIONS = [
        'catalog',
        'supplier:id,name',
        'installer:id,name',
        'vehicle:id,plate_no,odometer',
        'sourcePurchase:id,maintenance_id',
    ];

    private const CONSUMABLE_SERVICE_TYPE = [
        'engine-oil'  => ServiceTypes::OIL_CHANGE,
        'oil-filter'  => ServiceTypes::OIL_CHANGE,
        'brake-fluid' => ServiceTypes::BRAKE_SERVICE,
        'coolant'     => ServiceTypes::OTHER,
    ];

    // ───────────────────────────── vehicle surface ─────────────────────────────

    /**
     * Everything the Installed Components tab needs for one vehicle: what is fitted NOW, the full
     * lifecycle of everything ever fitted, and the roll-up figures across both.
     */
    public function vehicleConfiguration(Vehicle $vehicle): array
    {
        $rows = VehicleComponent::query()
            ->trusted()
            ->with(array_merge(self::PRESENT_RELATIONS, ['replacedBy.catalog']))
            // A retired row keeps vehicle_id (the car its story ended on), so one predicate covers
            // both the current fit and the history. A part TRANSFERRED away is deliberately not
            // here any more — it now lives on the other car, which is the physical truth.
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->get();

        $odometer = (int) ($vehicle->odometer ?? 0);

        $installed = $rows->where('status', VehicleComponent::STATUS_ACTIVE)
            ->map(fn (VehicleComponent $c) => $this->present($c, $odometer))
            ->values();

        $history = $rows->filter(fn (VehicleComponent $c) => $c->removed_at !== null)
            ->map(fn (VehicleComponent $c) => $this->present($c, $odometer))
            ->values();

        $consumables = $this->consumablesFor($vehicle, $odometer);

        return [
            'vehicle' => [
                'id'       => $vehicle->id,
                'plate_no' => $vehicle->plate_no,
                'model'    => $vehicle->model,
                'odometer' => $odometer,
            ],
            'installed'   => $installed->all(),
            'consumables' => $consumables,
            'history'     => $history->all(),
            'summary'     => $this->vehicleSummary($installed, $history),
            'data_origin' => 'Derived from the maintenance workflow: parts installed on a ticket (vehicle_components) '
                . 'and routine services performed at close (service_records). Nothing on this tab is entered by hand.',
        ];
    }

    /**
     * One component's full dossier — the drill-down behind a row. Carries the provenance chain
     * (ticket → purchase order → invoice → supplier), the biography, and both neighbours in the
     * replacement chain so a user can walk a slot's whole history in either direction.
     */
    public function dossier(VehicleComponent $component): array
    {
        $component->loadMissing([
            'catalog', 'vehicle', 'supplier', 'installer', 'installedBy', 'removedBy',
            'sourcePurchase.request', 'sourceLineItem', 'removalTicket',
            'replacedBy.catalog', 'replaces.catalog',
            'events.actor', 'media', 'serviceRecords.workshop',
        ]);

        $odometer = (int) ($component->vehicle?->odometer ?? 0);

        return [
            'component' => $this->present($component, $odometer),
            'links'     => $this->provenanceLinks($component),
            'events'    => $component->events->sortBy('at')->values()->map(fn ($e) => [
                'id'                  => $e->id,
                'event'               => $e->event,
                'at'                  => optional($e->at)->toIso8601String(),
                'odometer'            => $e->odometer,
                'from_vehicle_id'     => $e->from_vehicle_id,
                'to_vehicle_id'       => $e->to_vehicle_id,
                'maintenance_id'      => $e->maintenance_id,
                'maintenance_task_id' => $e->maintenance_task_id,
                'actor_name'          => $e->actor_name,
                'note'                => $e->note,
                'meta'                => $e->meta,
            ])->all(),
            // The replacement chain, stated from this part's point of view.
            'replaced_by' => $component->replacedBy ? $this->chainNode($component->replacedBy) : null,
            'replaces'    => $component->replaces ? $this->chainNode($component->replaces) : null,
            'inspections' => $component->serviceRecords->map(fn (ServiceRecord $r) => [
                'id'            => $r->id,
                'service_type'  => $r->service_type,
                'description'   => $r->description,
                'performed_at'  => optional($r->performed_at)->toDateString(),
                'odometer'      => $r->odometer,
                'workshop'      => $r->workshop?->name,
                'result'        => $r->result,
            ])->all(),
            'media' => $component->media->map(fn ($m) => [
                'id'            => $m->id,
                'kind'          => $m->kind,
                'disk'          => $m->disk,
                's3_key'        => $m->s3_key,
                'content_type'  => $m->content_type,
                'original_name' => $m->original_name,
                'note'          => $m->note,
            ])->all(),
        ];
    }

    // ───────────────────────────── fleet surface ─────────────────────────────

    /**
     * The fleet dashboard cards. Each card is a count PLUS the rows behind it, so every headline
     * number can be opened and audited — a number the user cannot drill into is a black box
     * (standing traceability rule).
     *
     * PERFORMANCE CONTRACT: counting and filtering happen in SQL; only the `$limit` rows a card
     * actually shows are hydrated and passed through present(). The obvious implementation — load
     * every active component and bucket it in PHP — is O(fleet) in both queries and memory and was
     * measured at ~5s / 4,957 queries on 2.5k components, so it would fall over well before this
     * layer covers the real fleet. Do not "simplify" this back into a Collection pipeline.
     *
     * @param int $limit rows returned per card (the count is always the true total)
     */
    public function fleetSummary(int $limit = 25): array
    {
        return [
            'totals'              => $this->fleetTotals(),
            'warranty_expiring'   => $this->warrantyExpiring($limit),
            'past_expected_life'  => $this->pastExpectedLife($limit),
            'recently_replaced'   => $this->recentlyReplaced($limit),
            'frequently_replaced' => $this->frequentlyReplaced($limit),
            'data_origin' => 'Live aggregate over vehicle_components (workflow-written). Counts are exact; '
                . 'age, life-used and warranty status are derived at read time from install date + odometer.',
        ];
    }

    /** Headline roll-ups — one aggregate query plus one warranty count, no row hydration. */
    private function fleetTotals(): array
    {
        $agg = $this->activeQuery()
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('COUNT(DISTINCT vehicle_id) as vehicles')
            ->selectRaw('COALESCE(SUM(purchase_cost), 0) as value')
            ->selectRaw('AVG(' . $this->daysBetween('installed_at', $this->nowExpr()) . ') as avg_age')
            ->first();

        $covered = $this->activeQuery()
            ->whereNotNull('warranty_until')
            ->whereDate('warranty_until', '>=', Carbon::now()->toDateString())
            ->count();

        return [
            'installed_components'  => (int) $agg->n,
            'vehicles_covered'      => (int) $agg->vehicles,
            'total_installed_value' => round((float) $agg->value, 2),
            'currency'              => 'AED',
            // Averaged in SQL over calendar-day boundaries (DATEDIFF), where the per-row age shown on
            // a component's own card counts whole 24h periods (Carbon). On a large fleet the two can
            // land a day apart after rounding. Left alone deliberately: this is a headline "how old
            // is our fleet's hardware" figure, and pulling every row into PHP to agree on one day
            // would cost the scan this method exists to avoid.
            'average_age_days'      => $agg->avg_age === null ? null : (int) round($agg->avg_age),
            // Anything still inside its warranty — the expiring-soon rows are a subset of this.
            'warranty_covered'      => $covered,
        ];
    }

    /** Active parts whose warranty ends inside the window — an indexed range scan on warranty_until. */
    private function warrantyExpiring(int $limit): array
    {
        $from = Carbon::now()->toDateString();
        $to   = Carbon::now()->addDays(self::WARRANTY_WINDOW_DAYS)->toDateString();

        $scope = fn () => $this->activeQuery()
            ->whereNotNull('warranty_until')
            ->whereDate('warranty_until', '>=', $from)
            ->whereDate('warranty_until', '<=', $to);

        $rows = $scope()
            ->with(self::PRESENT_RELATIONS)
            ->orderBy('warranty_until')
            ->limit($limit)
            ->get();

        return [
            'window_days' => self::WARRANTY_WINDOW_DAYS,
            'count'       => $scope()->count(),
            'rows'        => $this->presentAll($rows),
        ];
    }

    /**
     * Active parts past the catalog's expected life. Both clocks are evaluated IN SQL against the
     * vehicle's current odometer and the install date, mirroring {@see ComponentLifecycle::serviceLife}
     * — the harsher clock decides, and the ordering puts the worst offender first.
     */
    private function pastExpectedLife(int $limit): array
    {
        $ageDays = $this->daysBetween('vehicle_components.installed_at', $this->nowExpr());

        $distance = $this->distanceDriven();

        $byKm = '(cc.expected_life_km IS NOT NULL AND vehicle_components.installed_odometer IS NOT NULL'
            . " AND {$distance} >= cc.expected_life_km)";
        $byAge = "(cc.expected_life_months IS NOT NULL AND {$ageDays} >= cc.expected_life_months * 30.44)";

        // Ratio of life consumed on each clock; NULLIF guards a zero/absent expectation, COALESCE
        // makes an inapplicable clock lose rather than poison the comparison.
        $ratio = $this->greatest(
            "COALESCE({$distance} / NULLIF(cc.expected_life_km, 0), 0)",
            "COALESCE({$ageDays} / NULLIF(cc.expected_life_months * 30.44, 0), 0)"
        );

        $scope = fn () => $this->activeQuery()
            ->join('component_catalog as cc', 'cc.id', '=', 'vehicle_components.component_catalog_id')
            ->join('vehicles as v', 'v.id', '=', 'vehicle_components.vehicle_id')
            ->whereRaw("({$byKm} OR {$byAge})");

        $rows = $scope()
            ->select('vehicle_components.*')
            ->with(self::PRESENT_RELATIONS)
            ->orderByRaw("{$ratio} DESC")
            ->limit($limit)
            ->get();

        return [
            'count' => $scope()->count('vehicle_components.id'),
            'rows'  => $this->presentAll($rows),
        ];
    }

    /**
     * Active, trusted components ON A LIVE VEHICLE — the base every "current fleet" card narrows.
     *
     * Columns are qualified so the joined variants above cannot hit an ambiguous `status` (vehicles
     * has one too). The soft-delete check matters: `vehicles.deleted_at` only trips the FK's
     * SET NULL on a HARD delete, so a soft-deleted (sold / written-off) car keeps its components
     * pointing at it. Without this they would keep inflating total installed value and keep
     * appearing on warranty and service-life cards with a blank plate — a fleet report describing
     * cars that are no longer in the fleet.
     *
     * The HISTORICAL cards (recently / frequently replaced) deliberately do NOT apply this: they
     * measure what the fleet has consumed over time, and a part that wore out on a car we have
     * since sold still consumed its life. Filtering those would understate real churn.
     */
    private function activeQuery()
    {
        return VehicleComponent::query()
            ->where('vehicle_components.validation_status', '!=', VehicleComponent::VALIDATION_QUARANTINED)
            ->where('vehicle_components.status', VehicleComponent::STATUS_ACTIVE)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('vehicles')
                ->whereColumn('vehicles.id', 'vehicle_components.vehicle_id')
                ->whereNull('vehicles.deleted_at'));
    }

    /** @param \Illuminate\Support\Collection<int,VehicleComponent> $rows */
    private function presentAll($rows): array
    {
        return $rows->map(fn (VehicleComponent $c) => $this->present($c, (int) ($c->vehicle?->odometer ?? 0)))
            ->values()->all();
    }

    /** Parts removed with a named successor in the last 90 days — the fleet's recent replacement flow. */
    private function recentlyReplaced(int $limit): array
    {
        $since = Carbon::now()->subDays(90);

        $rows = VehicleComponent::query()
            ->trusted()
            ->with(['catalog', 'vehicle:id,plate_no,model', 'replacedBy.catalog'])
            ->whereNotNull('removed_at')
            ->where('removed_at', '>=', $since)
            ->orderByDesc('removed_at')
            ->limit($limit)
            ->get();

        $total = VehicleComponent::query()->trusted()
            ->whereNotNull('removed_at')->where('removed_at', '>=', $since)->count();

        return [
            'window_days' => 90,
            'count'       => $total,
            'rows'        => $rows->map(fn (VehicleComponent $c) => [
                'id'             => $c->id,
                'vehicle_id'     => $c->vehicle_id,
                'plate_no'       => $c->vehicle?->plate_no,
                'category'       => $c->catalog?->category_key,
                'type'           => $c->catalog?->name,
                'label'          => $c->label,
                'removed_at'     => optional($c->removed_at)->toIso8601String(),
                'removal_reason' => $c->removal_reason,
                'disposition'    => $c->disposition,
                'life_km'        => $c->life_km,
                'life_days'      => $c->life_days,
                'replaced_by'    => $c->replacedBy ? $this->chainNode($c->replacedBy) : null,
            ])->all(),
        ];
    }

    /**
     * Which component TYPES churn most — ranked by how many removals they account for, with the
     * average life achieved. This is the buying signal: a type replaced often AND early is either a
     * bad part or a bad supplier.
     */
    private function frequentlyReplaced(int $limit): array
    {
        $rows = VehicleComponent::query()
            ->trusted()
            ->whereNotNull('removed_at')
            ->select('component_catalog_id')
            ->selectRaw('COUNT(*) as replacements')
            ->selectRaw('COUNT(DISTINCT vehicle_id) as vehicles')
            ->selectRaw('AVG(CASE WHEN removed_odometer IS NOT NULL AND installed_odometer IS NOT NULL '
                . 'AND removed_odometer >= installed_odometer THEN removed_odometer - installed_odometer END) as avg_life_km')
            ->selectRaw('AVG(' . $this->dateDiffExpression() . ') as avg_life_days')
            ->selectRaw('SUM(purchase_cost) as spend')
            ->groupBy('component_catalog_id')
            ->orderByDesc('replacements')
            ->limit($limit)
            ->get();

        $catalogs = ComponentCatalog::whereIn('id', $rows->pluck('component_catalog_id'))->get()->keyBy('id');

        return [
            'rows' => $rows->map(function ($r) use ($catalogs) {
                $catalog = $catalogs[$r->component_catalog_id] ?? null;

                return [
                    'component_catalog_id' => $r->component_catalog_id,
                    'type'                 => $catalog?->name,
                    'slug'                 => $catalog?->slug,
                    'category'             => $catalog?->category_key,
                    'replacements'         => (int) $r->replacements,
                    'vehicles'             => (int) $r->vehicles,
                    'avg_life_km'          => $r->avg_life_km === null ? null : (int) round($r->avg_life_km),
                    'avg_life_days'        => $r->avg_life_days === null ? null : (int) round($r->avg_life_days),
                    'expected_life_km'     => $catalog?->expected_life_km,
                    'total_spend'          => $r->spend === null ? null : round((float) $r->spend, 2),
                ];
            })->all(),
        ];
    }

    /**
     * Portable "days between install and removal". MySQL/MariaDB both have DATEDIFF; SQLite (the
     * test database) has neither, so it gets julianday arithmetic. Keeping this in one place stops
     * the aggregate from silently returning NULL on one driver and a number on the other.
     */
    private function dateDiffExpression(): string
    {
        return $this->daysBetween('installed_at', 'removed_at');
    }

    /** Whole days from `$from` to `$to`, in the dialect of the active driver. */
    private function daysBetween(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(julianday({$to}) - julianday({$from}))"
            : "DATEDIFF({$to}, {$from})";
    }

    /** The SQL literal for "now" — SQLite's julianday() wants the string 'now', MySQL wants NOW(). */
    private function nowExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "'now'" : 'NOW()';
    }

    /**
     * Kilometres driven since a component was fitted: the vehicle's reading now minus its reading
     * then.
     *
     * BOTH columns are UNSIGNED. MySQL evaluates unsigned − unsigned as unsigned, so the moment an
     * install reading sits above the vehicle's current odometer — an odometer correction, a swapped
     * cluster, a mis-keyed reading — the subtraction underflows and raises
     * "BIGINT UNSIGNED value is out of range", taking the whole fleet dashboard down rather than
     * showing one odd row. Casting to signed makes such a component simply report negative distance,
     * which then loses every comparison and quietly falls out of the results, matching how the PHP
     * path already refuses to trust an end reading below the start one.
     */
    private function distanceDriven(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? '(v.odometer - vehicle_components.installed_odometer)'
            : '(CAST(v.odometer AS SIGNED) - CAST(vehicle_components.installed_odometer AS SIGNED))';
    }

    /** GREATEST() is MySQL/MariaDB; SQLite spells the scalar form MAX(). */
    private function greatest(string $a, string $b): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "MAX({$a}, {$b})" : "GREATEST({$a}, {$b})";
    }

    // ───────────────────────────── presentation ─────────────────────────────

    /**
     * One component as the UI consumes it. `$currentOdometer` is the vehicle's reading NOW — used to
     * age an ACTIVE part; a removed part is aged against its own removal reading instead, so history
     * never moves when the car keeps driving.
     */
    private function present(VehicleComponent $c, int $currentOdometer): array
    {
        $catalog  = $c->catalog;
        $isActive = $c->status === VehicleComponent::STATUS_ACTIVE;

        $endDate     = $c->removed_at ?: Carbon::now();
        $ageDays     = $c->installed_at ? (int) $c->installed_at->diffInDays($endDate) : null;
        $endOdometer = $c->removed_odometer ?? ($isActive ? $currentOdometer : null);
        $distanceKm  = ($c->installed_odometer !== null && $endOdometer !== null && $endOdometer >= $c->installed_odometer)
            ? $endOdometer - $c->installed_odometer
            : null;

        return [
            'id'          => $c->id,
            'kind'        => 'component',
            'vehicle_id'  => $c->vehicle_id,
            'plate_no'    => $c->vehicle?->plate_no,

            // Identity
            'category'      => $catalog?->category_key,
            'type'          => $catalog?->name,
            'catalog_slug'  => $catalog?->slug,
            'tracking_mode' => $catalog?->tracking_mode,
            'part_name'     => $c->label ?: $catalog?->name,
            'brand'         => $c->brand,
            'model'         => $c->model,
            'part_number'   => $c->part_number,
            'serial_no'     => $c->serial_no,
            'position'      => $c->position,
            'quantity'      => $c->quantity === null ? null : (float) $c->quantity,

            // Commercial FACTS (copied from the purchase, never recomputed)
            'supplier'      => $c->supplier ? ['id' => $c->supplier->id, 'name' => $c->supplier->name] : null,
            'installer'     => $c->installer ? ['id' => $c->installer->id, 'name' => $c->installer->name] : null,
            'purchase_cost' => $c->purchase_cost === null ? null : (float) $c->purchase_cost,
            'currency'      => $c->currency,

            // Install leg
            'installed_at'       => optional($c->installed_at)->toIso8601String(),
            'installed_odometer' => $c->installed_odometer,
            'installed_by_name'  => $c->installed_by_name,
            'technician_name'    => $c->technician_name,

            // Where it is now
            'status'   => $c->status,
            'location' => $c->location,

            // Removal leg (null while active)
            'removed_at'               => optional($c->removed_at)->toIso8601String(),
            'removed_odometer'         => $c->removed_odometer,
            'removed_by_name'          => $c->removed_by_name,
            'removal_reason'           => $c->removal_reason,
            'removal_note'             => $c->removal_note,
            'disposition'              => $c->disposition,
            'replaced_by_component_id' => $c->replaced_by_component_id,

            // DERIVED — estimates, labelled so the UI can mark them
            'age_days'     => $ageDays,
            'distance_km'  => $distanceKm,
            'warranty'     => $this->warranty($c),
            'service_life' => $this->serviceLife($catalog, $ageDays, $distanceKm),
            'cost_per_km'  => ComponentLifecycle::costPerKm(
                $c->purchase_cost === null ? null : (float) $c->purchase_cost,
                $distanceKm
            ),

            // Provenance
            'maintenance_id'    => $c->sourcePurchase?->maintenance_id ?? $c->removal_maintenance_id,
            'part_purchase_id'  => $c->source_part_purchase_id,
            'line_item_id'      => $c->source_line_item_id,
            'source'            => $c->source,
            'validation_status' => $c->validation_status,
        ];
    }

    /** Warranty standing: none | active | expiring_soon | expired, with the days that decide it. */
    private function warranty(VehicleComponent $c): array
    {
        return ComponentLifecycle::warranty($c->warranty_until, $c->warranty_months);
    }

    /** How much of the catalog's expected life this part has used — see {@see ComponentLifecycle}. */
    private function serviceLife(?ComponentCatalog $catalog, ?int $ageDays, ?int $distanceKm): array
    {
        return ComponentLifecycle::serviceLife(
            $catalog?->expected_life_km,
            $catalog?->expected_life_months,
            $ageDays,
            $distanceKm
        );
    }

    /** A compact reference to another component in a replacement chain. */
    private function chainNode(VehicleComponent $c): array
    {
        return [
            'id'           => $c->id,
            'type'         => $c->catalog?->name,
            'part_name'    => $c->label ?: $c->catalog?->name,
            'brand'        => $c->brand,
            'installed_at' => optional($c->installed_at)->toIso8601String(),
            'removed_at'   => optional($c->removed_at)->toIso8601String(),
            'status'       => $c->status,
        ];
    }

    /**
     * The navigable provenance chain behind one component: which ticket fitted it, which purchase
     * order bought it, which invoice it was billed on, who supplied it. Every id here is a live
     * deep-link target in the UI.
     */
    private function provenanceLinks(VehicleComponent $c): array
    {
        $purchase = $c->sourcePurchase;
        $line     = $c->sourceLineItem;

        return [
            'maintenance_id'         => $purchase?->maintenance_id ?? $line?->maintenance_id,
            'maintenance_task_id'    => $purchase?->maintenance_task_id ?? $line?->maintenance_task_id,
            'part_request_id'        => $purchase?->part_request_id,
            'part_purchase_id'       => $purchase?->id,
            'purchase_order_no'      => $purchase?->po_number,
            'purchased_at'           => optional($purchase?->purchased_at)->toIso8601String(),
            'maintenance_line_item_id' => $line?->id,
            'maintenance_invoice_id' => $line?->maintenance_invoice_id,
            'supplier_vendor_id'     => $c->supplier_vendor_id,
            'installer_vendor_id'    => $c->installer_vendor_id,
            'removal_maintenance_id' => $c->removal_maintenance_id,
        ];
    }

    // ───────────────────────────── consumables bridge ─────────────────────────────

    /**
     * The consumable half of the configuration: for each consumable catalog type with an honest
     * service_type mapping, the latest service_record that refreshed it. Shaped like a component row
     * (same keys the table renders) but tagged `kind: consumable` and carrying no id — there is no
     * asset to drill into, only the service that was performed.
     */
    private function consumablesFor(Vehicle $vehicle, int $odometer): array
    {
        $catalogs = ComponentCatalog::active()
            ->where('tracking_mode', ComponentCatalog::TRACKING_CONSUMABLE)
            ->whereIn('slug', array_keys(self::CONSUMABLE_SERVICE_TYPE))
            ->get();

        if ($catalogs->isEmpty()) {
            return [];
        }

        $lastPerType = ServiceRecord::lastPerType($vehicle->id);

        return $catalogs->map(function (ComponentCatalog $catalog) use ($lastPerType, $odometer) {
            $record = $lastPerType[self::CONSUMABLE_SERVICE_TYPE[$catalog->slug]] ?? null;
            if (! $record) {
                return null;
            }

            $ageDays    = $record->performed_at ? (int) $record->performed_at->diffInDays(Carbon::now()) : null;
            $distanceKm = ($record->odometer !== null && $odometer >= $record->odometer)
                ? $odometer - $record->odometer
                : null;

            return [
                'id'                 => null,
                'kind'               => 'consumable',
                'service_record_id'  => $record->id,
                'vehicle_id'         => $record->vehicle_id,
                'category'           => $catalog->category_key,
                'type'               => $catalog->name,
                'catalog_slug'       => $catalog->slug,
                'tracking_mode'      => $catalog->tracking_mode,
                'part_name'          => $catalog->name,
                'brand'              => null,
                'part_number'        => null,
                'serial_no'          => null,
                'position'           => null,
                'supplier'           => null,
                'installer'          => $record->workshop_vendor_id ? ['id' => $record->workshop_vendor_id, 'name' => $record->workshop?->name] : null,
                'purchase_cost'      => $record->materials_cost === null ? null : (float) $record->materials_cost,
                'currency'           => 'AED',
                'installed_at'       => optional($record->performed_at)->toIso8601String(),
                'installed_odometer' => $record->odometer,
                'installed_by_name'  => $record->performed_by_name,
                'technician_name'    => $record->technician_name,
                'status'             => VehicleComponent::STATUS_ACTIVE,
                'location'           => VehicleComponent::LOC_ON_VEHICLE,
                'removed_at'         => null,
                'age_days'           => $ageDays,
                'distance_km'        => $distanceKm,
                // A consumable carries no supplier warranty in our data — saying "none" is the fact.
                'warranty'           => ['status' => 'none', 'until' => null, 'days_remaining' => null, 'months' => null],
                'service_life'       => $this->serviceLife($catalog, $ageDays, $distanceKm),
                'cost_per_km'        => null,
                'maintenance_id'     => $record->maintenance_id,
                'source'             => 'service_record',
            ];
        })->filter()->values()->all();
    }

    // ───────────────────────────── roll-ups ─────────────────────────────

    /** @param Collection<int,array> $installed @param Collection<int,array> $history */
    private function vehicleSummary(Collection $installed, Collection $history): array
    {
        $ages = $installed->pluck('age_days')->filter(fn ($d) => $d !== null);

        return [
            'installed_count'       => $installed->count(),
            'replaced_count'        => $history->count(),
            'total_installed_value' => round($installed->sum(fn ($r) => (float) ($r['purchase_cost'] ?? 0)), 2),
            'lifetime_component_spend' => round(
                $installed->sum(fn ($r) => (float) ($r['purchase_cost'] ?? 0))
                + $history->sum(fn ($r) => (float) ($r['purchase_cost'] ?? 0)),
                2
            ),
            'average_age_days'      => $ages->isEmpty() ? null : (int) round($ages->avg()),
            'under_warranty'        => $installed->where('warranty.status', 'active')->count(),
            'warranty_expiring'     => $installed->where('warranty.status', 'expiring_soon')->count(),
            'past_expected_life'    => $installed->where('service_life.status', 'overdue')->count(),
            'due_soon'              => $installed->where('service_life.status', 'due_soon')->count(),
        ];
    }
}
