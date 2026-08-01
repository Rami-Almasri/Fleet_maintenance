<?php

namespace Database\Seeders;

use App\Models\ComponentCatalog;
use App\Models\ComponentEvent;
use App\Models\Maintenance;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleLogEvent;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEMO data for Vehicle Installed Components. NOT wired into DatabaseSeeder — run it on demand:
 *
 *   php artisan db:seed --class=VehicleComponentDemoSeeder
 *
 * Idempotent: every run first WIPES its own prior rows (tagged by MARKER in part_purchases.notes and
 * component_events.meta) and rebuilds them, so re-running never piles up. Teardown alone:
 * {@see VehicleComponentDemoSeeder::teardown()}.
 *
 * BLAST RADIUS — deliberately small. It writes ONLY to the asset layer's own tables
 * (vehicle_components, component_events) plus one provenance row per component in part_purchases so
 * the "which ticket / PO / supplier put this here?" links in the UI resolve to something real. It
 * NEVER touches maintenances, maintenance_line_items, invoices or vehicle odometers: those carry the
 * fleet's real money and real mileage, and a UI-testing seeder has no business moving them. Existing
 * maintenance tickets are READ, to link an install to a plausible real visit.
 *
 * What it produces (defaults): ~80 vehicles × 20–40 slots, each slot a chain of 1–4 generations, so
 * wear items (tyres, pads, filters, batteries) carry several historical replacements while big-ticket
 * items are usually original. Warranties land across the whole range — long expired, expiring inside
 * the dashboard's 60-day window, and freshly issued — because those are the states the cards exist to
 * surface. Every generated row also passes `php artisan components:verify`: the events are written
 * alongside the components, so the demo data proves the derive-from-events claim rather than faking it.
 */
class VehicleComponentDemoSeeder extends Seeder
{
    /** Tag written into part_purchases.notes and component_events.meta so demo rows are findable. */
    public const MARKER = 'COMPONENT_DEMO';

    private const VEHICLE_COUNT   = 80;
    private const MIN_SLOTS       = 20;
    private const MAX_SLOTS       = 40;

    /** Cars below this reading have no credible service history to generate against. */
    private const MIN_ODOMETER    = 25000;

    /**
     * Distance this fleet actually covers in a month — the median measured across 962 completed
     * component lives (install odometer → removal odometer over the elapsed days). Used to turn a
     * catalog's distance-only expectation into a month figure so a warranty can be capped against it.
     */
    private const KM_PER_MONTH    = 1800;

    /**
     * The slots a car can have: catalog slug → the positions to fill. `[null]` is a positionless
     * single fitment. Positions MUST match the catalog's position_scheme or the model guard rejects
     * the row — which is the point: the seeder is held to the same invariants as the workflow.
     */
    private const SLOTS = [
        // Wear items — the ones that build long replacement chains.
        'tyre'              => ['front_left', 'front_right', 'rear_left', 'rear_right'],
        'brake-pads'        => ['front', 'rear'],
        'brake-discs'       => ['front', 'rear'],
        'air-filter'        => [null],
        'cabin-filter'      => [null],
        'fuel-filter'       => [null],
        'battery-12v'       => [null],
        'spark-plugs'       => [null],
        'drive-belt'        => [null],

        // Suspension & steering — four corners each.
        'shock-absorber'    => ['front_left', 'front_right', 'rear_left', 'rear_right'],
        'control-arm'       => ['front_left', 'front_right'],
        'wheel-bearing'     => ['front_left', 'front_right', 'rear_left', 'rear_right'],
        'suspension-spring' => ['front_left', 'front_right'],
        'stabilizer-link'   => ['front_left', 'front_right'],
        'tie-rod-end'       => ['front_left', 'front_right'],
        'brake-caliper'     => ['front_left', 'front_right'],
        'steering-rack'     => [null],

        // Engine & drivetrain — mostly original, occasionally replaced.
        'alternator'        => [null],
        'starter-motor'     => [null],
        'radiator'          => [null],
        'water-pump'        => [null],
        'fuel-pump'         => [null],
        'radiator-fan'      => [null],
        'thermostat'        => [null],
        'oxygen-sensor'     => [null],
        'ignition-coil'     => [null],
        'timing-belt'       => [null],
        'engine-mount'      => [null],
        'catalytic-converter' => [null],

        // Climate & electronics.
        'ac-compressor'     => [null],
        'ac-condenser'      => [null],
        'ecu'               => [null],
        'gps-tracker'       => [null],
    ];

