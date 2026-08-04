<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NotificationScanner;
use App\Services\OilChangeProjectionService;
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
}
