<?php

namespace Tests\Foundation;

use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGarageAlertState;
use App\Notifications\FleetAlert;
use App\Services\Garage\GarageIntelligenceService;
use App\Services\OperationsService;
use App\Support\GarageSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Garage Intelligence, end to end against the real schema: the reading, the escalation rule that
 * decides who hears about it, and the wiring that makes a maintenance event trigger both.
 *
 * The DB-free arithmetic (window clipping, overlap merging, bad timestamps, the severity ladder) is
 * locked in tests/Unit/GarageVisitAndDowntimeMathTest and GarageSeverityTest. THIS suite is about the
 * things only a database can prove: that a stay is read from the real contract rows, that the state
 * survives between evaluations, that repeated maintenance activity does not spam an admin, and that
 * a genuine workflow transition reaches the engine through the application's own plumbing rather
 * than through a call this test made up.
 */
class GarageIntelligenceAlertTest extends FoundationTestCase
{
    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        // A named recipient. Pinning the allow-list keeps the test about the ALERT rather than about
        // whichever roles happen to be seeded in fleet_test.
        $this->recipient = User::create([
            'name'     => 'Garage Admin',
            'email'    => 'garage.'.uniqid().'@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);

        config([
            'garage_intelligence.enabled'              => true,
            'garage_intelligence.realtime'             => true,
            'garage_intelligence.window_days'          => 30,
            'garage_intelligence.visits'               => ['warning' => 3, 'high' => 5, 'critical' => 7],
            'garage_intelligence.downtime_days'        => ['warning' => 3, 'high' => 7, 'critical' => 14],
            'garage_intelligence.recipients.user_ids'  => [$this->recipient->id],
        ]);

        GarageIntelligenceService::$muted = false;
    }

    protected function tearDown(): void
    {
        GarageIntelligenceService::$muted = false;
        parent::tearDown();
    }

    private function intel(): GarageIntelligenceService
    {
        return app(GarageIntelligenceService::class);
    }

    /**
     * A car that is IN SERVICE — it has been rented once, long ago, which is what gives it a
     * performance anchor. Without one the engine reports `pending_service` and never grades, exactly
     * as the fleet-utilization report does (a freshly bought car's workshop time is onboarding, not
     * downtime).
     */
    private function inServiceVehicle(): Vehicle
    {
        $vehicle = $this->makeVehicle();

        Contract::create([
            'contract_no'   => 'T-C-'.uniqid(),
            'contract_type' => 'C',
            'vehicle_id'    => $vehicle->id,
            'state'         => 'closed',
            'out_date'      => Carbon::today()->subYear()->toDateString(),
            'out_time'      => '09:00:00',
            'in_date'       => Carbon::today()->subYear()->addDays(3)->toDateString(),
            'in_time'       => '09:00:00',
        ]);

        return $vehicle;
    }

    /** One garage stay: a type-'U' maintenance contract. `$daysAgo` is when it began. */
    private function stay(Vehicle $vehicle, int $startedDaysAgo, ?int $lengthDays = 1, string $state = 'closed'): Contract
    {
        $out = Carbon::today()->subDays($startedDaysAgo);

        return Contract::create([
            'contract_no'   => 'T-U-'.uniqid(),
            'contract_type' => 'U',
            'vehicle_id'    => $vehicle->id,
            'state'         => $lengthDays === null ? 'open' : $state,
            'out_date'      => $out->toDateString(),
            'out_time'      => '09:00:00',
            'in_date'       => $lengthDays === null ? null : $out->copy()->addDays($lengthDays)->toDateString(),
            'in_time'       => $lengthDays === null ? null : '09:00:00',
        ]);
    }

    /** Every garage alert this test's recipient has been sent. */
    private function alerts(?string $type = null): \Illuminate\Support\Collection
    {
        return $this->recipient->fresh()->notifications()
            ->where('type', FleetAlert::class)
            ->get()
            ->filter(fn ($n) => in_array($n->data['type'] ?? '', ['garage_visit_frequency', 'garage_downtime'], true))
            ->filter(fn ($n) => $type === null || ($n->data['type'] ?? '') === $type)
            ->values();
    }

    // ── The reading ────────────────────────────────────────────────────────────────────────────

    public function test_a_quiet_car_is_graded_normal_and_tells_nobody(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 10, lengthDays: 1);

        $state = $this->intel()->evaluate($vehicle);

        $this->assertSame(1, $state->visits);
        $this->assertSame(GarageSeverity::NORMAL, $state->severity);
        $this->assertCount(0, $this->alerts());
    }

