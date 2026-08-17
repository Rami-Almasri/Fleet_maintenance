<?php

namespace Tests\Foundation;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\OilChangeProjectionService;

/**
 * "SERVICE ON RETURN" IS A PROMISE, AND A PROMISE HAS TO PRODUCE SOMETHING.
 *
 * A car in that lane is one the projection expects to pass its oil point before it comes back. When it
 * returns over the limit, settleOnReturn mints the oil service ticket — that half always worked.
 *
 * When it returns UNDER the limit, nothing used to happen at all: no decision row, not due, so the sweep
 * returned early and the only trace that anyone had been watching the car was the /oil-projection counter
 * dropping by one. The car is still a few hundred km from its oil point and is about to go straight back
 * out to the next customer.
 *
 * So a flagged car now always produces something on return: the service ticket when the mileage says the
 * change is genuinely owed, and otherwise a card in /inspection-review carrying the real figures, for a
 * person to look at the car while it is physically in front of them.
 *
 * The fixture keeps the arithmetic readable — last service at 0 km, 7,000 km interval, 500 km grace:
 *   oil_limit   = 7,000     (bare)
 *   allowed_max = 7,500     (oil_limit + grace)
 * and the projection runs at the fixed business rate of 200 km/day.
 */
class OilServiceOnReturnTest extends FoundationTestCase
{
    private const INTERVAL = 7000;
    private const OUT_KM   = 6500;
    private const OIL_LIMIT = 7000;

    private function car(): Vehicle
    {
        return $this->makeVehicle([
            'status'                => 'rented',
            'odometer'              => self::OUT_KM,
            'last_service_odometer' => 0,
            'service_interval_km'   => self::INTERVAL,
        ]);
    }

    /**
     * A rental that went out `$daysAgo` days on `$days` days of hire and has just come back on
     * `$inKm`. Closed, because settlement only ever looks at returned cars.
     */
    private function returned(Vehicle $vehicle, int $daysAgo, int $days, int $inKm): Contract
    {
        return Contract::create([
            'contract_no'   => 'C-'.strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'closed',
            'vehicle_id'    => $vehicle->id,
            'out_date'      => now()->subDays($daysAgo)->toDateString(),
            'out_milage'    => self::OUT_KM,
            'days'          => $days,
            'in_date'       => now()->toDateString(),
            'in_milage'     => $inKm,
        ]);
    }

    private function service(): OilChangeProjectionService
    {
        return app(OilChangeProjectionService::class);
    }

    /** Every request sitting in the review queue for this car. */
    private function reviewCards(Vehicle $vehicle)
    {
        return Maintenance::where('vehicle_id', $vehicle->id)
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->get();
    }

    // ── The gap this closes ───────────────────────────────────────────────────────────────────────

    /**
     * Flagged for service on return, came back 200 km SHORT of its limit. No service ticket is owed —
     * the actual mileage decides that, and it still does — but the car does not vanish: a review card
     * says the oil is nearly due, with the real numbers on it.
     */
    public function test_a_flagged_car_back_inside_its_limit_raises_a_review_card(): void
    {
        $vehicle = $this->car();
        // out 3 days ago on a 4-day hire ⇒ expected now 7,100, one day left ⇒ return ~7,300.
        // 7,000 < 7,300 <= 7,500, so the projection promised "service on return".
        $contract = $this->returned($vehicle, 3, 4, 6800);

        $this->assertSame(
            OilChangeProjectionService::OIL_SERVICE_ON_RETURN,
            $this->service()->project($contract, now())['oil_status'],
            'precondition: this car was in the Service-on-return lane'
        );

        // Not due on the actual reading, so nothing is minted…
        $ticket = $this->service()->settleOnReturn($contract, $this->admin);
        $this->assertNull($ticket, 'the odometer decides — 6,800 km is short of the 7,000 km limit');

        // …but the promise still produced something a person can act on.
        $cards = $this->reviewCards($vehicle);
        $this->assertCount(1, $cards, 'exactly one card — not none, and not a pile');

        $card = $cards->first();
        $this->assertStringContainsString('Oil nearly due', (string) $card->customer_complaint);
        $this->assertStringContainsString('6,800', (string) $card->customer_complaint);
        $this->assertStringContainsString('7,000', (string) $card->customer_complaint);
        // The figures are on the ticket as DATA too, not only as a sentence.
        $this->assertSame('oil_projection', $card->trigger_detail['source']);
        $this->assertSame('oil_nearly_due_on_return', $card->trigger_detail['reason']);
        $this->assertSame(6800, $card->trigger_detail['actual_return_km']);
        $this->assertSame(self::OIL_LIMIT, $card->trigger_detail['oil_limit']);
        $this->assertSame(200, $card->trigger_detail['remaining_km']);
    }

