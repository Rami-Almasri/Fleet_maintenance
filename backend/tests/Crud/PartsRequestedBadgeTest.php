<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Services\PartWorkflowService;

/**
 * The workflow board's "Parts Requested" badge is a DERIVED read of the ticket's part requests — it is
 * not a workflow status and nothing is written because of it. These tests pin the one rule that decides
 * whether it shows: the car waits for DELIVERY, not for the fitting.
 *
 * A part stops counting the moment it LANDS (part_purchases.delivered_at), even though the request
 * itself stays `purchased` — delivery is not a request status. Marking it installed must clear it too.
 */
class PartsRequestedBadgeTest extends CrudTestCase
{
    /** An under-repair ticket with one fault and one part request that has been purchased, not delivered. */
    private function ticketAwaitingPart(): array
    {
        $vehicleId = $this->makeVehicle();

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
        ]);

        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicleId,
            'symptom'        => 'Grinding noise',
            'status'         => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);

        $request = PartRequest::create([
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $task->id,
            'vehicle_id'          => $vehicleId,
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_PURCHASED,
            'part_name'           => 'Brake Pads',
            'category_key'        => 'brakes',
            'quantity'            => 2,
            'reason'              => 'Worn brake pads',
        ]);

        $purchase = PartPurchase::create([
            'part_request_id'        => $request->id,
            'vehicle_id'             => $vehicleId,
            'part_name'              => 'Brake Pads',
            'category_key'           => 'brakes',
            'purchase_source'        => PartPurchase::SOURCE_SUPPLIER,
            'purchase_price'         => 300,
            'currency'               => 'AED',
            'quantity'               => 2,
            'purchased_at'           => now(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'source_name'            => 'ABC Parts',
        ]);

        return [$ticket, $request, $purchase];
    }

    /** The board card for a ticket, dug out of whichever lane column it landed in. */
    private function card(int $ticketId): array
    {
        $res = $this->getJson('/api/maintenance-tickets/board');
        $res->assertSuccessful();

        $card = collect(data_get($res->json(), 'data.columns', []))
            ->flatten(1)
            ->firstWhere('id', $ticketId);

        $this->assertNotNull($card, "Ticket {$ticketId} did not appear on the board.");

        return $card;
    }

    /** The part requests the badge counts — exactly what the card filters on. */
    private function outstanding(int $ticketId): array
    {
        return collect($this->card($ticketId)['parts'] ?? [])
            ->where('outstanding', true)
            ->values()
            ->all();
    }

    public function test_badge_shows_while_the_part_has_not_arrived(): void
    {
        [$ticket] = $this->ticketAwaitingPart();

        $outstanding = $this->outstanding($ticket->id);

        $this->assertCount(1, $outstanding, 'A purchased-but-undelivered part must still show the badge.');
        $this->assertSame('Brake Pads', $outstanding[0]['part_name']);
        $this->assertFalse($outstanding[0]['delivered']);
    }

    public function test_marking_delivered_clears_the_badge_even_though_the_request_stays_purchased(): void
    {
        [$ticket, $request, $purchase] = $this->ticketAwaitingPart();

        app(PartWorkflowService::class)->markDelivered($purchase, $this->admin);

        // Delivery is deliberately NOT a request status — this must stay `purchased`.
        $this->assertSame(PartRequest::STATUS_PURCHASED, $request->fresh()->status);

        $card = $this->card($ticket->id);
        $part = collect($card['parts'])->firstWhere('id', $request->id);

        $this->assertTrue($part['delivered'], 'The part landed, so it must read delivered.');
        $this->assertFalse($part['outstanding'], 'A delivered part must not keep the badge up.');
        $this->assertSame([], $this->outstanding($ticket->id));
    }

    public function test_installing_clears_the_badge(): void
    {
        [$ticket, $request, $purchase] = $this->ticketAwaitingPart();

        $purchase->forceFill(['installed_at' => now()])->save();
        $request->forceFill(['status' => PartRequest::STATUS_INSTALLED])->save();

        $this->assertSame([], $this->outstanding($ticket->id), 'An installed part must not keep the badge up.');
    }

    public function test_a_second_open_part_keeps_the_badge_up_after_the_first_is_delivered(): void
    {
        [$ticket, $request, $purchase] = $this->ticketAwaitingPart();

        $second = PartRequest::create([
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $request->maintenance_task_id,
            'vehicle_id'          => $ticket->vehicle_id,
            'source'              => PartRequest::SOURCE_GARAGE,
            'status'              => PartRequest::STATUS_REQUESTED,
            'part_name'           => 'Front Shock Absorber',
            'category_key'        => 'suspension',
            'quantity'            => 1,
            'reason'              => 'Leaking',
        ]);

        app(PartWorkflowService::class)->markDelivered($purchase, $this->admin);

        $outstanding = $this->outstanding($ticket->id);

        $this->assertCount(1, $outstanding, 'Only the still-missing part should be counted.');
        $this->assertSame($second->id, $outstanding[0]['id']);
        $this->assertSame('Front Shock Absorber', $outstanding[0]['part_name']);
    }

    public function test_the_ticket_never_leaves_its_lane_because_of_a_part(): void
    {
        [$ticket, , $purchase] = $this->ticketAwaitingPart();

        $before = $ticket->fresh()->workflow_status;
        app(PartWorkflowService::class)->markDelivered($purchase, $this->admin);

        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $before);
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->fresh()->workflow_status);
    }
}
