<?php

namespace Tests\Crud;

use App\Models\Vehicle;
use App\Services\GoogleSheetsService;
use App\Services\OfficeManagerClient;
use App\Services\OfficeManagerSync;
use App\Services\VehicleImporter;
use Mockery;

/**
 * THE SHEET SAYS WHICH CARS EXIST — THE API ONLY REFRESHES THEM.
 *
 * Reversed on 2026-08-19 at the owner's instruction. OfficeManager lists every car the company has
 * ever owned under our owner number, so letting it decide membership filled the fleet with hundreds
 * of cars sold years ago; and a car the team actually takes on is written on the "Faster" tab first.
 *
 * Both halves of that rule are load-bearing, and each one fails silently if it slips: an API that
 * can create again quietly repopulates the fleet with history, and a sheet that cannot create leaves
 * a new car invisible to everyone while the register plainly lists it.
 */
class FleetRegisterIsTheSheetTest extends CrudTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** The "Faster" tab, with the header on row 3 the way the real one has it. */
    private function registerRows(array $dataRows): array
    {
        return array_merge([
            ['', '', ''],
            ['', '', ''],
            ['NAME', 'Chassis', 'Status'],
        ], $dataRows);
    }

    private function importRegister(array $dataRows): array
    {
        config(['google.sheets.cars.header_row' => 3, 'google.sheets.asset.id' => null]);

        $sheets = Mockery::mock(GoogleSheetsService::class);
        $sheets->shouldReceive('readByGid')->andReturn($this->registerRows($dataRows));

        return (new VehicleImporter($sheets))->import();
    }

    private function syncApiVehicles(array $rows): array
    {
        $api = Mockery::mock(OfficeManagerClient::class);
        $api->shouldReceive('count')->andReturn(count($rows));
        $api->shouldReceive('vehicles')->andReturnUsing(function () use ($rows) {
            foreach ($rows as $row) {
                yield $row;
            }
        });

        return (new OfficeManagerSync($api))->importFleetVehicles();
    }

    private function ourOwnerNo(): string
    {
        return (string) (config('officemanager.owner_nos')[0] ?? '1541');
    }

    public function test_a_car_on_the_register_that_we_do_not_hold_is_created(): void
    {
        $result = $this->importRegister([['TESLA MODEL 3', 'REGVIN0000000001', 'Active']]);

        $this->assertSame(1, $result['created']);

        $car = Vehicle::where('vin', 'REGVIN0000000001')->first();
        $this->assertNotNull($car, 'a car the register lists must exist');
        $this->assertSame('TESLA', $car->make);
        $this->assertSame('MODEL 3', $car->model);
        $this->assertSame('sheet', $car->origin, 'and it must say the register is where it came from');
    }

    public function test_importing_the_register_twice_does_not_create_the_car_twice(): void
    {
        $rows = [['TESLA MODEL 3', 'REGVIN0000000002', 'Active']];
        $this->importRegister($rows);
        $second = $this->importRegister($rows);

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, Vehicle::where('vin', 'REGVIN0000000002')->count());
    }

    public function test_the_api_never_creates_a_car_the_register_does_not_list(): void
    {
        $before = Vehicle::count();

        $result = $this->syncApiVehicles([[
            'CarSerial'  => 99001,
            'CarOwnerNo' => $this->ourOwnerNo(),
            'ChasisNo'   => 'OMONLYVIN0000001',
            'CarNo'      => '12345',
            'Milage'     => 40000,
        ]]);

        $this->assertSame($before, Vehicle::count(), 'OfficeManager may not add a car to the fleet');
        $this->assertNull(Vehicle::where('vin', 'OMONLYVIN0000001')->first());
        $this->assertSame(1, $result['unlisted'], 'and it must report the car it left out');
    }

    public function test_the_api_still_refreshes_a_car_the_register_created(): void
    {
        $this->importRegister([['TESLA MODEL 3', 'REGVIN0000000003', 'Active']]);

        $this->syncApiVehicles([[
            'CarSerial'  => 99003,
            'CarOwnerNo' => $this->ourOwnerNo(),
            'ChasisNo'   => 'REGVIN0000000003',
            'CarNo'      => '54321',
            'Milage'     => 40000,
        ]]);

        $car = Vehicle::where('vin', 'REGVIN0000000003')->first();
        $this->assertSame(99003, (int) $car->car_serial, 'the register car must pick up its OM serial');
        $this->assertSame(40000, (int) $car->odometer);
        $this->assertSame('TESLA', $car->make, 'while make/model stay the sheet\'s');
    }

    public function test_a_car_whose_om_plate_is_really_its_chassis_number_links_instead_of_duplicating(): void
    {
        // Somebody typed the chassis number into OfficeManager's plate field, so the OM row carries
        // no VIN at all. Matched on the VIN digits, this is the register's car — not a second one.
        $this->importRegister([['FORD MUSTANG', '1FATP8UH2P5107903', 'Active']]);
        $before = Vehicle::count();

        $this->syncApiVehicles([[
            'CarSerial'  => 99004,
            'CarOwnerNo' => $this->ourOwnerNo(),
            'ChasisNo'   => '',
            'CarNo'      => '5107903',
            'Milage'     => 12000,
        ]]);

        $this->assertSame($before, Vehicle::count(), 'the same car must not exist twice');

        $car = Vehicle::where('vin', '1FATP8UH2P5107903')->first();
        $this->assertSame(99004, (int) $car->car_serial);
        $this->assertSame('1FATP8UH2P5107903', $car->vin, 'a blank OM chassis may not erase our VIN');
        $this->assertNotSame('5107903', $car->plate_no, 'nor may the chassis number come back as a plate');
    }
}