    /**
     * FOUR JOBS, ONE STAY, ONE VISIT. The car goes in once and has four things done to it. Those are
     * four maintenance records against one garage stay, and the count must be 1 — this is the rule
     * the whole feature rests on, proved here against real rows rather than fabricated intervals.
     */
    public function test_several_jobs_during_one_stay_count_as_a_single_visit(): void
    {
        $vehicle = $this->inServiceVehicle();
        $stay    = $this->stay($vehicle, startedDaysAgo: 10, lengthDays: 4);

        // Four workflow tickets, all belonging to that one stay.
        foreach (['Inspection', 'Oil change', 'Brake repair', 'Electrical repair'] as $job) {
            \App\Models\Maintenance::create([
                'vehicle_id'         => $vehicle->id,
                'origin'             => 'manual',
                'linked_contract_id' => $stay->id,
                'service_main'       => $job,
                'out_date'           => Carbon::today()->subDays(10)->toDateString(),
            ]);
        }

        $reading = $this->intel()->read($vehicle->id);

        $this->assertSame(1, $reading['visits'], 'four jobs in one stay is one garage visit, not four');
        $this->assertEqualsWithDelta(4.0, $reading['downtime_days'], 0.05);
    }

    public function test_visits_outside_the_window_are_not_counted(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 5, lengthDays: 1);
        $this->stay($vehicle, startedDaysAgo: 60, lengthDays: 1);   // long gone
        $this->stay($vehicle, startedDaysAgo: 90, lengthDays: 1);

