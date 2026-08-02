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

        // The trend is always 12 zero-filled months ending on the current one.
        $this->assertCount(12, $stats['trend']);
        $this->assertSame(Carbon::now()->format('Y-m'), $stats['trend'][11]['key']);
        $this->assertSame(3, $stats['trend'][11]['value']);
        $this->assertSame(0, $stats['trend'][0]['value']);

        $this->assertSame(0, $kpi['verified_fixed'], 'no post-repair inspection signed any of these off');
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
