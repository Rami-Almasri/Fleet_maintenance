<?php

namespace Tests\Feature;

use App\Models\ComponentCatalog;
use App\Models\PlateCode;
use App\Models\SpareKeyRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\SpareKeyInventoryImporter;
use App\Services\SpareKeyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The spare-key REGISTER import — the door that makes a car's profile show the keys it holds.
 *
 * The headline risk this file exists to pin down is the plate trap. PlateResolver matches on digits
 * alone, and the register carries `U76722` and `P76722` as two different cars, which they are. A
 * digits-only match would have put one car's keys on the other and the fleet's key count would have
 * been wrong in a way nobody could see from the screen.
 *
 * REQUIRES the `fleet_e2e_scratch` MySQL database — run with `-c phpunit.e2e.xml`.
 * @see SpareKeyLifecycleTest for the pattern and why there is no RefreshDatabase.
 */
class SpareKeyInventoryImportTest extends TestCase
{
    private const TOUCHED = [
        'vehicle_components', 'component_events', 'spare_key_requirements',
        'vehicle_log_events', 'vehicles', 'users', 'plate_codes',
    ];

    private User $actor;
    private ComponentCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'fleet_e2e_scratch') {
            $this->markTestSkipped('needs the fleet_e2e_scratch database — run with -c phpunit.e2e.xml');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TOUCHED as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        config()->set('features.asset_layer', 'shadow');

        $this->actor = User::create([
            'name' => 'Backfill Runner', 'email' => 'backfill.' . uniqid() . '@fleet.test',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->catalog = ComponentCatalog::firstOrCreate(
            ['slug' => SpareKeyRequirement::CATALOG_SLUG],
            [
                'name' => 'Spare Key', 'category_key' => 'interior',
                'tracking_mode' => ComponentCatalog::TRACKING_BATCH,
                'position_scheme' => ComponentCatalog::SCHEME_SET, 'is_active' => true,
            ]
        );
    }

    /** The register's own shape: 3 preamble rows, then B=model C=year D=plate E=qty F=remark. */
    private function sheet(array $dataRows): array
    {
        return array_merge([
            ['', '', 'SPARE KEYS:  136'],
            ['', '', 'Monday 31-08-2026'],
            ['', 'Model', 'Year', 'Number', 'Quantity', 'Remarks'],
        ], array_map(fn ($r) => array_merge([''], $r), $dataRows));
    }

    private function importer(): SpareKeyInventoryImporter
    {
        return app(SpareKeyInventoryImporter::class);
    }

    private function car(string $plateNo, ?string $plateCode, string $make, string $model): Vehicle
    {
        $v = Vehicle::create([
            'plate_no' => $plateNo,
            'make' => $make, 'model' => $model, 'status' => 'available', 'odometer' => 1000,
        ]);

        // `plate_code` is deliberately NOT mass-assignable on Vehicle — it comes from the
        // OfficeManager sync, not from a request body — so the fixture has to set it explicitly.
        // Passing it to create() drops it silently, which is what made these tests read as a
        // resolver bug the first time round.
        if ($plateCode !== null) {
            $v->forceFill(['plate_code' => $plateCode])->save();
        }

        return $v->fresh();
    }

    private function keysOn(Vehicle $v): int
    {
        return VehicleComponent::where('vehicle_id', $v->id)
            ->where('component_catalog_id', $this->catalog->id)
            ->where('status', VehicleComponent::STATUS_ACTIVE)
            ->count();
    }

    // ───────────────────────────── the plate trap ─────────────────────────────

    /**
     * THE HEADLINE. Two cars whose plates differ only by letter must each get their OWN key.
     * PlateResolver alone collapses these two, which is exactly the bug this importer must not have.
     */
    public function test_two_plates_with_the_same_digits_but_different_letters_are_different_cars(): void
    {
        PlateCode::create(['em_no' => '21', 'letter_en' => 'U']);
        PlateCode::create(['em_no' => '16', 'letter_en' => 'P']);

        $u = $this->car('76722', '21', 'NISSAN', 'PATROL XE');
        $p = $this->car('76722', '16', 'NISSAN', 'PATROL');

        $this->importer()->import($this->sheet([
            ['NISSAN PATROL XE', '2026', 'U76722', '1', ''],
            ['NISSAN PATROL',    '2025', 'P76722', '1', ''],
        ]), $this->actor, dryRun: false);

        $this->assertSame(1, $this->keysOn($u), 'U 76722 must hold exactly its own key');
        $this->assertSame(1, $this->keysOn($p), 'P 76722 must hold exactly its own key');
    }

    /** A letter the fleet disagrees with is REPORTED, never resolved to the nearest car. */
    public function test_a_conflicting_plate_letter_is_refused_rather_than_guessed(): void
    {
        PlateCode::create(['em_no' => '30', 'letter_en' => 'V']);
        $car = $this->car('76188', '30', 'JAGUAR', 'F PACE');

        $result = $this->importer()->import($this->sheet([
            ['JAGUAR F-PACE', '2021', 'G76188', '1', ''],
        ]), $this->actor, dryRun: false);

        $this->assertSame(0, $this->keysOn($car), 'a disputed plate must put a key on nobody');
        $this->assertSame('unmatched', $result['rows'][0]['outcome']);
        $this->assertStringContainsString('needs a human', $result['rows'][0]['detail']);
    }

    /**
     * The one accepted mismatch: OUR record has no plate code at all. That is a hole in our data
     * rather than a contradiction — but only when the make agrees, so a blank code can never
     * silently absorb another car's keys.
     */
    public function test_a_fleet_record_with_no_plate_code_is_matched_when_the_make_agrees(): void
    {
        $car = $this->car('36034', null, 'LAND ROVER', 'DEFENDER');

        $this->importer()->import($this->sheet([
            ['LAND ROVER DEFENDER', '2026', 'Z36034', '1', 'FROM VIP'],
        ]), $this->actor, dryRun: false);

        $this->assertSame(1, $this->keysOn($car));
    }

    public function test_a_blank_plate_code_does_not_absorb_a_key_when_the_make_disagrees(): void
    {
        $car = $this->car('36034', null, 'TOYOTA', 'LAND CRUISER');

        $result = $this->importer()->import($this->sheet([
            ['LAND ROVER DEFENDER', '2026', 'Z36034', '1', ''],
        ]), $this->actor, dryRun: false);

        $this->assertSame(0, $this->keysOn($car));
        $this->assertSame('unmatched', $result['rows'][0]['outcome']);
    }

    public function test_a_plate_the_fleet_has_never_heard_of_is_reported(): void
    {
        $result = $this->importer()->import($this->sheet([
            ['INFINITI', 'N/A', 'BB56617', '2', 'MR.BASEM CAR'],
        ]), $this->actor, dryRun: false);

        $this->assertSame('unmatched', $result['rows'][0]['outcome']);
        $this->assertSame(0, VehicleComponent::count());
    }

    // ───────────────────────────── what gets written ─────────────────────────────

    public function test_a_key_is_written_as_a_backfilled_asset_with_no_invented_facts(): void
    {
        $car = $this->car('89187', null, 'CITROEN', 'C4');

        $this->importer()->import($this->sheet([
            ['CITROEN C4', '2024', 'EE89187', '1', 'PHYSICAL KEY ONLY'],
        ]), $this->actor, dryRun: false);

        /** @var VehicleComponent $key */
        $key = VehicleComponent::where('vehicle_id', $car->id)->firstOrFail();

        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $key->status);
        $this->assertSame('unit_1', $key->position);
        $this->assertSame('Physical Key', $key->label, 'the remark states the KIND of key');

        // Nothing the register does not say may appear on the row.
        $this->assertNull($key->installed_at, 'the register records no arrival date — none may be invented');
        $this->assertNull($key->purchase_cost);
        $this->assertNull($key->supplier_vendor_id);
        $this->assertNull($key->serial_no);

        // …and the row says how far to trust it.
        $this->assertSame(VehicleComponent::SOURCE_LEGACY_BACKFILL, $key->source);
        $this->assertSame(VehicleComponent::EV_IMPORT, $key->evidence_channel);
        $this->assertSame(VehicleComponent::ACQ_UNKNOWN, $key->acquisition);

        // The register's own words survive on the biography, not just the parsed type.
        $this->assertStringContainsString('PHYSICAL KEY ONLY', (string) $key->events()->first()->note);
    }

    public function test_two_keys_on_one_car_become_two_independently_slotted_components(): void
    {
        $car = $this->car('89170', null, 'CITROEN', 'C4');

        $this->importer()->import($this->sheet([
            ['CITROEN C4', '2024', 'EE89170', '2', 'PHYSICAL + REMOTE KEY'],
        ]), $this->actor, dryRun: false);

        $keys = VehicleComponent::where('vehicle_id', $car->id)->orderBy('position')->get();

        $this->assertCount(2, $keys);
        $this->assertSame(['unit_1', 'unit_2'], $keys->pluck('position')->all());
        $this->assertSame('Physical + Remote Key', $keys[0]->label);
    }

    /**
     * Quantity 0 means the key exists but is not with us — out with a customer. Recording it would
     * claim the fleet holds a key it cannot lay hands on.
     */
    public function test_a_zero_count_writes_nothing_and_is_named_in_the_report(): void
    {
        $car = $this->car('75354', null, 'CHEVROLET', 'CAMARO');

        $result = $this->importer()->import($this->sheet([
            ['CHEVROLET CAMARO', '2022', 'V75354', '0', 'BASIC KEY IS FOUND AND WILL RETURN AFTER THE CX FINISH HIS RENT'],
        ]), $this->actor, dryRun: false);

        $this->assertSame(0, $this->keysOn($car));
        $this->assertSame('zero_on_hand', $result['rows'][0]['outcome']);
    }

    // ───────────────────────────── running it twice ─────────────────────────────

    public function test_the_import_is_idempotent(): void
    {
        $car = $this->car('32967', null, 'CHEVROLET', 'CORVETTE STINGRAY');
        $sheet = $this->sheet([['CHEVROLET CORVETTE STINGRAY', '2025', 'Z32967', '1', '']]);

        $this->importer()->import($sheet, $this->actor, dryRun: false);
        $second = $this->importer()->import($sheet, $this->actor, dryRun: false);

        $this->assertSame(1, $this->keysOn($car));
        $this->assertSame(0, $second['created']);
        $this->assertSame('already_present', $second['rows'][0]['outcome']);
    }

    /** A dry run resolves everything and writes nothing. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $car = $this->car('32967', null, 'CHEVROLET', 'CORVETTE STINGRAY');

        $result = $this->importer()->import(
            $this->sheet([['CHEVROLET CORVETTE STINGRAY', '2025', 'Z32967', '1', '']]),
            $this->actor,
            dryRun: true
        );

        $this->assertSame(0, $this->keysOn($car));
        $this->assertSame('would_create', $result['rows'][0]['outcome']);
        $this->assertSame(1, $result['applied']);
    }

    /**
     * TOP-UP ONLY. If the ledger holds more than the register counts, the extra is REPORTED and left
     * alone — taking a component off a car is a removal, and a removal needs a reason and a
     * disposition that a spreadsheet cell cannot supply.
     */
    public function test_a_surplus_in_the_ledger_is_reported_and_never_auto_removed(): void
    {
        $car = $this->car('89170', null, 'CITROEN', 'C4');

        $this->importer()->import($this->sheet([['CITROEN C4', '2024', 'EE89170', '2', '']]), $this->actor, dryRun: false);
        $this->assertSame(2, $this->keysOn($car));

        // The register is later corrected down to one.
        $result = $this->importer()->import($this->sheet([['CITROEN C4', '2024', 'EE89170', '1', '']]), $this->actor, dryRun: false);

        $this->assertSame(2, $this->keysOn($car), 'a key already on the car is never silently removed');
        $this->assertSame('surplus', $result['rows'][0]['outcome']);
        $this->assertStringContainsString('left alone for a human', $result['rows'][0]['detail']);
    }

    // ───────────────────────────── what the profile shows ─────────────────────────────

    public function test_the_vehicle_profile_reports_the_imported_keys_as_held_today(): void
    {
        $car = $this->car('89170', null, 'CITROEN', 'C4');

        $this->importer()->import($this->sheet([['CITROEN C4', '2024', 'EE89170', '2', 'PHYSICAL + REMOTE KEY']]), $this->actor, dryRun: false);

        $panel = app(SpareKeyService::class)->forVehicle($car->fresh());

        $this->assertSame(2, $panel['current']['count']);
        $this->assertSame(2, $panel['history']['keys_ever_received']);
        $this->assertSame(0, $panel['history']['requirements_raised'], 'a held key is not a requirement');
        $this->assertTrue($panel['current']['keys'][0]['from_register'], 'the UI must be able to say where this came from');
        $this->assertNull($panel['current']['keys'][0]['received_at']);
    }

    /**
     * A car with BOTH an imported historical requirement and an imported key reads coherently:
     * the requirement is history, the key is what it holds now, and neither inflates the other.
     */
    public function test_an_imported_requirement_and_an_imported_key_do_not_double_count(): void
    {
        $car = $this->car('32967', null, 'CHEVROLET', 'CORVETTE STINGRAY');

        $requirement = new SpareKeyRequirement();
        $requirement->fill([
            'vehicle_id' => $car->id, 'quantity' => 1,
            'reason_code' => SpareKeyRequirement::REASON_MISSING,
            'source' => SpareKeyRequirement::SOURCE_SHEET_IMPORT,
            'external_ref' => 'need-spare-key-2:x',
            'started_on' => '2026-07-01', 'finished_on' => '2026-07-06',
        ]);
        $requirement->status = SpareKeyRequirement::STATUS_COMPLETED;
        $requirement->save();

        $this->importer()->import($this->sheet([['CHEVROLET CORVETTE STINGRAY', '2025', 'Z32967', '1', '']]), $this->actor, dryRun: false);

        $panel = app(SpareKeyService::class)->forVehicle($car->fresh());

        $this->assertSame(1, $panel['current']['count']);
        $this->assertSame(1, $panel['history']['requirements_raised']);
        $this->assertSame(1, $panel['history']['keys_ever_received']);
        $this->assertSame(0, $panel['history']['keys_retired']);
        // The requirement must stay completed — a backfilled key has no purchase, so the projection
        // that watches purchases has nothing to react to.
        $this->assertSame(SpareKeyRequirement::STATUS_COMPLETED, $requirement->fresh()->status);
    }
}
