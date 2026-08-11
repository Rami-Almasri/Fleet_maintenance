<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\ContractOilDecision;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\OilRecallTask;
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  "RECALL NOW" → A CALL TO MAKE
    //
    //  A recall is a CONVERSATION: phone the customer, agree a day to bring the car in. It is
    //  emphatically NOT a logistics dispatch — the fleet has no operational module for moving
    //  vehicles on this path yet, and the tests below pin that boundary so a later logistics lane
    //  is an addition rather than a rewrite.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** The two people who make these calls, named explicitly rather than inherited from .env. */
    private function oilControllers(): array
    {
        $lin   = $this->controller('Lin');
        $marwa = $this->controller('Marwa');
        config(['maintenance.oil_projection.recipient_user_ids' => [$lin->id, $marwa->id]]);

        return [$lin, $marwa];
    }

    /**
     * The task carries everything the caller has to say, frozen as of the decision — because the
     * projection keeps moving and somebody halfway through a phone call must not watch the figures
     * they are quoting change underneath them.
     */
    public function test_recalling_creates_a_call_task_holding_the_decision_audit(): void
    {
        [$lin] = $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall',
            'note'     => 'Customer is local; ask for Thursday.',
        ])->assertSuccessful();

        $task = OilRecallTask::where('contract_id', $contract->id)->firstOrFail();

        $this->assertSame(OilRecallTask::STATUS_OPEN, $task->status);
        $this->assertSame(OilRecallTask::REASON_OIL_TOLERANCE, $task->reason_code);

        // Vehicle · contract · the customer's own number · the limits · where it lands.
        $this->assertSame($contract->vehicle_id, $task->vehicle_id);
        $this->assertSame($contract->id, $task->contract_id);
        $this->assertSame(7600, $task->customer_reading);
        $this->assertSame(now()->toDateString(), $task->customer_reading_on->toDateString());
        $this->assertSame(self::OIL_LIMIT, $task->oil_limit);
        $this->assertSame(self::ALLOWED_MAX, $task->allowed_max);
        $this->assertSame(13600, $task->expected_return_odometer);   // 7,600 + 30 × 200
        $this->assertSame(5600, $task->overToleranceKm());
        $this->assertSame(30, $task->remaining_days);

        // Created by, and the moment the decision was taken.
        $this->assertSame($this->admin->id, $task->created_by);
        $this->assertSame($this->admin->name, $task->created_by_name);
        $this->assertNotNull($task->decided_at);
        $this->assertSame('Customer is local; ask for Thursday.', $task->note);

        // It hangs off the decision record rather than duplicating the judgement.
        $decision = ContractOilDecision::where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame($decision->id, $task->contract_oil_decision_id);
        $this->assertSame(ContractOilDecision::DECISION_RECALL, $decision->decision);
        $this->assertNotNull($lin);
    }

    /** It lands with the configured controllers — not broadcast to whoever holds the permission. */
    public function test_the_recall_task_is_assigned_to_the_configured_controllers(): void
    {
        [$lin, $marwa] = $this->oilControllers();
        $bystander = $this->controller('Holds the permission but is not on the list');

        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $task = OilRecallTask::where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame([$lin->id, $marwa->id], $task->assigned_user_ids);

        // …and both of them are actually told, while the bystander is not.
        $key = 'oil_recall_task:' . $task->id;
        $this->assertSame(1, $this->alertCount($lin, $key));
        $this->assertSame(1, $this->alertCount($marwa, $key));
        $this->assertSame(0, $this->alertCount($bystander, $key));

        // The alert says what the call is about, in the numbers it is about.
        $body = $lin->notifications()->where('data->key', $key)->first()->data['body'];
        $this->assertStringContainsString('13,600 km', $body);
        $this->assertStringContainsString('8,000 km allowance', $body);
    }

    /**
     * TEST B — THE SALES GATE (owner ruling 2026-08-11, narrowing the 2026-08-10 ruling): a recall
     * raises the Controllers' call and the inspection follow-up, and NOTHING ELSE. No driver hears
     * about it until somebody records that Sales agreed the return with the customer.
     *
     * This is the whole point of the gate, so it is asserted from the driver's side: a user holding
     * `logistics.claim` must have been told nothing at all.
     */
    public function test_recall_raises_no_driver_collection_until_sales_confirm(): void
    {
        $this->oilControllers();
        $driver = User::create([
            'name' => 'Pool Driver', 'email' => 'drv.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $driver->givePermissionTo('logistics.claim');

        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        // The follow-up request exists, referenced from the decision, carrying the source tag.
        $decision = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $this->assertNotNull($decision->inspection_ticket_id);
        $req = Maintenance::find($decision->inspection_ticket_id);
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $req->workflow_status);   // waits on /inspection-review
        $this->assertSame('oil_projection', $req->trigger_detail['source'] ?? null);
        $this->assertSame('IN', $req->event_status);   // a request, not a garage event

        // NO movement, and no driver told anything.
        $this->assertSame(0, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count(),
            'no collection may exist before Sales confirm the customer agreed');
        $this->assertSame(0, $driver->notifications()->count(), 'the driver pool must hear nothing yet');
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $decision->recallStage());

        // Sales confirm → NOW the collection exists, pooled, linked, honest about custody.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [
            'note' => 'Customer will hand it over Thursday.',
        ])->assertSuccessful();

        $move = LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->first();
        $this->assertNotNull($move, 'Sales OK must release the driver collection');
        $this->assertNull($move->assigned_to_id, 'pooled — nobody is auto-assigned');
        $this->assertSame($req->id, $move->maintenance_id, 'collection is linked to the follow-up request');
        $this->assertStringContainsString('Oil recall', (string) $move->notes);
        $this->assertStringContainsString('OIL CHANGE (required)', (string) $move->notes);
        $this->assertSame($move->id, $decision->fresh()->collection_task_id);
        $this->assertNotSame('in_transit', $contract->vehicle->fresh()->operational_status,
            'the customer holds the car until a driver actually collects it');

        // The pool was pinged once, through the EXISTING dispatch notification.
        $this->assertSame(1, $this->alertCount($driver, 'logistics_dispatch:' . $move->id));

        // Re-taking the same decision, and re-confirming, duplicate nothing.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])
            ->assertSuccessful();
        $this->assertSame(1, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(1, $this->alertCount($driver, 'logistics_dispatch:' . $move->id));

        // …and the re-affirmed decision inherits the relay instead of asking for Sales again.
        $latest = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $this->assertTrue($latest->isSalesConfirmed(), 'a re-affirmed recall keeps the confirmation it already had');
        $this->assertSame($move->id, $latest->collection_task_id);
    }

    /**
     * TEST A — "Do it on return", end to end: no retrieval, a waiting follow-up with LIVE figures,
     * and the return settles everything automatically — service ticket raised, follow-up closed.
     */
    public function test_defer_waits_for_the_return_and_settles_automatically(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 2, reading: 7700);   // return ~8,100 → 100 over

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        // No retrieval of any kind: the customer keeps the car.
        $this->assertSame(0, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());

        // The follow-up request is linked, and the resource serves LIVE figures from the decision.
        $decision = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $req = Maintenance::find($decision->inspection_ticket_id);
        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($req)->resolve()['oil_context'];
        $this->assertSame('defer', $ctx['decision']);
        $this->assertSame(100, $ctx['live']['over_allowance_km']);
        $this->assertSame(100, $ctx['at_decision']['over_allowance']);

        // The car comes back (as the OM sync would record it): the single settlement funnel runs.
        $contract->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 8100])->save();
        $serviceTicket = $this->service()->settleOnReturn($contract->fresh());

        $this->assertNotNull($serviceTicket, 'the owed oil change is raised without anyone re-filing it');
        $this->assertSame($serviceTicket->id, $decision->fresh()->settled_ticket_id);
        // The announcing request closes in favour of the real ticket — no stale queue entry.
        $this->assertSame(Maintenance::WF_CLOSED, $req->fresh()->workflow_status);
        $this->assertStringContainsString('Settled on return', (string) $req->fresh()->review_notes);

        // The EXECUTION ticket is linked to the story, never an orphan — and the current action is
        // stated, derived from the actual return mileage.
        $this->assertSame('oil_projection', $serviceTicket->fresh()->trigger_detail['source'] ?? null);
        $this->assertSame($req->id, $serviceTicket->fresh()->trigger_detail['inspection_request_id']);
        $this->assertSame(8100, $serviceTicket->fresh()->trigger_detail['actual_return_km']);
        $svcCtx = \App\Http\Resources\MaintenanceWorkflowResource::make($serviceTicket->fresh())->resolve()['oil_context'];
        $this->assertSame('oil_change_required', $svcCtx['current_action']);

        // The notification follows the CURRENT state — actual figures, not the projection.
        [$lin] = [User::where('name', 'Lin')->first()];
        $this->assertSame(1, $this->alertCount($lin, 'oil_settle:' . $contract->id));

        // The oil is ACTUALLY changed: the authoritative cycle restarts from the SERVICE odometer.
        $contract->vehicle->recordOilService(8100);
        $freshVehicle = $contract->vehicle->fresh();
        $this->assertSame(8100, (int) $freshVehicle->last_service_odometer);
        $this->assertSame(8100 + (int) $freshVehicle->service_interval_km, $this->service()->oilLimit($freshVehicle),
            'the next interval runs from the ACTUAL service odometer — the old projection no longer drives the car');

        // Closing the execution ticket completes the workflow (the close path may re-anchor the
        // service baseline again through the existing closed-loop writer — that is ITS job).
        $serviceTicket->forceFill(['workflow_status' => Maintenance::WF_CLOSED])->save();
        $svcCtx = \App\Http\Resources\MaintenanceWorkflowResource::make($serviceTicket->fresh())->resolve()['oil_context'];
        $this->assertSame('oil_service_completed', $svcCtx['current_action']);
    }

    /**
     * TESTS B/D — THE ACTUAL MILEAGE WINS. A car that comes back INSIDE its oil limit gets no
     * forced oil change, whatever the projection predicted at decision time: the decision settles
     * without a ticket, and the follow-up resolves as "inspection only".
     */
    public function test_return_inside_the_limit_forces_no_oil_change(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);   // projected 600 over

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();
        $decision = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $req = Maintenance::find($decision->inspection_ticket_id);

        // The customer barely drove: actual return 6,900 km — under the 7,000 km oil limit.
        $contract->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 6900])->save();
        $this->assertNull($this->service()->settleOnReturn($contract->fresh()), 'no ticket is minted for oil that is not due');

        // Settled WITHOUT a service ticket; the follow-up says why; nothing is left open anywhere.
        $decision->refresh();
        $this->assertNotNull($decision->settled_at);
        $this->assertNull($decision->settled_ticket_id);
        $this->assertSame(Maintenance::WF_CLOSED, $req->fresh()->workflow_status);
        $this->assertStringContainsString('oil NOT due', (string) $req->fresh()->review_notes);
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count(), 'the request is the only record — no oil ticket');

        // The current action reflects the proven truth, with the decision snapshot kept for audit.
        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($req->fresh())->resolve()['oil_context'];
        $this->assertSame('inspection_only', $ctx['current_action']);
        $this->assertSame(600, $ctx['at_decision']['over_allowance']);
        // And no "oil due" notification went out — the state never called for one.
        $lin = User::where('name', 'Lin')->first();
        $this->assertSame(0, $this->alertCount($lin, 'oil_settle:' . $contract->id));
    }

    /**
     * TEST C — the decision changes, both directions: ONE follow-up request throughout, the
     * collection appears on escalation and stands down on de-escalation. No duplicates, no
     * stranded driver tasks.
     */
    public function test_changing_the_decision_transitions_the_same_workflow(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);

        // DEFER first: follow-up exists, no retrieval.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $ticketId = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')
            ->value('inspection_ticket_id');
        $this->assertNotNull($ticketId);
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());

        // Escalate DEFER → RECALL: same request re-stamped, still no movement — the recall is
        // waiting on Sales, and a car nobody has agreed to collect has no collection.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();
        $latest = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $this->assertSame('recall', $latest->decision);
        $this->assertSame($ticketId, $latest->inspection_ticket_id, 'the SAME follow-up request is reused');
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame('recall', Maintenance::find($ticketId)->trigger_detail['decision']);

        // Sales confirm → the collection appears against the same request.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])
            ->assertSuccessful();
        $this->assertSame(1, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());

        // De-escalate RECALL → DEFER: collection cancelled, call cancelled, request stays.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());
        $this->assertSame('defer', Maintenance::find($ticketId)->trigger_detail['decision']);
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());
    }

    /**
     * TEST D — the actual mileage is authoritative over the projection: a newer reading (and an
     * OM-shortened rental) clears the overrun and the inspection side shows the NEW truth, with
     * the at-decision snapshot preserved as history — then a worse reading escalates the figures.
     */
    public function test_a_new_reading_rewrites_the_follow_up_figures_live(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);   // 600 over at decision

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $req = Maintenance::find(
            ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->value('inspection_ticket_id'),
        );

        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($req)->resolve()['oil_context'];
        $this->assertSame(600, $ctx['live']['over_allowance_km']);

        // A fresh reading arrives and OfficeManager shortens the rental to tomorrow:
        // 7,650 + 1 × 200 = 7,850 — inside the 8,000 km allowance. The overrun is GONE.
        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', ['odometer' => 7650])
            ->assertSuccessful();
        $contract->fresh()->forceFill(['days' => 2])->save();   // out yesterday + 2 days ⇒ due tomorrow

        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($req->fresh())->resolve()['oil_context'];
        $this->assertLessThanOrEqual(0, $ctx['live']['over_allowance_km'], 'the stale 600-over must not survive');
        $this->assertSame(600, $ctx['at_decision']['over_allowance'], 'the audit snapshot is preserved');

        // The opposite direction: a reading proving the car is far worse escalates the live figures.
        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', ['odometer' => 8900])
            ->assertSuccessful();
        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($req->fresh())->resolve()['oil_context'];
        $this->assertSame(8900 + 200 - 8000, $ctx['live']['over_allowance_km']);   // 1,100 over
    }

    /**
     * The gate. A car that will finish inside the allowance cannot be recalled, so no call is ever
     * raised for one — which is the whole point of the tolerance rule.
     */
    public function test_no_recall_task_exists_for_a_car_inside_the_allowance(): void
    {
        $this->oilControllers();

        // 7,600 with two days left lands on exactly 8,000 — the last kilometre it is allowed.
        $contract = $this->rentalWithReading(remainingDays: 2, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertStatus(422);

        $this->assertSame(0, OilRecallTask::where('contract_id', $contract->id)->count());
        $this->assertSame(0, ContractOilDecision::where('contract_id', $contract->id)->count());
    }

    /** "Do it on return" is not a task — the rental simply runs its course. */
    public function test_deferring_creates_no_call_task(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        $this->assertSame(0, OilRecallTask::where('contract_id', $contract->id)->count());
    }

    /** Changing your mind withdraws the call rather than leaving it sitting in the queue. */
    public function test_revising_a_recall_to_a_defer_withdraws_the_call(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        $task = OilRecallTask::where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame(OilRecallTask::STATUS_CANCELLED, $task->status);
        $this->assertStringContainsString('Revised to', $task->outcome_note);
        $this->assertSame('service_required_on_return', $this->service()->project($contract->fresh())['oil_status']);
    }

    /** The queue Lin and Marwa work, and the two moves they make on it. */
    public function test_the_recall_queue_is_listed_and_worked_through(): void
    {
        $this->oilControllers();
        $contract = $this->rentalWithReading(remainingDays: 30, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $data = data_get($this->getJson('/api/OilRecallTasks')->json(), 'data');
        $this->assertSame(1, $data['summary']['open']);

        $row = $data['tasks'][0];
        $this->assertSame('open', $row['status']);
        // Sales agree the return, which is what puts a collection in a driver's queue at all — so
        // the "car arrived by itself" assertion at the end has something real to stand down.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])
            ->assertSuccessful();
        $this->assertSame(1, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame('oil_tolerance_exceeded_before_return', $row['reason_code']);
        $this->assertSame(13600, $row['expected_return_odometer']);
        $this->assertSame(5600, $row['over_tolerance_km']);
        // A code, never an English sentence — the wording is built at the edge.
        $this->assertArrayNotHasKey('reason', $row);

        // Reached the customer…
        $this->patchJson('/api/OilRecallTasks/' . $row['id'], [
            'status' => 'contacted', 'outcome_note' => 'Bringing it in Thursday.',
        ])->assertSuccessful();

        $task = OilRecallTask::find($row['id']);
        $this->assertSame(OilRecallTask::STATUS_CONTACTED, $task->status);
        $this->assertSame($this->admin->id, $task->claimed_by);   // whoever moved it owns it
        $this->assertNull($task->completed_at);

        // …and the car came back, which closes the call and raises the oil change.
        app(OperationsService::class)->closeOperation($contract->fresh(), ['in_milage' => 13000]);

        $task->refresh();
        $this->assertSame(OilRecallTask::STATUS_DONE, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertStringContainsString('oil change raised as ticket', $task->outcome_note);

        // The collection the recall raised stood down the moment the car arrived by itself —
        // nothing is left open in a driver's queue for a car already in the yard.
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(
            LogisticsTask::STATUS_CANCELLED,
            LogisticsTask::where('vehicle_id', $contract->vehicle_id)->orderByDesc('id')->value('status'),
        );
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

    // ── Odometer evidence trail ──────────────────────────────────────────────────────────────
    // The projection publishes WHERE each number came from: the handover reading under its own
    // name, and the sheet's oil-service km classified against the anchor rather than silently
    // trusted (it has no reliable date, so it can never become an anchor itself).

    /** Scenario A: only the handover reading exists — it is the anchor AND says so. */
    public function test_handover_only_evidence_is_published_and_never_decision_ready(): void
    {
        $p = $this->service()->project($this->rental(58));

        $this->assertSame('handover', $p['anchor_source']);
        $this->assertSame(self::OUT_KM, $p['handover_odometer']);
        $this->assertSame(now()->subDays(58)->toDateString(), $p['handover_on']);
        $this->assertSame(self::OUT_KM + 58 * 200, $p['expected']);
        // Arithmetically far past its allowance, but the anchor is a stale handover figure — so
        // this is a phone call, never a decision.
        $this->assertSame('decision_required', $p['oil_status']);
        $this->assertFalse($p['decision_ready']);
        // Oil service (0 km) predates the anchor — nothing to flag.
        $this->assertNull($p['oil_service_state']);
    }

    /** Scenario B: an oil service the car could plausibly have reached = mid-rental service, not an error. */
    public function test_plausible_mid_rental_oil_service_is_flagged_as_informational(): void
    {
        $contract = $this->rental(5);   // anchor 6,500 · expected 7,500
        Vehicle::whereKey($contract->vehicle_id)->update(['last_service_odometer' => 7000]);

        $p = $this->service()->project($contract->fresh());

        $this->assertSame(7000, $p['oil_service_odometer']);
        $this->assertSame(500, $p['oil_service_ahead_km']);
        $this->assertSame(OilChangeProjectionService::SERVICE_MID_RENTAL, $p['oil_service_state']);
        // The anchor does NOT move: the service km has no date and cannot be projected from.
        $this->assertSame('handover', $p['anchor_source']);
        $this->assertSame(self::OUT_KM, $p['anchor_odometer']);
    }

    /** Scenario C: an oil service beyond anything the car could have reached = suspicious conflict. */
    public function test_implausible_oil_service_reading_is_flagged_suspicious_and_not_trusted(): void
    {
        $contract = $this->rental(5);   // anchor 6,500 · expected 7,500 · margin 500 ⇒ plausible ≤ 8,000
        Vehicle::whereKey($contract->vehicle_id)->update(['last_service_odometer' => 30390]);

        $p = $this->service()->project($contract->fresh());

        $this->assertSame(30390, $p['oil_service_odometer']);
        $this->assertSame(OilChangeProjectionService::SERVICE_SUSPICIOUS, $p['oil_service_state']);
        // Still not an anchor, still not decision-ready — the record is surfaced, not obeyed.
        $this->assertSame('handover', $p['anchor_source']);
        $this->assertFalse($p['decision_ready']);
    }

    /** The plausibility boundary itself: expected + margin is mid-rental; one km past it is suspicious. */
    public function test_service_plausibility_boundary_follows_the_configured_margin(): void
    {
        config(['maintenance.oil_projection.service_plausibility_margin_km' => 500]);
        $contract = $this->rental(5);   // expected 7,500 ⇒ boundary 8,000

        Vehicle::whereKey($contract->vehicle_id)->update(['last_service_odometer' => 8000]);
        $this->assertSame(
            OilChangeProjectionService::SERVICE_MID_RENTAL,
            $this->service()->project($contract->fresh())['oil_service_state'],
        );

        Vehicle::whereKey($contract->vehicle_id)->update(['last_service_odometer' => 8001]);
        $this->assertSame(
            OilChangeProjectionService::SERVICE_SUSPICIOUS,
            $this->service()->project($contract->fresh())['oil_service_state'],
        );
    }

    // ── The operational lane ─────────────────────────────────────────────────────────────────
    // One primary action per car. THE RULE THIS EXISTS FOR: a car due back TODAY is never routed
    // to "call the customer" just because its reading is stale — the customer is already bringing
    // it back; the action is to read the real odometer on arrival and service if due.

    /** An open rental that went out $daysAgo days ago on a contracted duration of $days days. */
    private function rentalWithDuration(int $daysAgo, int $days): Contract
    {
        return Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $this->car(),
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDays($daysAgo)->toDateString(),
            'out_milage'    => self::OUT_KM,
            'days'          => $days,
        ]);
    }

    /** Case 1: due back today + stale reading ⇒ SERVICE ON RETURN, never a customer call. */
    public function test_a_car_due_back_today_is_service_on_return_not_a_call(): void
    {
        $p = $this->service()->project($this->rentalWithDuration(daysAgo: 60, days: 60));

        $this->assertSame(0, $p['remaining_days']);
        $this->assertSame('handover', $p['anchor_source']);                     // reading is 60d stale
        $this->assertSame('decision_required', $p['oil_status']);               // axes stay authoritative
        $this->assertSame(OilChangeProjectionService::LANE_SERVICE_ON_RETURN, $p['lane']);
    }

    /** An OVERDUE rental (past its return date, still out) is also an arrival to catch, not a call. */
    public function test_an_overdue_rental_is_service_on_return(): void
    {
        $p = $this->service()->project($this->rentalWithDuration(daysAgo: 64, days: 60));

        $this->assertSame(0, $p['remaining_days']);
        $this->assertSame(OilChangeProjectionService::LANE_SERVICE_ON_RETURN, $p['lane']);
    }

    /**
     * THE NOISE GUARD. A car due back today with a FULL oil interval still in front of it has no
     * oil question — it must not be dragged into "Service on return" (or any human's lane) merely
     * because today happens to be its return date. Real case: a Maybach 7,089 km inside its grace,
     * ~33 days from its next change, shown as an arrival to catch.
     */
    public function test_a_car_due_today_with_oil_life_left_is_safe_not_an_arrival_to_catch(): void
    {
        // 2 days into a 2-day hire: due back today, projected 6,900 km against a 7,000 km oil limit.
        $p = $this->service()->project($this->rentalWithDuration(daysAgo: 2, days: 2));

        $this->assertSame(0, $p['remaining_days']);
        $this->assertSame('within_tolerance', $p['oil_status']);
        $this->assertSame(OilChangeProjectionService::LANE_SAFE, $p['lane']);
    }

    /** Case 2: due back tomorrow with a stale reading ⇒ CALL CUSTOMER. */
    public function test_a_stale_car_due_tomorrow_is_call_customer(): void
    {
        $p = $this->service()->project($this->rentalWithDuration(daysAgo: 60, days: 61));

        $this->assertSame(1, $p['remaining_days']);
        $this->assertSame(OilChangeProjectionService::LANE_CALL_CUSTOMER, $p['lane']);
    }

    /** Case 3: a fresh reading proving the allowance cannot survive the rental ⇒ ACTION REQUIRED. */
    public function test_a_fresh_over_allowance_reading_is_action_required(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $p = $this->service()->project($contract->fresh());

        $this->assertTrue($p['decision_ready']);
        $this->assertSame(OilChangeProjectionService::LANE_ACTION_REQUIRED, $p['lane']);
    }

    /** Case 6: a car well inside its interval ⇒ SAFE; a car with no anchor ⇒ NO_DATA, never safe. */
    public function test_safe_and_no_data_lanes(): void
    {
        // 1 day into a 2-day hire: return lands on 6,500 + 2×200 = 6,900, under the 7,000 bare limit.
        $p = $this->service()->project($this->rentalWithDuration(daysAgo: 1, days: 2));
        $this->assertSame('within_tolerance', $p['oil_status']);
        $this->assertSame(OilChangeProjectionService::LANE_SAFE, $p['lane']);

        $bare = $this->rental(3);
        $bare->forceFill(['out_milage' => null])->save();
        $this->assertSame(OilChangeProjectionService::LANE_NO_DATA, $this->service()->project($bare->fresh())['lane']);
    }

    /** Case 4/5: a car that has actually RETURNED leaves this board entirely — it is settled, not phoned. */
    public function test_a_returned_car_is_not_on_the_board_at_all(): void
    {
        $gone = $this->rentalWithDuration(daysAgo: 60, days: 60);
        $gone->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 20000])->save();

        $data = data_get($this->getJson('/api/OilProjection')->json(), 'data');
        $this->assertNull(collect($data['contracts'])->firstWhere('contract_id', $gone->id));
    }

    /**
     * A recall/defer decision also puts the car in front of the INSPECTOR: one Stage-0 test
     * request per contract, its note spelling out how far past expectation the car has run —
     * and a revised decision never files a second one.
     */
    public function test_deciding_files_one_test_request_with_the_overrun_note(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $ticket = Maintenance::where('vehicle_id', $contract->vehicle_id)
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->first();

        $this->assertNotNull($ticket, 'the decision should file a test request for the Inspector');
        $this->assertSame(Maintenance::SOURCE_CONTROLLER, $ticket->request_origin);
        // The note carries the figures the decision was made on: overrun, limit, landing point.
        $this->assertStringContainsString('recall now', (string) $ticket->customer_complaint);
        $this->assertStringContainsString('Moved more than expected', (string) $ticket->customer_complaint);
        $this->assertStringContainsString('600 km over the 8,000 km max', (string) $ticket->customer_complaint);
        $this->assertStringContainsString('return ~8,600 km', (string) $ticket->customer_complaint);
        $this->assertStringContainsString('7,600 km', (string) $ticket->customer_complaint); // latest reading

        // Revising the answer (recall → defer, the allowed direction) records a new decision but
        // files NO second request — the car already sits in the Inspector's queue.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)->count());
    }

    /** Scenario D: a fresh customer reading outranks everything and unlocks the decision. */
    public function test_fresh_customer_reading_becomes_the_anchor_and_unlocks_the_decision(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 5, reading: 7600);
        $p = $this->service()->project($contract->fresh());

        $this->assertSame('reading', $p['anchor_source']);
        $this->assertSame(7600, $p['anchor_odometer']);
        // The handover stays published alongside — renaming, not overwriting.
        $this->assertSame(5000, $p['handover_odometer']);   // rentalWithReading's out mileage
        $this->assertTrue($p['decision_ready']);
    }
}
