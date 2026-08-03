<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\Vehicle;
use App\Services\PartSpendService;
use Illuminate\Support\Carbon;

/**
 * Pins the date window on the "Where parts money goes" ledger (PartSpendService::ranked).
 *
 * The correctness claim of a window filter is not "it returns fewer rows" — it is that consecutive
 * windows PARTITION the all-time total: every dirham lands in exactly one slice, none duplicated and
 * none lost. That is what these tests assert, because the two ledgers date their money from different
 * columns (purchased_at vs installed_on/invoice date) and it would be easy to drop the rows that carry
 * neither, silently shrinking every windowed total.
 */
class PartSpendWindowTest extends CrudTestCase
{
    private PartSpendService $spend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spend = app(PartSpendService::class);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::findOrFail($this->makeVehicle());
    }

    /** An unfitted purchase — the ledger's first source, dated by purchased_at. */
    private function purchase(string $name, float $price, string $purchasedAt): PartPurchase
    {
        return PartPurchase::create([
            'vehicle_id'      => $this->vehicle()->id,
            'part_name'       => $name,
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'  => $price,
            'currency'        => 'AED',
            'quantity'        => 1,
            'purchased_at'    => $purchasedAt,
        ]);
    }

    /** An itemised part line — the ledger's second source, dated by installed_on when it has one. */
    private function line(string $desc, float $price, ?string $installedOn): MaintenanceLineItem
    {
        $vehicle = $this->vehicle();

        return MaintenanceLineItem::create([
            // A line is always a charge ON a ticket (the column is NOT NULL) — the cost rolls
            // line → task → ticket, so it needs a parent even when the test only reads the total.
            'maintenance_id' => Maintenance::create(['vehicle_id' => $vehicle->id])->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceLineItem::KIND_PART,
            'description'    => $desc,
            'quantity'       => 1,
            'unit_price'     => $price,
            'installed_on'   => $installedOn,
        ]);
    }

    public function test_no_window_returns_every_dirham(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');
        $this->line('Brake Pads', 300, '2024-06-01');

        $this->assertSame(800.0, $this->spend->ranked('part', 50)['totals']['spend']);
    }

    public function test_window_selects_only_money_spent_inside_it(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');
        $this->purchase('Radiator', 700, '2025-03-01 10:00:00');

        $r = $this->spend->ranked('part', 50, null, ['from' => '2023-01-01', 'to' => '2023-12-31']);

        $this->assertSame(500.0, $r['totals']['spend']);
        $this->assertSame(['Alternator'], array_column($r['rows'], 'label'));
    }

    /** The invariant that matters: adjacent windows sum back to the all-time total. */
    public function test_consecutive_windows_partition_the_all_time_total(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');
        $this->purchase('Radiator', 700, '2024-07-04 10:00:00');
        $this->line('Brake Pads', 300, '2025-02-02');
        $this->line('Battery', 150, '2023-11-30');

        $allTime = $this->spend->ranked('part', 50)['totals']['spend'];

        $sliced = 0.0;
        foreach ([2023, 2024, 2025] as $year) {
            $sliced += $this->spend->ranked('part', 50, null, [
                'from' => "$year-01-01",
                'to'   => "$year-12-31",
            ])['totals']['spend'];
        }

        $this->assertSame(1650.0, $allTime);
        $this->assertSame($allTime, $sliced);
    }

    /** Both ends are inclusive of their whole day, so one date at both ends means "that day". */
    public function test_window_ends_are_inclusive_of_the_whole_day(): void
    {
        $this->purchase('Late Buy', 400, '2024-05-10 23:45:00');

        $r = $this->spend->ranked('part', 50, null, ['from' => '2024-05-10', 'to' => '2024-05-10']);

        $this->assertSame(400.0, $r['totals']['spend']);
    }

    /** A range drawn backwards is corrected, not answered with an empty chart. */
    public function test_reversed_range_is_swapped(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');

        $forward  = $this->spend->ranked('part', 50, null, ['from' => '2023-01-01', 'to' => '2023-12-31']);
        $backward = $this->spend->ranked('part', 50, null, ['from' => '2023-12-31', 'to' => '2023-01-01']);

        $this->assertSame($forward['totals']['spend'], $backward['totals']['spend']);
        $this->assertSame(500.0, $backward['totals']['spend']);
    }

    /** An explicit range wins over a trailing preset — `days` must not clip a hand-drawn window. */
    public function test_explicit_range_overrides_days_preset(): void
    {
        $this->purchase('Old Buy', 500, '2023-03-01 10:00:00');

        $r = $this->spend->ranked('part', 50, null, ['days' => 30, 'from' => '2023-01-01', 'to' => '2023-12-31']);

        $this->assertSame(500.0, $r['totals']['spend']);
        $this->assertSame(0, $r['window']['days']);
    }

    public function test_days_preset_is_a_trailing_window(): void
    {
        $this->purchase('Recent', 500, Carbon::now()->subDays(5)->toDateTimeString());
        $this->purchase('Ancient', 900, Carbon::now()->subDays(400)->toDateTimeString());

        $r = $this->spend->ranked('part', 50, null, ['days' => 30]);

        $this->assertSame(500.0, $r['totals']['spend']);
        $this->assertSame('Recent', $r['rows'][0]['label']);
    }

    /**
     * A line with no installed_on and no invoice has no real money date. It must still be counted
     * (dropping it would shrink the total) but must be reported as entry-dated, so the UI can say so.
     */
    public function test_undated_line_is_counted_but_flagged_as_entry_dated(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');
        $this->line('Mystery Part', 250, null);   // created_at = now

        $all = $this->spend->ranked('part', 50);
        $this->assertSame(750.0, $all['totals']['spend']);
        $this->assertSame(1, $all['dated']['entry']);
        $this->assertSame(1, $all['dated']['exact']);

        // It falls in TODAY's window, not the year its money would otherwise be guessed into.
        $today = Carbon::now()->toDateString();
        $r = $this->spend->ranked('part', 50, null, ['from' => $today, 'to' => $today]);
        $this->assertSame(250.0, $r['totals']['spend']);
        $this->assertSame(1, $r['dated']['entry']);
    }

    /** The response states the slice it answered for, so a caption never has to guess. */
    public function test_response_echoes_the_resolved_window(): void
    {
        $r = $this->spend->ranked('part', 50, null, ['from' => '2024-02-01', 'to' => '2024-02-29']);

        $this->assertSame('2024-02-01', $r['window']['from']);
        $this->assertSame('2024-02-29', $r['window']['to']);
    }

    /** The vehicle scope and the date window compose instead of overriding each other. */
    public function test_vehicle_scope_and_window_compose(): void
    {
        $a = $this->purchase('Alternator', 500, '2023-03-01 10:00:00');
        $this->purchase('Radiator', 700, '2023-04-01 10:00:00');   // different vehicle

        $r = $this->spend->ranked('part', 50, $a->vehicle_id, ['from' => '2023-01-01', 'to' => '2023-12-31']);

        $this->assertSame(500.0, $r['totals']['spend']);
    }

    /** The endpoint accepts the window params and rejects nonsense. */
    public function test_endpoint_accepts_window_params(): void
    {
        $this->purchase('Alternator', 500, '2023-03-01 10:00:00');

        $this->getJson('/api/part-requests/spend?by=part&from=2023-01-01&to=2023-12-31')
            ->assertSuccessful()
            ->assertJsonPath('data.totals.spend', 500)
            ->assertJsonPath('data.window.from', '2023-01-01');

        $this->getJson('/api/part-requests/spend?days=notanumber')->assertStatus(422);
    }
}
