<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Services\RecurringFaultService;
use Illuminate\Support\Carbon;

/**
 * The Recurring Fault Reviews dashboard runs on FLEET-WIDE stats, not the filtered case list. These tests
 * pin the two things that make that distinction worth having:
 *
 *   1. the aggregates count every case regardless of the table's status/decision filters, and
 *   2. the headline numbers (median time-to-return, failed-after-a-verified-fix, the 12-month trend)
 *      are counted facts, so a wrong roll-up is caught here rather than read off a chart.
 */
class RecurringFaultStatsTest extends CrudTestCase
{
    public function test_stats_aggregate_every_case_not_just_the_open_ones(): void
    {
        $garageId = $this->makeVendor(['name' => 'Al Noor Auto']);

        // Three recurrences on two cars: 15 days, 5 days, 40 days after their repairs. Car A's two cases
        // are built oldest-fix-first on purpose — the detector always matches the MOST RECENT prior fix,
        // so seeding the 5-day repair first would make both of car A's cases read as 5 days.
        $carA = $this->recurrence($garageId, 15);
        $this->recurrence($garageId, 5, $carA['vehicle_id']);
        $carB = $this->recurrence($garageId, 40);

        // One of them has already been ruled on — it must still count everywhere except `open`.
        app(RecurringFaultService::class)->decide(
            $carB['review'],
            $this->admin,
            RecurringFaultReview::DECISION_WORKSHOP_RESPONSIBILITY,
        );

        $stats = app(RecurringFaultService::class)->stats();
        $kpi   = $stats['kpi'];

        $this->assertSame(3, $kpi['total']);
        $this->assertSame(2, $kpi['open']);
        $this->assertSame(1, $kpi['decided']);
        $this->assertSame(3, $kpi['last_30_days'], 'all three cases opened just now');
        $this->assertSame(15, $kpi['median_days'], 'median of 5 / 15 / 40 is 15, not the 20 the mean would give');
        $this->assertSame(1, $kpi['repeat_vehicles'], 'only the car with two cases is a repeat offender');

        // Speed histogram: 5d → ≤7, 15d → 8–30, 40d → 31–60.
        $speed = collect($stats['speed'])->keyBy('key');
        $this->assertSame(1, $speed['0-7']['value']);
        $this->assertSame(1, $speed['8-30']['value']);
        $this->assertSame(1, $speed['31-60']['value']);
        $this->assertSame(0, $speed['61+']['value']);

        // Each bar names the faults that came back in that window — all three are the seeded `cooling`
        // category, so every non-empty bucket carries exactly that one fault, and the empty one none.
        $this->assertSame([['label' => 'cooling', 'value' => 1]], $speed['0-7']['faults']);
        $this->assertSame([['label' => 'cooling', 'value' => 1]], $speed['31-60']['faults']);
        $this->assertSame([], $speed['61+']['faults']);
        $this->assertSame(0, $speed['0-7']['more'], 'one distinct fault fits well inside the top-4 cut');

        // The decision mix carries only actual rulings; the undecided backlog is derived on the client.
        $this->assertSame(
            [['key' => RecurringFaultReview::DECISION_WORKSHOP_RESPONSIBILITY, 'value' => 1]],
            $stats['decisions'],
        );

        // Garage roll-up: three returns after this garage's repairs, one of them ruled its responsibility.
        $this->assertCount(1, $stats['garages']);
        $this->assertSame('Al Noor Auto', $stats['garages'][0]['label']);
        $this->assertSame(3, $stats['garages'][0]['value']);
        $this->assertSame(2, $stats['garages'][0]['open']);
        $this->assertSame(1, $stats['garages'][0]['workshop']);

        // Cars, worst first.
        $this->assertSame(2, $stats['vehicles'][0]['value']);
        $this->assertSame($carA['vehicle_id'], $stats['vehicles'][0]['vehicle_id']);
        $this->assertSame(2, $stats['vehicles'][0]['open'], 'both cases on car A are still awaiting a ruling');

        // Faults: all three share the seeded `cooling` category, across two cars.
        $this->assertCount(1, $stats['faults']);
        $this->assertSame(3, $stats['faults'][0]['value']);
        $this->assertSame(2, $stats['faults'][0]['cars']);

        // Each ranking carries the OTHER dimension for its hover: the cars behind the fault, worst
        // first — this fault is car A twice and car B once, not three separate cars.
        $this->assertSame([2, 1], array_column($stats['faults'][0]['top_cars'], 'value'));
        $this->assertSame(0, $stats['faults'][0]['cars_more'], 'two cars fit inside the top-4 cut');

        // …and the faults behind the car.
        $this->assertSame([['label' => 'cooling', 'value' => 2]], $stats['vehicles'][0]['faults']);
        $this->assertSame(0, $stats['vehicles'][0]['more']);

        // The trend is always 12 zero-filled months ending on the current one.
        $this->assertCount(12, $stats['trend']);
        $this->assertSame(Carbon::now()->format('Y-m'), $stats['trend'][11]['key']);
        $this->assertSame(3, $stats['trend'][11]['value']);
        $this->assertSame(0, $stats['trend'][0]['value']);

        // Cases opened after a post-repair inspection signed the previous fix off are the QC failures.
        // None here — the count lives on the trend and garage tooltips, not on a headline card.
        $this->assertSame(0, $stats['trend'][11]['verified']);
        $this->assertSame(0, $stats['garages'][0]['verified']);
    }

