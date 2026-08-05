<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\ContractOilDecision;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NotificationScanner;
use App\Services\OilChangeProjectionService;
use App\Services\OperationsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Mid-rental oil-change projection.
 *
 * Every fixture below uses the same car so the arithmetic stays readable:
 *   last service at 0 km · 7,000 km interval · 500 km fleet grace  ⇒  limit = 7,500 km
 *   the car goes out on 6,500 km                                    ⇒  1,000 km of headroom
 * and the projection runs at the fixed business rate of 200 km/day.
 *
 * The four operating cases the fleet described are asserted directly in
 * test_release_decision_covers_the_four_operating_cases.
 */
class OilChangeProjectionTest extends CrudTestCase
{
    private const INTERVAL = 7000;
    private const OUT_KM   = 6500;
    private const LIMIT    = 7500;   // 0 + 7000 interval + 500 grace

    private function car(int $odometer = self::OUT_KM): int
    {
        $id = $this->makeVehicle();
        Vehicle::whereKey($id)->update([
            'status'                => 'rented',
            'odometer'              => $odometer,
            'last_service_odometer' => 0,
            'service_interval_km'   => self::INTERVAL,
        ]);

        return $id;
    }

    /** An open rental that went out `$daysAgo` days ago on `$outKm`. */
    private function rental(int $daysAgo, ?int $outKm = self::OUT_KM, ?int $vehicleId = null): Contract
    {
        return Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicleId ?? $this->car(),
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDays($daysAgo)->toDateString(),
            'out_milage'    => $outKm,
        ]);
    }

    private function detect(): Collection
    {
        return collect(app(NotificationScanner::class)->detect());
    }

    private function service(): OilChangeProjectionService
    {
        return app(OilChangeProjectionService::class);
    }

    // ── The pre-handover release decision ────────────────────────────────────────────────────

    public function test_release_decision_covers_the_four_operating_cases(): void
    {
        $vehicle = Vehicle::find($this->car());
        $decide  = fn (int $days) => $this->service()->releaseDecision($vehicle, $days)['decision'];

        // 1 day → 250 km, well inside the 1,000 km headroom.
        $this->assertSame('release', $decide(1));

        // 4 days → exactly 1,000 km. The grace is there to be used, so this still goes out.
        $this->assertSame('release', $decide(4));

        // 5 days → 1,250 km breaks the headroom, but a fresh change (7,500 km) covers the whole
        // trip, so we change it now while the car is in our hands.
        $this->assertSame('change_first', $decide(5));

        // 60 days → 15,000 km. Even a fresh change cannot cover that, so changing now buys
        // nothing: release it and let the daily projection chase a real reading mid-rental.
        $this->assertSame('release_and_chase', $decide(60));
    }

    public function test_release_decision_reports_no_data_without_a_sheet_anchor(): void
    {
        $id = $this->car();
        Vehicle::whereKey($id)->update(['last_service_odometer' => null, 'service_interval_km' => null]);

        $this->assertSame('no_data', $this->service()->releaseDecision(Vehicle::find($id), 5)['decision']);
    }

    // ── The daily projection ─────────────────────────────────────────────────────────────────

    public function test_car_inside_the_limit_is_not_chased(): void
    {
        $p = $this->service()->project($this->rental(3));

        // 6,500 + 3 × 200 = 7,100, still under the 7,500 limit.
        $this->assertSame('ok', $p['status']);
        $this->assertSame(7100, $p['expected']);
        $this->assertSame(self::LIMIT, $p['threshold']);
        $this->assertSame(400, $p['km_to_threshold']);
        // 1,000 km of headroom at 200 km/day ⇒ the projection crosses on day 5.
        $this->assertSame(now()->subDays(3)->addDays(5)->toDateString(), $p['breach_on']);
    }

    public function test_projection_crossing_the_limit_raises_a_chase(): void
    {
        $contract = $this->rental(5);

        $p = $this->service()->project($contract);
        $this->assertSame('chase_due', $p['status']);
        $this->assertSame(self::LIMIT, $p['expected']);   // 6,500 + 5 × 200
        $this->assertSame('oil_projection:' . $contract->id . ':start', $p['key']);

        $hit = $this->detect()->firstWhere('key', $p['key']);
        $this->assertNotNull($hit, 'the chase should surface as an alert');
        $this->assertSame('oil_projection', $hit['type']);
        $this->assertSame($contract->id, $hit['meta']['contract_id']);
    }

    public function test_a_placeholder_handover_reading_predicts_nothing(): void
    {
        // 0 and 1 are the branch's "didn't record it" sentinels. We refuse to guess rather than
        // fall back to the car's stale odometer.
        $p = $this->service()->project($this->rental(30, 1));

        $this->assertSame('no_data', $p['status']);
        $this->assertNull($p['expected']);
        $this->assertNull($p['key']);
        $this->assertSame(self::LIMIT, $p['threshold']); // the limit is still known — only the anchor is missing
    }

    // ── The reported reading: re-anchor and re-arm ───────────────────────────────────────────

    public function test_reported_reading_re_anchors_the_projection_and_rotates_the_key(): void
    {
        $contract = $this->rental(5);
        $this->assertSame('chase_due', $this->service()->project($contract)['status']);

        // The customer says 7,000 km — they have driven less than we assumed.
        $res = $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => 7000,
            'reported_by' => 'Customer (phone)',
        ]);
        $res->assertSuccessful();

        $p = data_get($res->json(), 'data.projection');
        $this->assertSame('ok', $p['status']);
        $this->assertSame(7000, $p['expected']);      // anchored today, so 0 days elapsed
        $this->assertSame('reading', $p['anchor_source']);
        $this->assertSame(0, $p['days_elapsed']);
        // 500 km left at 200 km/day ⇒ the next chase lands 3 days out.
        $this->assertSame(now()->addDays(3)->toDateString(), $p['breach_on']);

        // The key rotated off ':start' — that is what re-arms the alert for the next round.
        $this->assertNotSame('oil_projection:' . $contract->id . ':start', $p['key']);
        $this->assertStringStartsWith('oil_projection:' . $contract->id . ':r', $p['key']);

        // And the old condition is gone, so the previous alert auto-resolves.
        $this->assertNull($this->detect()->firstWhere('key', 'oil_projection:' . $contract->id . ':start'));
    }

    public function test_a_reading_that_runs_backwards_is_rejected(): void
    {
        $contract = $this->rental(5);

        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', ['odometer' => 6000])
            ->assertStatus(422);
    }

    public function test_a_reading_never_touches_the_vehicle_odometer(): void
    {
        $contract = $this->rental(5);
        $before   = Vehicle::find($contract->vehicle_id)->odometer;

        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', ['odometer' => 9000])
            ->assertSuccessful();

        // Customer-reported mileage is hearsay: it anchors the projection and nothing else.
        $this->assertSame($before, Vehicle::find($contract->vehicle_id)->odometer);
    }

    // ── Alert hygiene ────────────────────────────────────────────────────────────────────────

    public function test_the_chase_is_raised_once_per_anchor(): void
    {
        // Name the recipient explicitly: the deployed .env points this alert at the real controllers,
        // and a test that silently depends on who happens to be listed there is a test that breaks
        // the day someone edits it.
        config(['maintenance.oil_projection.recipient_user_ids' => [$this->admin->id]]);

        $contract = $this->rental(5);
        $scanner  = app(NotificationScanner::class);

        $scanner->scan();
        $scanner->scan();   // the condition still holds — this must NOT nag again

        $held = $this->admin->notifications()
            ->where('data->key', 'oil_projection:' . $contract->id . ':start')
            ->count();

        $this->assertSame(1, $held);
    }

    /**
     * THE WHOLE LOOP, end to end — the thing every other test here only covers a slice of:
     *
     *   car goes out → projection drifts past the oil limit → ONLY the controllers are asked →
     *   one of them enters the mileage the customer reported → the anchor moves and the answer
     *   changes → days pass → the projection crosses again and the chase RE-ARMS under a new key.
     *
     * The re-arm is the part that silently breaks: the alert is deduped by anchor, so if entering a
     * reading did not rotate the key the car would go quiet forever after the first call.
     */
    public function test_the_full_loop_chases_re_anchors_and_re_arms(): void
    {
        $lin       = $this->controller('Lin');
        $marwa     = $this->controller('Marwa');
        $bystander = $this->controller('Holds the permission but is not on the list');
        config(['maintenance.oil_projection.recipient_user_ids' => [$lin->id, $marwa->id]]);

        Carbon::setTestNow(Carbon::parse('2026-08-04 09:00:00'));

        // ── 1. Out 5 days on 6,500 km ⇒ projected 7,500 against a 7,500 limit: time to call.
        $contract  = $this->rental(5);
        $startKey  = 'oil_projection:' . $contract->id . ':start';
        $scanner   = app(NotificationScanner::class);
        $scanner->scan();

        $this->assertSame(1, $this->alertCount($lin, $startKey), 'Lin should be asked');
        $this->assertSame(1, $this->alertCount($marwa, $startKey), 'Marwa should be asked');
        // The allow-list REPLACES the permission gate — holding `reminders.manage` is not enough.
        $this->assertSame(0, $this->alertCount($bystander, $startKey));
        $this->assertSame(0, $this->alertCount($this->admin, $startKey));

        // ── 2. Marwa calls; the customer has driven less than we assumed (7,000 km, not 7,500).
        $res = $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => 7000,
            'reported_by' => 'Customer (phone)',
        ]);
        $res->assertSuccessful();

        $p = data_get($res->json(), 'data.projection');
        $this->assertSame('ok', $p['status']);              // back inside the limit
        $this->assertSame('reading', $p['anchor_source']);  // anchored on the real number now
        $this->assertSame('2026-08-07', $p['breach_on']);   // 500 km left at 200 km/day ⇒ 3 days
        $readingKey = $p['key'];
        $this->assertNotSame($startKey, $readingKey);

        // The condition has cleared, so the original ask auto-resolves instead of nagging forever.
        $scanner->scan();
        $this->assertTrue($this->alertResolved($marwa, $startKey));

        // ── 3. Three days on, the projection crosses again — and the chase is free to fire, because
        //       the reading rotated the dedup key. This is the re-arm.
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));
        $scanner->scan();

        $this->assertSame(1, $this->alertCount($lin, $readingKey));
        $this->assertSame(1, $this->alertCount($marwa, $readingKey));
        // And still nobody else.
        $this->assertSame(0, $this->alertCount($bystander, $readingKey));

        Carbon::setTestNow();
    }

    /** A controller who holds the permission — membership of the allow-list is set per test. */
    private function controller(string $name): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => 'ctl.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $user->givePermissionTo('reminders.manage');

        return $user;
    }

    private function alertCount(User $user, string $key): int
    {
        return $user->notifications()->where('data->key', $key)->count();
    }

    private function alertResolved(User $user, string $key): bool
    {
        $row = $user->notifications()->where('data->key', $key)->first();

        return (bool) ($row?->data['resolved'] ?? false);
    }

    public function test_the_board_lists_open_rentals_with_their_projection(): void
    {
        $contract = $this->rental(5);

        $res = $this->getJson('/api/OilProjection');
        $res->assertSuccessful();

        $data = data_get($res->json(), 'data');
        $this->assertSame(200, $data['model']['rate_km_per_day']);
        $this->assertSame(500, $data['model']['grace_km']);
        $this->assertGreaterThanOrEqual(1, $data['summary']['chase_due']);

        $row = collect($data['contracts'])->firstWhere('contract_id', $contract->id);
        $this->assertNotNull($row);
        $this->assertSame('chase_due', $row['projection']['status']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  THE RETURN-WINDOW DECISION
    //
    //  Reaching the oil limit is not an emergency. The question is whether the car can FINISH the
    //  rental inside the tolerance. These fixtures use the fleet's own worked example so the
    //  arithmetic in each test reads exactly like the rule:
    //
    //      oil limit   7,500 km   (last service 0 + 7,500 interval)
    //      tolerance     500 km
    //      allowed max 8,000 km
    // ═════════════════════════════════════════════════════════════════════════════════════════

    private const OIL_LIMIT   = 7500;
    private const ALLOWED_MAX = 8000;

    /** A car whose oil limit is exactly 7,500 km, so `allowed max` is exactly 8,000 km. */
    private function carAtLimit7500(): int
    {
        $id = $this->makeVehicle();
        Vehicle::whereKey($id)->update([
            'status'                => 'rented',
            'odometer'              => 7000,
            'last_service_odometer' => 0,
            'service_interval_km'   => self::OIL_LIMIT,
        ]);

        return $id;
    }

    /**
     * An open rental on that car with `$remainingDays` still to run, already out for a day so the
     * contract is realistic, and a customer reading of `$reading` km entered today.
     */
    private function rentalWithReading(int $remainingDays, int $reading): Contract
    {
        $contract = Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $this->carAtLimit7500(),
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDays(1)->toDateString(),
            'out_milage'    => 5000,
            // Contracted duration: one day already spent + the days still to run.
            'days'          => 1 + $remainingDays,
        ]);

        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => $reading,
            'reported_by' => 'Customer (phone)',
        ])->assertSuccessful();

        return $contract->fresh();
    }

    /**
     * CASE 1 — the case this whole change exists for.
     *
     * The car is already 100 km past its oil limit, which under the old reading of the rule looked
     * like an emergency. It is not: two days remain, so it lands on exactly 8,000 km — the last
     * kilometre it is allowed. Nobody is interrupted, nobody is asked to decide anything, and the
     * oil change is simply booked for the moment it comes back.
     */
    public function test_a_car_that_finishes_inside_the_tolerance_keeps_running(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 2, reading: 7600);

        $p = $this->service()->project($contract);

        $this->assertSame(self::OIL_LIMIT, $p['oil_limit']);
        $this->assertSame(500, $p['tolerance']);
        $this->assertSame(self::ALLOWED_MAX, $p['allowed_max']);
        $this->assertSame(2, $p['remaining_days']);
        $this->assertTrue($p['return_date_known']);

        // 7,600 + 2 × 200 = 8,000 — right on the allowance, and the allowance exists to be used.
        $this->assertSame(8000, $p['expected_return']);
        $this->assertSame(0, $p['over_tolerance_km']);
        $this->assertSame('service_required_on_return', $p['oil_status']);

        // Past the limit but inside the allowance is NOT a decision. Offering one would be the
        // noise this rule was written to remove, so the API refuses it outright.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertStatus(422);

        // And nobody is asked to choose anything.
        $this->assertNull($this->detect()->firstWhere('type', 'oil_decision'));
    }

    /**
     * CASE 2 — the same car, the same reading, five days left instead of two. Now it cannot finish
     * inside the allowance however you look at it, so a person has to answer for it.
     */
    public function test_a_car_that_cannot_finish_inside_the_tolerance_needs_a_decision(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);

        $p = $this->service()->project($contract);

        // 7,600 + 5 × 200 = 8,600 — 600 km past the 8,000 km allowance.
        $this->assertSame(8600, $p['expected_return']);
        $this->assertSame(600, $p['over_tolerance_km']);
        $this->assertSame('decision_required', $p['oil_status']);
        $this->assertNull($p['decision']);

        // It reaches the two people who make these calls, in the terms they make them in.
        $alert = $this->detect()->firstWhere('key', 'oil_decision:' . $contract->id . ':r' . $p['reading_id']);
        $this->assertNotNull($alert, 'a car that will bust the allowance must ask for a decision');
        $this->assertSame('oil_decision', $alert['type']);
        $this->assertSame(600, $alert['meta']['over_km']);
        $this->assertSame(5, $alert['meta']['remaining_days']);
        $this->assertStringContainsString('8,600 km', $alert['body']);
        $this->assertStringContainsString('8,000 km allowance', $alert['body']);

        // Two answers, and only two.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'wait_and_see'])
            ->assertStatus(422);
    }

    /** "Do it on return" — accept the overrun; the car keeps working and owes a change at close. */
    public function test_deferring_accepts_the_overrun_and_books_the_change_for_the_return(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);

        $res = $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'defer',
            'note'     => 'Customer is mid-trip; 600 km over is acceptable.',
        ]);
        $res->assertSuccessful();

        $p = data_get($res->json(), 'data.projection');
        $this->assertSame('service_required_on_return', $p['oil_status']);
        $this->assertSame('defer', $p['decision']['decision']);
        // The arithmetic it was taken against is snapshotted, so the call stays readable later.
        $this->assertSame(8600, $p['decision']['expected_return_odometer']);
        $this->assertSame(self::ALLOWED_MAX, $p['decision']['allowed_max']);

        // The car now carries the standing "owes maintenance" flag the rest of the fleet reads.
        $this->assertTrue((bool) Vehicle::find($contract->vehicle_id)->is_deferred_maintenance);

        // And the decision stops the nagging — it has been answered.
        $this->assertNull($this->detect()->firstWhere('type', 'oil_decision'));
    }

    /** "Recall now" — the overrun is too big to accept; the car has to come back. */
    public function test_recalling_marks_the_car_for_return(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $p = $this->service()->project($contract->fresh());
        $this->assertSame('recall_required', $p['oil_status']);
        $this->assertSame('recall', $p['decision']['decision']);
        $this->assertTrue((bool) Vehicle::find($contract->vehicle_id)->is_deferred_maintenance);
    }

    /**
     * CASE 3 — the reading is not just filed, it is ANSWERED.
     *
     * Leen or Marwa are on the phone. The moment the number goes in, the same response tells them
     * what it changed: this car was heading 600 km past its allowance and, on the real figure the
     * customer just read off the dash, it now finishes comfortably inside it. Nothing to decide.
     */
    public function test_a_new_reading_recalculates_the_decision_immediately(): void
    {
        // Out 4 days on 7,000 km with 4 days still to run. On the 200 km/day assumption the car is
        // already at 7,800 and heading for 8,600 — 600 km past what it is allowed.
        $contract = Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $this->carAtLimit7500(),
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDays(4)->toDateString(),
            'out_milage'    => 7000,
            'days'          => 8,
        ]);

        $before = $this->service()->project($contract);
        $this->assertSame(7800, $before['expected']);
        $this->assertSame(8600, $before['expected_return']);
        $this->assertSame('decision_required', $before['oil_status']);

        // Marwa phones. The customer has actually driven 50 km/day, not 200 — the model was wrong
        // about this car, which is exactly why we ask a human being instead of trusting the curve.
        $res = $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => 7200,
            'reported_by' => 'Customer (phone)',
        ]);
        $res->assertSuccessful();

        // The verdict comes back in the SAME response as the save — no second request, no reload.
        // She can tell the customer to carry on before she puts the phone down.
        $p = data_get($res->json(), 'data.projection');
        $this->assertSame(7200, $p['anchor_odometer']);
        $this->assertSame('reading', $p['anchor_source']);
        $this->assertSame(4, $p['remaining_days']);
        $this->assertSame(8000, $p['expected_return']);        // 7,200 + 4 × 200
        $this->assertSame(0, $p['over_tolerance_km']);         // right on the allowance
        $this->assertSame('service_required_on_return', $p['oil_status']);

        // …and the decision that was hanging over the car evaporates with it.
        $this->assertNull($this->detect()->firstWhere('type', 'oil_decision'));
    }

    /**
     * A `defer` is bound to the reading it was taken on. If the customer turns out to be driving
     * harder than the number that call was based on — hard enough to break the allowance again —
     * the decision does not quietly stand. It comes back to a human, exactly as the chase re-arms.
     */
    public function test_a_later_reading_that_re_breaks_the_allowance_re_opens_the_decision(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $this->assertSame('service_required_on_return', $this->service()->project($contract)['oil_status']);

        // Two days on, the customer reports a much bigger number than the defer assumed.
        $res = $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', ['odometer' => 9200]);
        $res->assertSuccessful();

        $p = data_get($res->json(), 'data.projection');
        $this->assertSame('decision_required', $p['oil_status']);
        $this->assertTrue($p['decision']['superseded']);
    }

    /**
     * CASE 4 — the promise at the end of every path: the car comes back, and the oil change it owes
     * becomes a real ticket while it is standing in the yard.
     */
    public function test_the_owed_oil_change_becomes_a_ticket_when_the_car_returns(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        // The customer brings it back on 8,650 km.
        app(OperationsService::class)->closeOperation($contract, ['in_milage' => 8650]);

        $ticket = Maintenance::where('vehicle_id', $contract->vehicle_id)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->latest('id')->first();

        $this->assertNotNull($ticket, 'the returned car must arrive with its oil change already raised');
        $this->assertSame(Maintenance::TYPE_ROUTINE, $ticket->maintenance_type);
        $this->assertSame(8650, $ticket->intake_odometer);       // the branch's real return reading
        $this->assertStringContainsString('Oil Change', json_encode($ticket->findings));

        // The debt is now a ticket, so the standing flag is cleared — no double-chasing.
        $this->assertFalse((bool) Vehicle::find($contract->vehicle_id)->is_deferred_maintenance);

        // The decision is closed out against the ticket it became.
        $decision = ContractOilDecision::where('contract_id', $contract->id)->latest('id')->first();
        $this->assertNotNull($decision->settled_at);
        $this->assertSame($ticket->id, $decision->settled_ticket_id);

        // The sweep that catches sync-closed contracts must not mint a SECOND ticket for this one.
        $this->assertSame(0, $this->service()->settleReturnedRentals()['settled']);
    }

    /**
     * The quiet majority: nobody was ever asked, because the car was always going to finish inside
     * the allowance. It still comes back owing an oil change, and the sweep still raises it — this
     * is the path for the ~all contracts that OfficeManager closes behind our back.
     */
    public function test_the_sweep_raises_the_change_for_a_car_that_simply_came_back_past_its_limit(): void
    {
        config(['maintenance.oil_projection.recipient_user_ids' => [$this->admin->id]]);

        $contract = $this->rentalWithReading(remainingDays: 2, reading: 7600);
        $this->assertSame('service_required_on_return', $this->service()->project($contract)['oil_status']);

        // OfficeManager closes it: `in_date` appears, no application code runs.
        $contract->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 8000])->save();

        $result = $this->service()->settleReturnedRentals();
        $this->assertSame(1, $result['settled']);

        $decision = ContractOilDecision::where('contract_id', $contract->id)->latest('id')->first();
        $this->assertTrue($decision->is_auto, 'nobody decided this — it is recorded as an automatic settle');
        $this->assertNotNull($decision->settled_ticket_id);

        // Idempotent: a second sweep tick finds nothing left to do.
        $this->assertSame(0, $this->service()->settleReturnedRentals()['settled']);
    }

    /** A car that comes back well short of its oil point owes nothing, and nothing is raised. */
    public function test_a_car_that_returns_below_its_oil_point_owes_nothing(): void
    {
        config(['maintenance.oil_projection.recipient_user_ids' => [$this->admin->id]]);

        $contract = $this->rentalWithReading(remainingDays: 1, reading: 6000);
        $this->assertSame('within_tolerance', $this->service()->project($contract)['oil_status']);

        $contract->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 6200])->save();

        $this->assertSame(0, $this->service()->settleReturnedRentals()['settled']);
        $this->assertSame(0, ContractOilDecision::where('contract_id', $contract->id)->count());
    }

    /**
     * Recalling a customer's car is not a call anyone should make against a guess. When the newest
     * real number has aged back into a projection, we ask for a fresh reading instead of asking for
     * a decision — the board still states the position, but the alert chases the fact.
     */
    public function test_a_stale_projection_chases_a_reading_rather_than_forcing_a_decision(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $this->assertTrue($this->service()->project($contract)['decision_ready']);
        $this->assertNotNull($this->detect()->firstWhere('type', 'oil_decision'));

        // Four days later that reading is no longer a fact about today.
        Carbon::setTestNow(Carbon::now()->addDays(4));

        $p = $this->service()->project($contract->fresh());
        // The arithmetic has not changed its mind — the car really will bust its allowance…
        $this->assertSame('decision_required', $p['oil_status']);
        // …but it is no longer something a person can answer for, so we ask for the number instead.
        $this->assertFalse($p['decision_ready']);
        $this->assertNull($this->detect()->firstWhere('type', 'oil_decision'));
        $this->assertNotNull($this->detect()->firstWhere('type', 'oil_projection'));

        Carbon::setTestNow();
    }

    /**
     * THE NOISE GUARD. Every long rental is arithmetically certain to run past its allowance — a
     * 30-day hire cannot fit inside an oil interval however you slice it. If the board counted those
     * as decisions it would show most of the fleet as "Decision needed" every morning and be ignored
     * within a week. Until a real reading exists, such a car is a phone call, not a choice.
     */
    public function test_a_long_rental_on_a_handover_estimate_is_not_counted_as_a_decision(): void
    {
        // 30 days out, no customer reading — everything here rests on the 200 km/day assumption.
        $contract = Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $this->carAtLimit7500(),
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDays(3)->toDateString(),
            'out_milage'    => 7000,
            'days'          => 30,
        ]);

        $p = $this->service()->project($contract);
        $this->assertSame('decision_required', $p['oil_status']);
        $this->assertSame('handover', $p['anchor_source']);
        $this->assertFalse($p['decision_ready'], 'an estimate is never something to recall a customer on');

        // Nobody is asked to choose; they are asked for the number that would make a choice possible.
        $this->assertNull($this->detect()->firstWhere('type', 'oil_decision'));

        $data = data_get($this->getJson('/api/OilProjection')->json(), 'data');
        $this->assertSame(0, $data['summary']['decision_required']);
        $this->assertGreaterThanOrEqual(1, $data['summary']['awaiting_reading']);
    }

    /**
     * The go-live floor. A rental that came back before this flow existed is water under the bridge:
     * the car has already been through the yard, and raising an oil ticket for it now would hand the
     * workshop a pile of work for cars that have been and gone. A rental carrying an explicit
     * decision is settled whatever its date — that call was taken under this flow and is still owed.
     */
    public function test_the_sweep_does_not_backfill_returns_from_before_the_flow_existed(): void
    {
        config([
            'maintenance.oil_projection.recipient_user_ids' => [$this->admin->id],
            'maintenance.oil_projection.settle_from'        => now()->subDay()->toDateString(),
        ]);

        $old = $this->rentalWithReading(remainingDays: 2, reading: 7600);
        $old->forceFill([
            'state' => 'closed', 'in_date' => now()->subDays(4)->toDateString(), 'in_milage' => 8000,
        ])->save();

        $this->assertSame(0, $this->service()->settleReturnedRentals()['settled']);
        $this->assertSame(0, ContractOilDecision::where('contract_id', $old->id)->count());

        // But a decision taken under this flow is honoured no matter how long the car has been back.
        $decided = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $this->postJson('/api/Contract/' . $decided->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $decided->forceFill([
            'state' => 'closed', 'in_date' => now()->subDays(4)->toDateString(), 'in_milage' => 8600,
        ])->save();

        $this->assertSame(1, $this->service()->settleReturnedRentals()['settled']);
    }

    /** The board summarises the decision axis, not just the chase axis. */
    public function test_the_board_summarises_the_decision_states(): void
    {
        $decide = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $onReturn = $this->rentalWithReading(remainingDays: 2, reading: 7600);

        $data = data_get($this->getJson('/api/OilProjection')->json(), 'data');

        $this->assertGreaterThanOrEqual(1, $data['summary']['decision_required']);
        $this->assertGreaterThanOrEqual(1, $data['summary']['service_required_on_return']);
        $this->assertSame(500, $data['model']['tolerance_km']);

        $rows = collect($data['contracts']);
        $this->assertSame('decision_required', $rows->firstWhere('contract_id', $decide->id)['projection']['oil_status']);
        $this->assertSame('service_required_on_return', $rows->firstWhere('contract_id', $onReturn->id)['projection']['oil_status']);
    }
}
