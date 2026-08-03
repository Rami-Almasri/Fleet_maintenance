<?php

namespace Tests\Unit;

use App\Models\PartPurchase;
use App\Models\PartRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PartRequest::isOutstanding() is the SINGLE definition of "this car is waiting on a part". Seven
 * surfaces read it — the workflow board badge, the ops card, Car Status, the operations board, the
 * oversight KPI, MaintenanceDelayResolver and WorkflowStateResolver — so the rule is pinned here once.
 *
 * The rule: the wait ends at DELIVERY, not at the fitting. Delivery is NOT a request status (the
 * request stays `purchased` after markDelivered) — it is only ever part_purchases.delivered_at.
 *
 * In-memory, like WorkflowStateResolverTest: no database, relations set by hand.
 */
class PartRequestOutstandingTest extends TestCase
{
    private function purchase(?string $deliveredAt = null, ?string $installedAt = null): PartPurchase
    {
        return (new PartPurchase())->forceFill([
            'delivered_at' => $deliveredAt,
            'installed_at' => $installedAt,
        ]);
    }

    private function request(string $status, array $purchases = []): PartRequest
    {
        $request = (new PartRequest())->forceFill(['status' => $status]);
        $request->setRelation('purchases', collect($purchases));

        return $request;
    }

    /** Every pre-delivery stage still counts as waiting. */
    public static function openStatuses(): array
    {
        return [
            'requested'    => [PartRequest::STATUS_REQUESTED],
            'under_review' => [PartRequest::STATUS_UNDER_REVIEW],
            'approved'     => [PartRequest::STATUS_APPROVED],
            'purchased'    => [PartRequest::STATUS_PURCHASED],
        ];
    }

    #[DataProvider('openStatuses')]
    public function test_an_undelivered_request_is_outstanding(string $status): void
    {
        $this->assertTrue($this->request($status)->isOutstanding());
    }

    #[DataProvider('openStatuses')]
    public function test_delivery_ends_the_wait_at_every_open_stage(string $status): void
    {
        $request = $this->request($status, [$this->purchase(deliveredAt: '2026-08-03 09:00:00')]);

        $this->assertTrue($request->isOnSite());
        $this->assertFalse($request->isOutstanding(), "A delivered part must not read as waiting at stage {$status}.");
    }

    public function test_the_status_stays_purchased_after_delivery_so_status_alone_cannot_decide(): void
    {
        // This is the trap the old rule fell into: it only looked at status, which does not move on
        // delivery, so a landed part read as "waiting" forever.
        $delivered = $this->request(PartRequest::STATUS_PURCHASED, [$this->purchase(deliveredAt: '2026-08-03 09:00:00')]);

        $this->assertSame(PartRequest::STATUS_PURCHASED, $delivered->status);
        $this->assertFalse($delivered->isOutstanding());
        $this->assertNotContains(PartRequest::STATUS_PURCHASED, PartRequest::SETTLED);
    }

    public function test_installation_also_ends_the_wait(): void
    {
        $viaTimestamp = $this->request(PartRequest::STATUS_PURCHASED, [$this->purchase(installedAt: '2026-08-03 11:00:00')]);
        $viaStatus    = $this->request(PartRequest::STATUS_INSTALLED);

        $this->assertFalse($viaTimestamp->isOutstanding());
        $this->assertFalse($viaStatus->isOutstanding());
    }

    public function test_dropped_requests_never_wait(): void
    {
        $this->assertFalse($this->request(PartRequest::STATUS_REJECTED)->isOutstanding());
        $this->assertFalse($this->request(PartRequest::STATUS_CANCELLED)->isOutstanding());
        $this->assertFalse($this->request(PartRequest::STATUS_COMPLETED)->isOutstanding());
    }

    public function test_an_undelivered_purchase_does_not_end_the_wait(): void
    {
        $ordered = $this->request(PartRequest::STATUS_PURCHASED, [$this->purchase()]);

        $this->assertFalse($ordered->isOnSite());
        $this->assertTrue($ordered->isOutstanding(), 'Ordering is not arriving — the car is still waiting.');
    }

    public function test_one_delivered_line_among_several_ends_the_wait(): void
    {
        // Part re-ordered after a wrong first shipment: any purchase landing means the part is on site.
        $request = $this->request(PartRequest::STATUS_PURCHASED, [
            $this->purchase(),
            $this->purchase(deliveredAt: '2026-08-03 12:00:00'),
        ]);

        $this->assertFalse($request->isOutstanding());
    }

    public function test_settled_is_wider_than_terminal_and_that_difference_is_deliberate(): void
    {
        // TERMINAL (the OLD rule) omits `installed`, which is why the old surfaces kept saying "waiting"
        // after a part was fitted. SETTLED closes that.
        $this->assertContains(PartRequest::STATUS_INSTALLED, PartRequest::SETTLED);
        $this->assertNotContains(PartRequest::STATUS_INSTALLED, PartRequest::TERMINAL);
    }
}
