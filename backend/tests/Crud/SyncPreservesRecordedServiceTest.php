<?php

namespace Tests\Crud;

use App\Models\Vehicle;
use App\Services\GoogleSheetsService;
use App\Services\OfficeManagerClient;
use App\Services\OfficeManagerSync;
use App\Services\OilChangeImporter;
use Mockery;

/**
 * A SYNC MAY NOT UNDO WHAT MAINTENANCE RECORDED.
 *
 * Both service anchors on a car — the oil baseline and the battery date — used to have exactly one
 * writer: an inbound sync. They no longer do. Closing a ticket that carried an oil change or a battery
 * replacement writes both (Vehicle::recordServiceDone, via confirmRoutineServices), and neither upstream
 * can ever learn that happened: the Oil Change sheet is filled in by hand, and OfficeManager is a
 * READ-ONLY replica we cannot push to.
 *
 * So every scheduled sync carried the older upstream figure straight over the newer one we had just
 * recorded. The car's oil anchor rolled backward and it read tens of thousands of km overdue; the
 * battery date reverted and the battery check re-raised itself for a battery fitted the day before.
 * The work was done — the platform simply forgot, on a timer, and then nagged about it.
 *
 * The rule both sides now hold: these anchors only ever move FORWARD.
 */
class SyncPreservesRecordedServiceTest extends CrudTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Run the Oil Change sheet import against a fixed set of rows. */
    private function importOilSheet(array $rows): array
    {
        $sheets = Mockery::mock(GoogleSheetsService::class);
        $sheets->shouldReceive('readByGid')->andReturn($rows);

        return (new OilChangeImporter($sheets))->import();
    }

    /** Run the OM fleet-vehicle sync against a fixed set of API rows. */
    private function syncApiVehicles(array $rows): void
    {
        $api = Mockery::mock(OfficeManagerClient::class);
        $api->shouldReceive('count')->andReturn(count($rows));
        $api->shouldReceive('vehicles')->andReturnUsing(function () use ($rows) {
            foreach ($rows as $row) {
                yield $row;
            }
        });

        (new OfficeManagerSync($api))->importFleetVehicles();
    }

    /** The owner number the sync treats as ours, so the fixture row is actually picked up. */
    private function ourOwnerNo(): string
    {
        return (string) (config('officemanager.owner_nos')[0] ?? '1541');
    }

    public function test_the_oil_sheet_cannot_roll_a_recorded_oil_change_backward(): void
    {
        $vehicle = Vehicle::find($this->makeVehicle(['odometer' => 120000]));
        $vehicle->update(['vin' => 'OILVIN0000000001', 'service_interval_km' => 10000]);

        // A closed ticket recorded the oil change at 120,000 km.
        $vehicle->recordOilService(120000);
        $this->assertSame(120000, (int) $vehicle->fresh()->last_service_odometer);

        // The sheet still shows the PREVIOUS change, at 95,000 — nobody has typed the new one in yet.
        $result = $this->importOilSheet([
            ['Chassis', 'LAST CHANGE', 'VALIDITY'],
            ['OILVIN0000000001', '95,000', '10000'],
        ]);

        $vehicle->refresh();
        $this->assertSame(120000, (int) $vehicle->last_service_odometer, 'the sheet must not undo a recorded oil change');
        $this->assertSame(1, $result['preserved'] ?? 0, 'the run must report that it kept our newer anchor');

        // The car is NOT overdue: with the anchor intact it has its full interval left.
        $this->assertSame('ok', $vehicle->serviceStatus()['status']);
    }

    public function test_the_oil_sheet_still_moves_the_anchor_forward(): void
    {
        $vehicle = Vehicle::find($this->makeVehicle(['odometer' => 90000]));
        $vehicle->update(['vin' => 'OILVIN0000000002', 'last_service_odometer' => 80000, 'service_interval_km' => 10000]);

        // The sheet carries a LATER change than anything we hold — a service done outside the workflow.
        // That is real information and must still land: the guard is forward-only, not sheet-off.
        $this->importOilSheet([
            ['Chassis', 'LAST CHANGE', 'VALIDITY'],
            ['OILVIN0000000002', '88,000', '12000'],
        ]);

        $vehicle->refresh();
        $this->assertSame(88000, (int) $vehicle->last_service_odometer);
        $this->assertSame(12000, (int) $vehicle->service_interval_km, 'the interval stays sheet-owned either way');
    }

    public function test_the_om_sync_cannot_roll_a_recorded_battery_change_backward(): void
    {
        $vehicle = Vehicle::find($this->makeVehicle(['odometer' => 60000]));
        // origin 'api' = a car the OM sync owns. A 'web' car (the fixture default) is skipped outright
        // by importFleetVehicles, which would make this test pass without the sync ever running.
        $vehicle->update(['vin' => 'BATVIN0000000001', 'car_serial' => 90001, 'origin' => 'api']);

        // A closed ticket recorded the battery replacement today.
        $vehicle->recordServiceDone('battery', 60000);
        $today = now()->toDateString();
        $this->assertSame($today, optional($vehicle->fresh()->battery_last_changed)->toDateString());

        // OfficeManager still holds the battery fitted two years ago — it has never heard of ours.
        $this->syncApiVehicles([[
            'CarSerial'          => 90001,
            'CarOwnerNo'         => $this->ourOwnerNo(),
            'ChasisNo'           => 'BATVIN0000000001',
            'CarNo'              => $vehicle->plate_no,
            'Milage'             => 60000,
            'BatteryLastChanged' => now()->subYears(2)->toDateString(),
        ]]);

        $this->assertSame(
            $today,
            optional($vehicle->fresh()->battery_last_changed)->toDateString(),
            'the API must not undo a battery replacement we recorded',
        );
    }

    public function test_the_om_sync_still_moves_the_battery_date_forward(): void
    {
        $vehicle = Vehicle::find($this->makeVehicle(['odometer' => 60000]));
        $vehicle->update([
            'vin'                  => 'BATVIN0000000002',
            'car_serial'           => 90002,
            'origin'               => 'api',
            'battery_last_changed' => now()->subYears(3)->toDateString(),
        ]);

        // A battery changed in OfficeManager and never in our workflow — the newer date is theirs, and wins.
        $newer = now()->subMonth()->toDateString();
        $this->syncApiVehicles([[
            'CarSerial'          => 90002,
            'CarOwnerNo'         => $this->ourOwnerNo(),
            'ChasisNo'           => 'BATVIN0000000002',
            'CarNo'              => $vehicle->plate_no,
            'Milage'             => 60000,
            'BatteryLastChanged' => $newer,
        ]]);

        $this->assertSame($newer, optional($vehicle->fresh()->battery_last_changed)->toDateString());
    }
}