    /** …and it is linked back to the settlement row, so the card is never an orphan. */
    public function test_the_card_is_linked_to_the_settled_decision(): void
    {
        $vehicle  = $this->car();
        $contract = $this->returned($vehicle, 3, 4, 6800);

        $this->service()->settleOnReturn($contract, $this->admin);

        $row = \App\Models\ContractOilDecision::where('contract_id', $contract->id)->firstOrFail();
        $this->assertNotNull($row->settled_at, 'the rental is settled either way');
        $this->assertNull($row->settled_ticket_id, 'no service ticket was owed');
        $this->assertSame($this->reviewCards($vehicle)->first()->id, $row->inspection_ticket_id);
    }

    /**
     * SOMEBODY IS TOLD. A card nobody is alerted to is a card nobody reads until they happen to open the
     * queue — and the point of catching this on return is that the car is about to go out again.
     *
     * Aimed at the oil Controllers, whose queue it landed in. Deliberately `info`: the car came back in
     * time, which is the system working, not an emergency.
     */
    public function test_the_controllers_are_notified_when_the_card_is_raised(): void
    {
        // On the live fleet these are Lin & Marwa, named in config. Pinned explicitly here so the test
        // asserts the DELIVERY, not whichever users happen to hold reminders.manage in the test schema.
        config(['maintenance.oil_projection.recipient_user_ids' => [$this->admin->id]]);

        $vehicle  = $this->car();
        $contract = $this->returned($vehicle, 3, 4, 6800);

        $this->service()->settleOnReturn($contract, $this->admin);

        $card = $this->reviewCards($vehicle)->first();
        $data = $this->admin->notifications()->get()
            ->pluck('data')
            ->first(fn ($d) => ($d['type'] ?? null) === 'oil_nearly_due');

        $this->assertNotNull($data, 'the people who own this queue are told');
        $this->assertSame('oil_nearly_due:'.$contract->id, $data['key']);
        $this->assertSame('info', $data['severity'], 'it came back in time — not an alarm');
        $this->assertStringContainsString('6,800', (string) $data['body']);
        $this->assertStringContainsString('200 km left', (string) $data['body']);
        // Deep-links to the card itself, not to a queue they then have to search.
        $this->assertSame('/inspection-review?ticket='.$card->id, $data['url']);
    }

    // ── What must NOT change ──────────────────────────────────────────────────────────────────────

    /**
     * THE FLOOD GUARD. A car nobody promised anything about produces nothing on return. Without this
     * scope every returning rental in the fleet would file a card, which is how the review queue stopped
     * being believed the last time (143 rows for 30 real decisions).
     */
    public function test_an_unflagged_car_still_produces_nothing(): void
    {
        $vehicle = $this->car();
        // out 1 day ago on a 2-day hire ⇒ return ~6,900, under the 7,000 limit: within_tolerance.
        $contract = $this->returned($vehicle, 1, 2, 6700);

        $this->assertSame(
            OilChangeProjectionService::OIL_WITHIN_TOLERANCE,
            $this->service()->project($contract, now())['oil_status']
        );

        $this->assertNull($this->service()->settleOnReturn($contract, $this->admin));
        $this->assertCount(0, $this->reviewCards($vehicle), 'nothing was promised, so nothing is raised');
    }

    /**
     * A car that comes back OVER its limit still mints the service ticket and gets NO review card —
     * the change is owed, so it is a job, not a question. Two things for one car is what the whole
     * one-story rule exists to prevent.
     */
    public function test_a_car_back_over_its_limit_still_gets_the_service_ticket_only(): void
    {
        $vehicle  = $this->car();
        $contract = $this->returned($vehicle, 3, 4, 7300);

        $ticket = $this->service()->settleOnReturn($contract, $this->admin);

        $this->assertNotNull($ticket, 'the change is genuinely owed');
        $this->assertCount(0, $this->reviewCards($vehicle), 'a job, not a question');
    }

    /** Settlement stays idempotent: the sweep runs hourly and must not file a second card. */
    public function test_settling_twice_files_one_card(): void
    {
        $vehicle  = $this->car();
        $contract = $this->returned($vehicle, 3, 4, 6800);

        $this->service()->settleOnReturn($contract, $this->admin);
        $this->service()->settleOnReturn($contract->refresh(), $this->admin);

        $this->assertCount(1, $this->reviewCards($vehicle));
    }
}