    public function test_the_stats_endpoint_is_served_and_needs_no_filters(): void
    {
        $this->recurrence($this->makeVendor(), 10);

        $res = $this->getJson('/api/recurring-fault-reviews/stats')->assertOk();

        $body = $res->json('data');
        $this->assertSame(1, $body['kpi']['total']);
        $this->assertSame(10, $body['kpi']['median_days']);
        $this->assertCount(12, $body['trend']);
        $this->assertCount(4, $body['speed']);
    }

    /**
     * "Faults that keep coming back" is one of the two date-scoped panels. Narrowing it must move that
     * ranking and nothing else — the KPIs, the trend line and the car ranking stay all-time.
     */
    public function test_the_fault_ranking_can_be_scoped_by_date_without_moving_anything_else(): void
    {
        $garageId = $this->makeVendor();

        $recent = $this->recurrence($garageId, 10);
        $old    = $this->recurrence($garageId, 10);
        // Backdate one case: the recurrence itself was raised four months ago.
        $old['review']->forceFill(['opened_at' => Carbon::now()->subDays(120)])->save();

        $service = app(RecurringFaultService::class);

        $all = $service->stats();
        $this->assertSame(2, $all['faults'][0]['value'], 'all time counts both recurrences');
        $this->assertSame(0, $all['faults_window']['days']);
        $this->assertSame(2, $all['faults_window']['cases']);

        // Trailing preset — only the case opened just now is inside 30 days.
        $windowed = $service->stats(['days' => 30]);
        $this->assertSame(1, $windowed['faults'][0]['value']);
        $this->assertSame(1, $windowed['faults'][0]['cars']);
        $this->assertSame(30, $windowed['faults_window']['days']);

        // Everything else is untouched by the window.
        $this->assertSame(2, $windowed['kpi']['total']);
        $this->assertSame(2, $windowed['garages'][0]['value']);
        $this->assertCount(2, $windowed['vehicles']);
        $this->assertCount(12, $windowed['trend']);

        // An explicit range wins over the preset, and covers whole days at both ends.
        $onlyOld = $service->stats([
            'days' => 30,
            'from' => Carbon::now()->subDays(121)->toDateString(),
            'to'   => Carbon::now()->subDays(119)->toDateString(),
        ]);
        $this->assertSame(1, $onlyOld['faults'][0]['value'], 'the four-month-old case, not the fresh one');
        $this->assertCount(2, $onlyOld['vehicles'], 'the car ranking is still all-time');
        $this->assertSame(0, $onlyOld['faults_window']['days'], 'an explicit range clears the preset');

        // A window with nothing in it empties the ranking rather than falling back to all-time.
        $empty = $service->stats(['from' => Carbon::now()->addDay()->toDateString()]);
        $this->assertSame([], $empty['faults']);
        $this->assertSame(0, $empty['faults_window']['cases']);
        $this->assertSame(2, $empty['kpi']['total']);

        $this->assertNotSame($recent['vehicle_id'], $old['vehicle_id']);
    }