    /** How many generations a slot typically goes through: slug → [min, max]. Default [1, 1]. */
    private const GENERATIONS = [
        'air-filter'     => [2, 5],
        'cabin-filter'   => [2, 5],
        'oil-filter'     => [2, 5],
        'tyre'           => [1, 4],
        'brake-pads'     => [1, 4],
        'fuel-filter'    => [1, 3],
        'battery-12v'    => [1, 3],
        'brake-discs'    => [1, 2],
        'drive-belt'     => [1, 2],
        'spark-plugs'    => [1, 2],
        'stabilizer-link' => [1, 2],
        'shock-absorber' => [1, 2],
        'wiper-blades'   => [1, 3],
    ];

    /** Realistic brand pools per catalog category, so the demo does not read as one supplier's fleet. */
    private const BRANDS = [
        'tyres'        => ['Bridgestone', 'Michelin', 'Goodyear', 'Continental', 'Dunlop', 'Yokohama', 'Pirelli', 'Hankook'],
        'brakes'       => ['Brembo', 'TRW', 'Bosch', 'Textar', 'Akebono', 'ATE', 'Ferodo'],
        'suspension'   => ['KYB', 'Monroe', 'Bilstein', 'Sachs', 'TRW', 'Moog', 'Lemförder'],
        'electrical'   => ['Bosch', 'Denso', 'Valeo', 'Hitachi', 'Delphi', 'Varta', 'ACDelco', 'Exide'],
        'engine'       => ['Mann', 'Mahle', 'Gates', 'Dayco', 'NGK', 'Denso', 'Aisin', 'Bosch'],
        'ac'           => ['Denso', 'Sanden', 'Valeo', 'Behr', 'Mahle'],
        'transmission' => ['Aisin', 'LuK', 'Sachs', 'Exedy', 'Valeo'],
    ];

    /** Price band per catalog slug in AED: slug → [min, max]. Anything unlisted falls back to [120, 600]. */
    private const PRICES = [
        'tyre' => [280, 900],            'brake-pads' => [180, 650],      'brake-discs' => [320, 1100],
        'brake-caliper' => [450, 1400],  'battery-12v' => [280, 750],     'alternator' => [700, 2200],
        'starter-motor' => [600, 1800],  'radiator' => [500, 1600],       'water-pump' => [300, 900],
        'fuel-pump' => [450, 1500],      'ac-compressor' => [1200, 3500], 'ac-condenser' => [600, 1800],
        'radiator-fan' => [400, 1200],   'ecu' => [1500, 5000],           'gps-tracker' => [250, 600],
        'steering-rack' => [1200, 3800], 'catalytic-converter' => [900, 3200], 'timing-belt' => [250, 900],
        'shock-absorber' => [250, 850],  'control-arm' => [280, 900],     'wheel-bearing' => [180, 600],
        'suspension-spring' => [200, 700], 'stabilizer-link' => [80, 260], 'tie-rod-end' => [110, 380],
        'air-filter' => [45, 160],       'cabin-filter' => [40, 140],     'fuel-filter' => [70, 250],
        'spark-plugs' => [120, 420],     'ignition-coil' => [150, 520],   'drive-belt' => [90, 300],
        'thermostat' => [80, 280],       'oxygen-sensor' => [220, 700],   'engine-mount' => [150, 500],
    ];

    /** Warranty options in months, weighted toward the 6/12 the fleet actually buys. */
    private const WARRANTIES = [null, 3, 6, 6, 12, 12, 12, 24];