        $this->assertSame(1, $this->intel()->read($vehicle->id)['visits']);
    }

    public function test_a_car_still_in_the_garage_accrues_downtime_from_its_entry(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 8, lengthDays: null);   // open — no return recorded

        $reading = $this->intel()->read($vehicle->id);

        $this->assertTrue($reading['currently_in_garage']);
        $this->assertEqualsWithDelta(8.0, $reading['downtime_days'], 0.6, 'an open stay runs to now');
        $this->assertSame(GarageSeverity::HIGH, $reading['downtime_severity']);
        $this->assertStringContainsString('in a garage right now', implode(' ', $reading['reasons']));
    }

    public function test_overlapping_stays_are_not_double_counted(): void
    {
        $vehicle = $this->inServiceVehicle();
        // Two records describing the same four-day trip. Summed they would read eight days.
        $this->stay($vehicle, startedDaysAgo: 10, lengthDays: 4);
        $this->stay($vehicle, startedDaysAgo: 9, lengthDays: 3);

        $this->assertEqualsWithDelta(4.0, $this->intel()->read($vehicle->id)['downtime_days'], 0.05);
    }

    /**
     * Rental is King. A car that was with a customer for part of its maintenance window was not off
     * the road for that part, and grading it as downtime would punish a car that was earning.
     */
    public function test_time_the_customer_had_the_car_is_not_garage_downtime(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 10, lengthDays: 6);

        $bare = $this->intel()->read($vehicle->id)['downtime_days'];
        $this->assertEqualsWithDelta(6.0, $bare, 0.05);

        Contract::create([
            'contract_no'   => 'T-C-'.uniqid(),
            'contract_type' => 'C',
            'vehicle_id'    => $vehicle->id,
            'state'         => 'closed',
            'out_date'      => Carbon::today()->subDays(8)->toDateString(),
            'out_time'      => '09:00:00',
            'in_date'       => Carbon::today()->subDays(6)->toDateString(),
            'in_time'       => '09:00:00',
        ]);

        $this->assertEqualsWithDelta(4.0, $this->intel()->read($vehicle->id)['downtime_days'], 0.05,
            'the two rented days must not be counted as shop time');
    }

    public function test_a_car_never_rented_is_pending_and_is_never_graded(): void
    {
        $vehicle = $this->makeVehicle();          // no rental → no in-service anchor
        $this->stay($vehicle, startedDaysAgo: 5, lengthDays: 20);   // onboarding prep, not downtime

        $reading = $this->intel()->read($vehicle->id);

        $this->assertTrue($reading['pending_service']);
        $this->assertSame(GarageSeverity::NORMAL, $reading['severity']);
        $this->assertCount(0, $this->alerts());
    }

    /** A return recorded before the departure must not invent a visit or negative downtime. */
    public function test_a_malformed_stay_creates_neither_a_visit_nor_downtime(): void
    {
        $vehicle = $this->inServiceVehicle();

        Contract::create([
            'contract_no'   => 'T-U-'.uniqid(),
            'contract_type' => 'U',
            'vehicle_id'    => $vehicle->id,
            'state'         => 'closed',
            'out_date'      => Carbon::today()->subDays(5)->toDateString(),
            'in_date'       => Carbon::today()->subDays(9)->toDateString(),   // came back before it left
        ]);

        $reading = $this->intel()->read($vehicle->id);

        $this->assertSame(0, $reading['visits']);
        $this->assertSame(0.0, $reading['downtime_days']);
    }

    // ── Escalation and spam ────────────────────────────────────────────────────────────────────

    public function test_crossing_into_warning_alerts_the_admin_once(): void
    {
        Notification::fake();

        $vehicle = $this->inServiceVehicle();
        foreach ([4, 9, 14] as $ago) {
            $this->stay($vehicle, startedDaysAgo: $ago, lengthDays: 0);   // three separate same-day stays
        }

        $state = $this->intel()->evaluate($vehicle);

        $this->assertSame(3, $state->visits);
        $this->assertSame(GarageSeverity::WARNING, $state->visit_severity);
        Notification::assertSentTo($this->recipient, FleetAlert::class,
            fn (FleetAlert $n) => $n->payload['type'] === 'garage_visit_frequency'
                && $n->payload['meta']['attention_level'] === GarageSeverity::WARNING
                && str_contains($n->payload['body'], '3 times'));
    }

    /** THE SPAM TEST. Ten evaluations of an unchanged car produce exactly one alert. */
    public function test_repeated_evaluations_of_an_unchanged_car_do_not_spam_the_admin(): void
    {
        $vehicle = $this->inServiceVehicle();
        foreach ([4, 9, 14] as $ago) {
            $this->stay($vehicle, startedDaysAgo: $ago, lengthDays: 0);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->intel()->evaluate($vehicle);
        }

        $this->assertCount(1, $this->alerts('garage_visit_frequency'),
            'ten evaluations of the same condition is still one alert');
    }

    public function test_each_climb_alerts_and_each_plateau_is_silent(): void
    {
        $vehicle = $this->inServiceVehicle();

        // → warning (3 visits)
        foreach ([4, 9, 14] as $ago) {
            $this->stay($vehicle, startedDaysAgo: $ago, lengthDays: 0);
        }
        $this->intel()->evaluate($vehicle);
        $this->assertCount(1, $this->alerts('garage_visit_frequency'));

        // a 4th visit — still warning. Nothing new to say.
        $this->stay($vehicle, startedDaysAgo: 18, lengthDays: 0);
        $this->intel()->evaluate($vehicle);
        $this->assertCount(1, $this->alerts('garage_visit_frequency'), 'warning → warning must be silent');

        // a 5th — now high. That IS news.
        $this->stay($vehicle, startedDaysAgo: 21, lengthDays: 0);
        $this->intel()->evaluate($vehicle);
        $this->assertCount(2, $this->alerts('garage_visit_frequency'));
        $this->assertSame(
            GarageSeverity::HIGH,
            VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->value('visit_severity')
        );

        // a 6th — still high. Silent again.
        $this->stay($vehicle, startedDaysAgo: 24, lengthDays: 0);
        $this->intel()->evaluate($vehicle);
        $this->assertCount(2, $this->alerts('garage_visit_frequency'), 'high → high must be silent');
    }

    /** The two signals escalate independently — being told about downtime must not mute visits. */
    public function test_the_two_signals_are_announced_separately(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 8);    // downtime → high

        $this->intel()->evaluate($vehicle);
        $this->assertCount(1, $this->alerts('garage_downtime'));
        $this->assertCount(0, $this->alerts('garage_visit_frequency'));

        $this->stay($vehicle, startedDaysAgo: 5, lengthDays: 0);
        $this->stay($vehicle, startedDaysAgo: 3, lengthDays: 0);      // visits → 3, warning
        $this->intel()->evaluate($vehicle);

        $this->assertCount(1, $this->alerts('garage_visit_frequency'), 'the visit signal speaks for itself');
        $this->assertCount(1, $this->alerts('garage_downtime'), 'and the downtime signal stays silent at the same level');
    }

    /**
     * RECOVERY. Old visits age out of the window, the grade falls, and — crucially — the record falls
     * with it, so a later climb back to the same level is announced again rather than being silenced
     * forever by a remembered peak. No "resolved" notification is invented on the way down.
     */
    public function test_a_car_that_recovers_settles_quietly_and_can_alert_again_later(): void
    {
        $vehicle = $this->inServiceVehicle();

        $old = collect([4, 9, 14])->map(fn ($ago) => $this->stay($vehicle, startedDaysAgo: $ago, lengthDays: 0));
        $this->intel()->evaluate($vehicle);
        $this->assertCount(1, $this->alerts('garage_visit_frequency'));

        // Time passes: those three stays are now outside the 30-day window.
        $old->each(fn (Contract $c) => $c->forceFill([
            'out_date' => Carbon::parse($c->out_date)->subDays(45)->toDateString(),
            'in_date'  => Carbon::parse($c->in_date)->subDays(45)->toDateString(),
        ])->save());

        $state = $this->intel()->evaluate($vehicle);

        $this->assertSame(0, $state->visits);
        $this->assertSame(GarageSeverity::NORMAL, $state->visit_severity, 'the stored state must not go stale');
        $this->assertSame(GarageSeverity::NORMAL, $state->notified_visit_severity, 'the record drops with the grade');
        $this->assertCount(1, $this->alerts('garage_visit_frequency'), 'de-escalation raises nothing');

        // It deteriorates again — and is announced again.
        foreach ([2, 5, 8] as $ago) {
            $this->stay($vehicle, startedDaysAgo: $ago, lengthDays: 0);
        }
        $this->intel()->evaluate($vehicle);

        $this->assertCount(2, $this->alerts('garage_visit_frequency'), 'a relapse is news again');
    }

    public function test_the_alert_carries_the_context_an_admin_needs_to_act(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 9);

        $this->intel()->evaluate($vehicle);
        $meta = $this->alerts('garage_downtime')->first()->data['meta'];

        $this->assertSame($vehicle->id, $meta['vehicle_id']);
        $this->assertSame($vehicle->plate_no, $meta['plate']);
        $this->assertSame(30, $meta['window_days']);
        $this->assertSame(1, $meta['garage_visits']);
        $this->assertEqualsWithDelta(9.0, $meta['downtime_days'], 0.05);
        $this->assertNotNull($meta['downtime_pct']);
        $this->assertNotNull($meta['last_entry_at']);
        $this->assertSame(GarageSeverity::HIGH, $meta['attention_level']);
        $this->assertNotEmpty($meta['reasons']);
    }

    public function test_nobody_outside_the_configured_audience_is_told(): void
    {
        $bystander = User::create([
            'name' => 'Bystander', 'email' => 'by.'.uniqid().'@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);

        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 9);
        $this->intel()->evaluate($vehicle);

        $this->assertCount(1, $this->alerts('garage_downtime'));
        $this->assertSame(0, $bystander->fresh()->notifications()->count());
    }

    public function test_the_feature_switch_stops_everything(): void
    {
        config(['garage_intelligence.enabled' => false]);

        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 20);

        $this->assertNull($this->intel()->evaluate($vehicle));
        $this->assertNull(VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first());
        $this->assertCount(0, $this->alerts());
    }

    // ── Wiring: a real state change reaches the engine ─────────────────────────────────────────

    /**
     * THE INTEGRATION POINT. Nothing here calls the intelligence service. A maintenance contract is
     * opened and the vehicle is reconciled — the same call every workflow transition, contract
     * open/close and dispatch already makes — and the reading must appear and the alert must go out.
     * This is what proves the feature is wired into the existing maintenance system rather than
     * bolted alongside it.
     */
    public function test_reconciling_a_vehicle_after_a_garage_movement_evaluates_and_alerts(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 10);   // 10 days off the road → high

        $this->assertNull(VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first());

        app(OperationsService::class)->reconcileVehicleOperationalStatus($vehicle->fresh());

        $state = VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first();
        $this->assertNotNull($state, 'the reconcile must have evaluated the car');
        $this->assertSame(GarageSeverity::HIGH, $state->downtime_severity);
        $this->assertCount(1, $this->alerts('garage_downtime'));
    }

    /** …and a burst of transitions on the same car is still one alert. */
    public function test_a_burst_of_maintenance_activity_produces_one_alert_not_one_per_event(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 10);

        $ops = app(OperationsService::class);
        for ($i = 0; $i < 6; $i++) {
            $ops->reconcileVehicleOperationalStatus($vehicle->fresh());
        }

        $this->assertCount(1, $this->alerts('garage_downtime'));
    }

    public function test_muting_suppresses_the_realtime_hook_for_bulk_work(): void
    {
        GarageIntelligenceService::$muted = true;

        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 10);

        app(OperationsService::class)->reconcileVehicleOperationalStatus($vehicle->fresh());

        $this->assertNull(VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first());
        $this->assertCount(0, $this->alerts());
    }

    /** The sweep is the rolling-window safety net; it grades without re-announcing settled levels. */
    public function test_the_sweep_refreshes_state_without_re_announcing(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 10);

        $first = $this->intel()->sweep([$vehicle->id]);
        $this->assertSame(1, $first['notified']);

        $second = $this->intel()->sweep([$vehicle->id]);
        $this->assertSame(0, $second['notified'], 'a second sweep must add nothing');
        $this->assertSame(1, $second['attention']);
        $this->assertCount(1, $this->alerts('garage_downtime'));
    }

    public function test_the_silent_sweep_records_the_reading_without_telling_anyone(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 16);   // critical

        $this->intel()->sweep([$vehicle->id], notify: false);

        $state = VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first();
        $this->assertSame(GarageSeverity::CRITICAL, $state->downtime_severity);
        $this->assertCount(0, $this->alerts(), 'a backfill must not alert on history');
    }

    // ── The admin surface ──────────────────────────────────────────────────────────────────────

    public function test_the_vehicle_profile_endpoint_serves_the_reading(): void
    {
        $vehicle = $this->inServiceVehicle();
        $this->stay($vehicle, startedDaysAgo: 20, lengthDays: 9);
        $this->intel()->evaluate($vehicle, notify: false);

        $res = $this->getJson('/api/maintenance-tickets/vehicle/'.$vehicle->id);
        $res->assertOk();

        $g = $res->json('data.garage_intelligence');
        $this->assertSame(30, $g['window_days']);
        $this->assertSame(1, $g['visits']);
        $this->assertEqualsWithDelta(9.0, $g['downtime_days'], 0.05);
        $this->assertSame(GarageSeverity::HIGH, $g['severity']);
        $this->assertNotEmpty($g['reasons']);
    }
}