    /**
     * "Cars that keep coming back" carries its own window. The two are independent: scoping the car
     * ranking must not reshape the fault ranking, and vice versa.
     */
    public function test_the_car_ranking_has_its_own_window_independent_of_the_fault_one(): void
    {
        $garageId = $this->makeVendor();

        // Car A: one case raised just now and one raised four months ago — a repeat offender all-time,
        // but a single-case car inside a 30-day window. Car B: one case, four months ago.
        $carA = $this->recurrence($garageId, 10);
        $this->recurrence($garageId, 30, $carA['vehicle_id'])['review']
            ->forceFill(['opened_at' => Carbon::now()->subDays(120)])->save();
        $carB = $this->recurrence($garageId, 10);
        $carB['review']->forceFill(['opened_at' => Carbon::now()->subDays(120)])->save();

        $service = app(RecurringFaultService::class);

        // All time: both cars on the board, car A worst.
        $all = $service->stats();
        $this->assertCount(2, $all['vehicles']);
        $this->assertSame($carA['vehicle_id'], $all['vehicles'][0]['vehicle_id']);
        $this->assertSame(2, $all['vehicles'][0]['value']);

        // Scoped to 30 days: only car A's fresh case survives, so car B leaves the ranking entirely.
        $scoped = $service->stats([], ['days' => 30]);
        $this->assertCount(1, $scoped['vehicles']);
        $this->assertSame($carA['vehicle_id'], $scoped['vehicles'][0]['vehicle_id']);
        $this->assertSame(1, $scoped['vehicles'][0]['value']);
        $this->assertSame(30, $scoped['cars_window']['days']);
        $this->assertSame(1, $scoped['cars_window']['cases']);

        // The car window touches NOTHING else — not the fault ranking, not the KPIs, not the garages.
        $this->assertSame(3, $scoped['faults'][0]['value'], 'the fault ranking is still all-time');
        $this->assertSame(0, $scoped['faults_window']['days']);
        $this->assertSame(3, $scoped['kpi']['total']);
        $this->assertSame(3, $scoped['garages'][0]['value']);
        $this->assertSame(1, $scoped['kpi']['repeat_vehicles'], 'car A is still a repeat offender all-time');

        // …and the two windows can be narrowed to opposite slices at once without interfering.
        $both = $service->stats(
            ['days' => 30],
            ['from' => Carbon::now()->subDays(121)->toDateString(), 'to' => Carbon::now()->subDays(119)->toDateString()],
        );
        $this->assertSame(1, $both['faults'][0]['value'], 'faults: just the fresh case');
        $this->assertSame(2, $both['cars_window']['cases'], 'cars: just the two four-month-old ones');
        $this->assertCount(2, $both['vehicles']);
    }

    /** The endpoint passes both windows through under their `faults_*` / `cars_*` params. */
    public function test_the_stats_endpoint_accepts_both_windows(): void
    {
        $garageId = $this->makeVendor();
        $this->recurrence($garageId, 10);
        $stale = $this->recurrence($garageId, 10);
        $stale['review']->forceFill(['opened_at' => Carbon::now()->subDays(200)])->save();

        $body = $this->getJson('/api/recurring-fault-reviews/stats?faults_days=30&cars_days=30')
            ->assertOk()->json('data');

        $this->assertSame(1, $body['faults'][0]['value']);
        $this->assertSame(30, $body['faults_window']['days']);
        $this->assertCount(1, $body['vehicles']);
        $this->assertSame(30, $body['cars_window']['days']);
        $this->assertSame(2, $body['kpi']['total'], 'the KPIs ignore both windows');

        $this->getJson('/api/recurring-fault-reviews/stats?faults_days=-1')->assertStatus(422);
        $this->getJson('/api/recurring-fault-reviews/stats?cars_to=not-a-date')->assertStatus(422);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Build one full recurrence: a fault fixed `$daysAgo` days ago at `$garageId`, then the same fault
     * confirmed again today — which is what opens the review case.
     *
     * @return array{vehicle_id:int, review:RecurringFaultReview}
     */
    private function recurrence(int $garageId, int $daysAgo, ?int $vehicleId = null): array
    {
        $vehicleId ??= $this->makeVehicle(['odometer' => 41000]);

        $old = $this->ticket($vehicleId, Maintenance::WF_CLOSED, ['return_odometer' => 40000]);
        $this->fault($old, $vehicleId, [
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => Carbon::now()->subDays($daysAgo),
            'current_vendor_id' => $garageId,
        ]);

        $new    = $this->ticket($vehicleId, Maintenance::WF_UNDER_REPAIR);
        $review = app(RecurringFaultService::class)->onFaultConfirmed(
            $this->fault($new, $vehicleId),
            $this->admin,
        );
        $this->assertNotNull($review, "a fault fixed {$daysAgo} days ago must open a review");

        return ['vehicle_id' => $vehicleId, 'review' => $review];
    }

    private function ticket(int $vehicleId, string $status, array $extra = []): Maintenance
    {
        $ticket = new Maintenance();
        $ticket->vehicle_id      = $vehicleId;
        $ticket->origin          = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status = $status;
        $ticket->event_status    = 'OUT';
        $ticket->forceFill($extra);
        $ticket->save();

        return $ticket;
    }

    private function fault(Maintenance $ticket, int $vehicleId, array $extra = []): MaintenanceTask
    {
        $fault = new MaintenanceTask();
        $fault->forceFill(array_merge([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicleId,
            'symptom'        => 'Engine overheating',
            'category_key'   => 'cooling',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ], $extra))->save();

        return $fault;
    }
}