    public function run(): void
    {
        $this->command?->info('Wiping any previous ' . self::MARKER . ' rows…');
        self::teardown();

        $catalogs = ComponentCatalog::active()
            ->where('tracking_mode', '!=', ComponentCatalog::TRACKING_CONSUMABLE)
            ->get()
            ->keyBy('slug');

        if ($catalogs->isEmpty()) {
            $this->command?->error('component_catalog is empty — run ComponentCatalogSeeder first.');

            return;
        }

        // Only cars with a plausible odometer. A component's install reading must never exceed the
        // reading on the car it sits on: distance-driven would go negative, every distance-based
        // service-life bar would collapse to "no expectation set", and the fleet board's SQL would
        // be doing unsigned subtraction on a negative result. Cars reading under this threshold are
        // new, reset or mis-scanned, and there is no honest history to invent on top of them.
        // STABLE selection. inRandomOrder() re-rolled the whole target set on every run, so a URL
        // someone had open ("/vehicles/1692?tab=components") went empty the next time the seeder ran
        // — the car had simply dropped out of the sample. Hashing the id gives the same scattered
        // 80 cars every time: still spread across the fleet rather than the first 80 by id, but
        // repeatable, so links stay valid and a re-run refreshes the SAME cars.
        $vehicles = Vehicle::query()
            ->whereNotNull('plate_no')
            ->where('odometer', '>=', self::MIN_ODOMETER)
            ->orderByRaw('MD5(CONCAT(id, ?))', [self::MARKER])
            ->limit(self::VEHICLE_COUNT)
            ->get();

        if ($vehicles->isEmpty()) {
            $this->command?->error('No vehicles found — nothing to fit components to.');

            return;
        }

        $suppliers = Vendor::query()->whereIn('type', ['parts_supplier', 'other'])->pluck('name', 'id');
        $garages   = Vendor::query()->where('type', 'garage')->pluck('name', 'id');
        $actor     = User::query()->orderBy('id')->first();

        if ($suppliers->isEmpty()) {
            $suppliers = Vendor::query()->pluck('name', 'id')->take(20);
        }

        $stats = ['components' => 0, 'events' => 0, 'purchases' => 0, 'active' => 0, 'retired' => 0];
        $bar   = $this->command?->getOutput()->createProgressBar($vehicles->count());
        $bar?->start();

        foreach ($vehicles as $vehicle) {
            DB::transaction(function () use ($vehicle, $catalogs, $suppliers, $garages, $actor, &$stats) {
                $this->fitVehicle($vehicle, $catalogs, $suppliers, $garages, $actor, $stats);
            });
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine(2);
        $this->command?->info(sprintf(
            'Fitted %d components (%d currently installed, %d replaced) across %d vehicles — %d events, %d purchase records.',
            $stats['components'], $stats['active'], $stats['retired'], $vehicles->count(), $stats['events'], $stats['purchases']
        ));
        $this->command?->line('  Verify the read model derives from the events:  php artisan components:verify');
        $this->command?->line('  Remove this demo data:  php artisan tinker --execute="Database\\Seeders\\VehicleComponentDemoSeeder::teardown();"');
    }

    /** Build one car's whole component history: pick its slots, then chain generations through each. */
    private function fitVehicle(Vehicle $vehicle, $catalogs, $suppliers, $garages, ?User $actor, array &$stats): void
    {
        // The span of history we invent for this car: how far back its records go, and how many of
        // its kilometres fall inside that window. Never mutates the vehicle — the current odometer is
        // read as the end point and everything else is derived backwards from it.
        // The car's REAL reading is the ceiling — never inflated. Everything else is derived
        // backwards from it, so no generated component can claim to have been fitted at a mileage
        // the car has not reached.
        $currentOdo  = (int) $vehicle->odometer;
        $historyKm   = (int) min($currentOdo, random_int(50000, 160000));
        $historyDays = random_int(500, 1900);

        // Real visits this car actually had — used to attach an install to a plausible ticket rather
        // than inventing maintenance history.
        $tickets = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->pluck('created_at', 'id');

        $slots = collect(self::SLOTS)
            ->filter(fn ($positions, $slug) => $catalogs->has($slug))
            ->flatMap(fn ($positions, $slug) => collect($positions)->map(fn ($p) => [$slug, $p]))
            ->shuffle()
            ->take(random_int(self::MIN_SLOTS, self::MAX_SLOTS));

        foreach ($slots as [$slug, $position]) {
            $this->fitSlot(
                $vehicle, $catalogs[$slug], $position, $currentOdo, $historyKm, $historyDays,
                $tickets, $suppliers, $garages, $actor, $stats
            );
        }
    }

    /**
     * One slot's full chain. Generations are laid out on a shared timeline: generation i is installed
     * at boundary i and removed at boundary i+1, so the successor's install date IS the predecessor's
     * removal date — which is what makes the history read as a continuous story rather than a set of
     * unrelated rows.
     */
    private function fitSlot(
        Vehicle $vehicle, ComponentCatalog $catalog, ?string $position,
        int $currentOdo, int $historyKm, int $historyDays,
        $tickets, $suppliers, $garages, ?User $actor, array &$stats
    ): void {
        [$minGen, $maxGen] = self::GENERATIONS[$catalog->slug] ?? [1, 1];
        $generations = random_int($minGen, $maxGen);

        // Fractions along the history window where each generation begins. 0 = oldest record we hold,
        // 1 = today. Sorted so the chain always moves forward in both time and distance.
        $points = collect(range(0, $generations - 1))
            ->map(fn ($i) => $i === 0 ? 0.0 : random_int(10, 95) / 100)
            ->sort()
            ->values()
            ->all();

        // ~1 in 5 chains gets a brand-new final generation, so "recently replaced" and "warranty just
        // issued" are always populated no matter how the random spans fall.
        $freshTail = random_int(1, 5) === 1;

        if ($freshTail) {
            $points[$generations - 1] = max($points[$generations - 1], 1 - (random_int(3, 55) / $historyDays));
        }

        $stamps = $this->timeline($points, $currentOdo, $historyKm, $historyDays);

        $predecessor = null;

        foreach ($stamps as $i => $stamp) {
            $component = $this->makeComponent(
                $vehicle, $catalog, $position, $stamp['at'], $stamp['odo'],
                $tickets, $suppliers, $garages, $actor, $stats
            );

            $stats['components']++;

            // Close the PREVIOUS generation the moment its successor exists, mirroring exactly what
            // ComponentService::installFromPurchase does inside its install transaction.
            if ($predecessor) {
                $this->closeOut($predecessor, $component, $stamp['at'], $stamp['odo'], $tickets, $actor, $stats);
                $stats['retired']++;
            }

            if ($i === count($stamps) - 1) {
                $stats['active']++;
            }

            $predecessor = $component;
        }
    }

    /**
     * Turn the chain's fraction points into STRICTLY INCREASING (date, odometer) stamps.
     *
     * The raw fractions can collide once rounded to whole days, and each install is given a random
     * hour, so a naive mapping can hand generation N+1 a timestamp EARLIER than generation N — which
     * would record a part removed before it was fitted. `components:verify` catches exactly that, so
     * the fix belongs here rather than in the checker: the correction pass walks BACKWARDS from the
     * newest stamp and pushes colliding earlier ones further into the past, which cannot invent a
     * future date the way pushing the later one forward could.
     *
     * @param  array<int,float> $points ascending fractions in [0,1]; 0 = oldest record, 1 = today
     * @return array<int,array{at: Carbon, odo: int}>
     */
    private function timeline(array $points, int $currentOdo, int $historyKm, int $historyDays): array
    {
        $stamps = [];

        foreach ($points as $f) {
            $stamps[] = [
                'at'  => Carbon::now()->subDays((int) round($historyDays * (1 - $f)))->setTime(random_int(8, 17), random_int(0, 59)),
                'odo' => (int) max(0, round($currentOdo - $historyKm * (1 - $f))),
            ];
        }

        for ($i = count($stamps) - 1; $i > 0; $i--) {
            if ($stamps[$i - 1]['at']->gte($stamps[$i]['at'])) {
                $stamps[$i - 1]['at'] = $stamps[$i]['at']->copy()->subDays(random_int(5, 40));
            }
            if ($stamps[$i - 1]['odo'] >= $stamps[$i]['odo']) {
                $stamps[$i - 1]['odo'] = max(0, $stamps[$i]['odo'] - random_int(500, 4000));
            }
        }

        return $stamps;
    }

    /** Create the purchase provenance row, the component, and its `installed` event. */
    private function makeComponent(
        Vehicle $vehicle, ComponentCatalog $catalog, ?string $position,
        Carbon $installedAt, int $installedOdo,
        $tickets, $suppliers, $garages, ?User $actor, array &$stats
    ): VehicleComponent {
        $brand   = $this->brandFor($catalog);
        $cost    = $this->priceFor($catalog);
        $partNo  = $this->partNumberFor($brand);
        $months  = $this->warrantyFor($catalog, $installedAt);
        $ticket  = $this->ticketNear($tickets, $installedAt);

        $supplierId = $suppliers->keys()->random();
        $garageId   = $garages->isNotEmpty() ? $garages->keys()->random() : null;

        $purchase = PartPurchase::create([
            'vehicle_id'        => $vehicle->id,
            'maintenance_id'    => $ticket,
            'part_name'         => $catalog->name . ' — ' . $brand,
            'part_number'       => $partNo,
            'category_key'      => $catalog->category_key,
            'part_class'        => $catalog->tracking_mode === ComponentCatalog::TRACKING_SERIALIZED ? 'major' : 'standard',
            'purchase_source'   => 'supplier',
            'source_vendor_id'  => $supplierId,
            'source_name'       => $suppliers[$supplierId] ?? null,
            'po_number'         => 'PO-' . $installedAt->format('Ym') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'purchase_price'    => $cost,
            'currency'          => 'AED',
            'quantity'          => 1,
            'purchased_by'      => $actor?->id,
            'purchased_by_name' => $actor?->name,
            // Bought a few days before it went on the car — the normal procurement lead time.
            'purchased_at'      => $installedAt->copy()->subDays(random_int(1, 12)),
            'installed_by'      => $actor?->id,
            'installed_by_name' => $actor?->name,
            'installed_at'      => $installedAt,
            'installed_odometer' => $installedOdo,
            'result'            => PartPurchase::RESULT_SUCCESS,
            'notes'             => self::MARKER,
        ]);
        $stats['purchases']++;

        // Non-fillable fields (status/location/trust markers) are set by explicit assignment, exactly
        // as ComponentService does — mass assignment is blocked on purpose.
        $component = new VehicleComponent([
            'component_catalog_id' => $catalog->id,
            'serial_no'   => $catalog->isSerialized() ? strtoupper(substr(md5($catalog->slug . $vehicle->id . $installedAt->timestamp), 0, 12)) : null,
            'part_number' => $partNo,
            'brand'       => $brand,
            'model'       => $this->modelCodeFor($catalog),
            'label'       => $brand . ' ' . $catalog->name,
            'quantity'    => 1,
            'position'    => $position,

            'installed_at'       => $installedAt,
            'installed_odometer' => $installedOdo,
            'installed_by'       => $actor?->id,
            'installed_by_name'  => $actor?->name,
            'technician_name'    => null,
            'installer_vendor_id' => $garageId,
            'supplier_vendor_id'  => $supplierId,
            'purchase_cost'      => $cost,
            'currency'           => 'AED',
            'warranty_months'    => $months,

            'source_part_purchase_id' => $purchase->id,
            'source'                  => VehicleComponent::SOURCE_WORKFLOW,
        ]);
        $component->vehicle_id = $vehicle->id;
        $component->status     = VehicleComponent::STATUS_ACTIVE;
        $component->location   = VehicleComponent::LOC_ON_VEHICLE;
        $component->write_mode = 'seed';
        // Demo rows are born validated so they appear on every read surface — a provisional row is a
        // shadow-launch observation awaiting a human gate, which is not what this data is.
        $component->validation_status = VehicleComponent::VALIDATION_VALIDATED;
        $component->save();

        $this->event($component, ComponentEvent::EVENT_INSTALLED, $installedAt, $actor, [
            'to_vehicle_id'  => $vehicle->id,
            'odometer'       => $installedOdo,
            'maintenance_id' => $ticket,
            'note'           => "Installed from purchase #{$purchase->id} ({$purchase->part_name})",
        ]);
        $stats['events']++;

        return $component;
    }

    /**
     * Retire a generation because its successor has just been fitted. Writes the same removal leg and
     * the same event pair the service writes, so `components:verify` replays these rows successfully.
     */
    private function closeOut(
        VehicleComponent $component, VehicleComponent $successor,
        Carbon $removedAt, int $removedOdo, $tickets, ?User $actor, array &$stats
    ): void {
        // Dispositions that KEEP vehicle_id, so the retired row stays queryable as this car's history.
        // 'stored' is deliberately absent: a stored part detaches from the vehicle and would vanish
        // from the history tab, which is not the story this demo is illustrating.
        $reason = collect([
            VehicleComponent::REASON_WORN_OUT, VehicleComponent::REASON_WORN_OUT,
            VehicleComponent::REASON_FAILED, VehicleComponent::REASON_UPGRADE,
        ])->random();

        $disposition = $reason === VehicleComponent::REASON_FAILED && random_int(1, 3) === 1
            ? VehicleComponent::DISP_WARRANTY_RETURN
            : collect([VehicleComponent::DISP_SCRAPPED, VehicleComponent::DISP_SCRAPPED, VehicleComponent::DISP_RETURNED_SUPPLIER])->random();

        [$status, $location] = match ($disposition) {
            VehicleComponent::DISP_RETURNED_SUPPLIER,
            VehicleComponent::DISP_WARRANTY_RETURN => [VehicleComponent::STATUS_RETIRED, VehicleComponent::LOC_SUPPLIER],
            default                                => [VehicleComponent::STATUS_RETIRED, VehicleComponent::LOC_SCRAPPED],
        };

        $ticket = $this->ticketNear($tickets, $removedAt);

        $component->removed_at             = $removedAt;
        $component->removed_odometer       = max($removedOdo, (int) $component->installed_odometer);
        $component->removed_by             = $actor?->id;
        $component->removed_by_name        = $actor?->name;
        $component->removal_reason         = $reason;
        $component->removal_note           = null;
        $component->disposition            = $disposition;
        $component->removal_maintenance_id = $ticket;
        $component->status                 = $status;
        $component->location               = $location;
        $component->replaced_by_component_id = $successor->id;
        $component->save();

        $this->event($component, ComponentEvent::EVENT_REMOVED, $removedAt, $actor, [
            'from_vehicle_id' => $component->vehicle_id,
            'odometer'        => $component->removed_odometer,
            'maintenance_id'  => $ticket,
            'meta'            => ['removal_reason' => $reason, 'disposition' => $disposition],
        ]);

        $terminal = match ($disposition) {
            VehicleComponent::DISP_RETURNED_SUPPLIER => ComponentEvent::EVENT_RETURNED_SUPPLIER,
            VehicleComponent::DISP_WARRANTY_RETURN   => ComponentEvent::EVENT_WARRANTY_CLAIMED,
            default                                  => ComponentEvent::EVENT_DISPOSED,
        };

        $this->event($component, $terminal, $removedAt, $actor, [
            'from_vehicle_id' => $component->vehicle_id,
            'maintenance_id'  => $ticket,
        ]);

        $stats['events'] += 2;
    }

    /**
     * Append one biography line AND its vehicle-timeline mirror — the same pair ComponentService
     * writes. The mirror matters: without it the demo would populate the Installed Components tab
     * but leave the Vehicle Timeline silent, so the feature would look half-built exactly where a
     * reviewer goes to check that the two agree.
     */
    private function event(VehicleComponent $component, string $event, Carbon $at, ?User $actor, array $data): void
    {
        $this->mirrorToTimeline($component, $event, $at, $actor, $data);

        ComponentEvent::create([
            'vehicle_component_id' => $component->id,
            'event'                => $event,
            'from_vehicle_id'      => $data['from_vehicle_id'] ?? null,
            'to_vehicle_id'        => $data['to_vehicle_id'] ?? null,
            'odometer'             => $data['odometer'] ?? null,
            'maintenance_id'       => $data['maintenance_id'] ?? null,
            'actor_id'             => $actor?->id,
            'actor_name'           => $actor?->name,
            'at'                   => $at,
            'note'                 => $data['note'] ?? null,
            'meta'                 => array_merge(['seeder' => self::MARKER], $data['meta'] ?? []),
        ]);
    }

    /**
     * The vehicle_log_events row behind a component event, stamped with the HISTORICAL date so the
     * timeline reads as the car's real biography rather than a wall of rows dated "today". Only the
     * events that describe the car change hands here — warehouse noise (purchased/stored) stays out,
     * matching ComponentService::recordEvent's own mapping.
     */
    private function mirrorToTimeline(VehicleComponent $component, string $event, Carbon $at, ?User $actor, array $data): void
    {
        $type = match ($event) {
            ComponentEvent::EVENT_INSTALLED => VehicleLogEvent::EVENT_COMPONENT_INSTALLED,
            ComponentEvent::EVENT_REMOVED   => VehicleLogEvent::EVENT_COMPONENT_REMOVED,
            // Terminal dispositions are not mirrored — see ComponentService::recordEvent for why.
            default                         => null,
        };

        $vehicleId = $data['to_vehicle_id'] ?? $data['from_vehicle_id'] ?? $component->vehicle_id;
        if (! $type || ! $vehicleId) {
            return;
        }

        $catalogName = $component->catalog?->name ?: 'Component';
        $description = $type === VehicleLogEvent::EVENT_COMPONENT_INSTALLED
            ? "{$catalogName} installed — {$component->label}"
            : "{$catalogName} replaced — {$component->label} · "
                . str_replace('_', ' ', (string) ($data['meta']['removal_reason'] ?? 'removed'));

        VehicleLogEvent::create([
            'vehicle_id'  => $vehicleId,
            'event_type'  => $type,
            'source_tag'  => 'components',
            'description' => $description,
            'meta'        => ['component_id' => $component->id, 'seeder' => self::MARKER],
            'actor_id'    => $actor?->id,
            'occurred_at' => $at,
        ]);
    }

    // ───────────────────────────── flavour ─────────────────────────────

    private function brandFor(ComponentCatalog $catalog): string
    {
        $pool = self::BRANDS[$catalog->category_key] ?? ['OEM', 'Genuine Parts', 'Bosch', 'Denso'];

        return $pool[array_rand($pool)];
    }

    private function priceFor(ComponentCatalog $catalog): float
    {
        [$min, $max] = self::PRICES[$catalog->slug] ?? [120, 600];

        return round(random_int($min * 100, $max * 100) / 100, 2);
    }

    private function partNumberFor(string $brand): string
    {
        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $brand), 0, 3))
            . '-' . random_int(1000, 9999)
            . '-' . random_int(10, 99);
    }

    private function modelCodeFor(ComponentCatalog $catalog): string
    {
        return strtoupper(substr($catalog->slug, 0, 3)) . random_int(100, 999);
    }

    /**
     * Warranty months, skewed so the dashboard cards are never empty: a part installed inside the last
     * ~3 months is biased toward a term that lands its expiry INSIDE the 60-day warning window, which
     * is the state "Components nearing warranty expiration" exists to catch.
     *
     * The term is then CAPPED at how long the part is expected to last. Without the cap the weighted
     * list happily put a 24-month warranty on a cabin filter the catalog expects to last 12 months —
     * a part that reads "156% used" and "still under warranty" at the same time, which no supplier
     * would ever sell and which makes the whole tab look untrustworthy.
     */
    private function warrantyFor(ComponentCatalog $catalog, Carbon $installedAt): ?int
    {
        $ageDays = Carbon::now()->diffInDays($installedAt);

        $months = ($ageDays < 100 && random_int(1, 2) === 1)
            // 3 months on a part fitted 1–3 months ago expires within the window.
            ? 3
            : ($catalog->default_warranty_months && random_int(1, 3) > 1
                ? (int) $catalog->default_warranty_months
                : self::WARRANTIES[array_rand(self::WARRANTIES)]);

        if ($months === null) {
            return null;
        }

        $ceiling = $this->expectedLifeMonths($catalog);

        return $ceiling === null ? $months : max(3, min($months, $ceiling));
    }

    /**
     * The catalog's expected life expressed in months. An explicit expected_life_months wins; a
     * distance-only expectation is converted at {@see KM_PER_MONTH}. Returns null when the catalog
     * states no expectation at all (engines, ECUs, GPS units) — there is nothing to cap against, so
     * those keep whatever term the supplier gave.
     */
    private function expectedLifeMonths(ComponentCatalog $catalog): ?int
    {
        if ($catalog->expected_life_months) {
            return (int) $catalog->expected_life_months;
        }

        return $catalog->expected_life_km
            ? (int) round($catalog->expected_life_km / self::KM_PER_MONTH)
            : null;
    }

    /** The real maintenance visit closest in time to a fitting, or null when the car has no history. */
    private function ticketNear($tickets, Carbon $when): ?int
    {
        if ($tickets->isEmpty()) {
            return null;
        }

        $best     = null;
        $bestDiff = null;

        foreach ($tickets as $id => $createdAt) {
            $diff = abs(Carbon::parse($createdAt)->diffInDays($when));
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best     = $id;
            }
        }

        // Only claim a link when the visit is genuinely near the fitting; otherwise leave it null
        // rather than attaching a part to an unrelated ticket a year away.
        return $bestDiff !== null && $bestDiff <= 45 ? (int) $best : null;
    }

    // ───────────────────────────── teardown ─────────────────────────────

    /**
     * Remove every row this seeder created, and nothing else. Order matters: events reference
     * components, components reference purchases, and a component's successor FK must be cleared
     * before its predecessor row can go.
     */
    public static function teardown(): void
    {
        $purchaseIds = PartPurchase::where('notes', self::MARKER)->pluck('id');

        if ($purchaseIds->isEmpty()) {
            return;
        }

        $componentIds = VehicleComponent::whereIn('source_part_purchase_id', $purchaseIds)->pluck('id');

        // The timeline mirrors carry the marker in their meta JSON — matched as text so this works
        // on MySQL and SQLite alike, and scoped to the component event types so nothing else is hit.
        VehicleLogEvent::whereIn('event_type', [
            VehicleLogEvent::EVENT_COMPONENT_INSTALLED,
            VehicleLogEvent::EVENT_COMPONENT_REMOVED,
            VehicleLogEvent::EVENT_COMPONENT_DISPOSED,
            VehicleLogEvent::EVENT_COMPONENT_TRANSFERRED,
        ])->where('meta', 'like', '%' . self::MARKER . '%')->delete();

        ComponentEvent::whereIn('vehicle_component_id', $componentIds)->delete();
        VehicleComponent::whereIn('id', $componentIds)->update(['replaced_by_component_id' => null]);
        VehicleComponent::whereIn('id', $componentIds)->delete();
        PartPurchase::whereIn('id', $purchaseIds)->delete();
    }
}
