<?php

namespace Tests\Foundation;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\PartPurchase;
use App\Models\Vendor;
use App\Services\MaintenanceInvoiceService;

/**
 * WHOEVER SUPPLIED THE PART IS WHO BILLS FOR IT.
 *
 * A ticket's cost arrives on two different documents, and the same part must never appear on both:
 *
 *     the supplier sold us the part  →  that supplier's parts invoice
 *     the garage supplied and fitted →  that garage's maintenance invoice
 *
 * PartInvoiceService has always enforced one direction — a garage-bought part is refused on a supplier's
 * parts invoice. The other direction was open: nothing stopped a supplier-bought part, or one bought from
 * a DIFFERENT garage, being keyed onto this garage's bill. The ticket was then charged for it twice, once
 * on each document, and both bills looked correct read on their own.
 *
 * These tests lock the missing half, plus the rule that makes it enforceable at all: a part billed in our
 * app must name the record it was billed FROM, so there is something to check it against.
 */
class PartBelongsOnTheSuppliersBillTest extends FoundationTestCase
{
    private MaintenanceInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(MaintenanceInvoiceService::class);
    }

    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'       => $this->makeVehicle()->id,
            'maintenance_type' => 'Breakdown',
            'status'           => 'pending',
            'date'             => now()->toDateString(),
            'findings'         => [['text' => 'Engine noise', 'source' => 'inspector']],
        ]);
    }

    private function purchase(Maintenance $ticket, array $overrides = []): PartPurchase
    {
        return PartPurchase::create(array_merge([
            'vehicle_id'      => $ticket->vehicle_id,
            'maintenance_id'  => $ticket->id,
            'part_name'       => 'Alternator',
            'purchase_source' => PartPurchase::SOURCE_GARAGE,
            'purchase_price'  => 500,
            'quantity'        => 1,
            'currency'        => 'AED',
        ], $overrides));
    }

    /** Bill one part line for a named garage (or in-house when no vendor is given). */
    private function bill(Maintenance $ticket, array $line, ?Vendor $garage = null): MaintenanceInvoice
    {
        return $this->invoices->create($ticket, [
            'is_internal' => $garage === null,
            'vendor_id'   => $garage?->id,
            'line_items'  => [array_merge([
                'kind'         => 'part',
                'finding_text' => 'Engine noise',
                'description'  => 'Alternator',
                'quantity'     => 1,
                'unit_price'   => 500,
            ], $line)],
        ], $this->admin);
    }

    /** The supplier will invoice for it. Putting it on the garage's bill charges the ticket twice. */
    public function test_a_supplier_bought_part_is_refused_on_a_garage_bill(): void
    {
        $ticket = $this->ticket();
        $garage = $this->makeVendor();
        $bought = $this->purchase($ticket, [
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'Al Noor Auto Parts',
        ]);

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessageMatches('/Al Noor Auto Parts/');

        $this->bill($ticket, ['part_source' => 'purchase', 'part_source_id' => $bought->id], $garage);
    }

    /** A part supplied by one garage does not belong on another garage's invoice. */
    public function test_a_part_from_another_garage_is_refused(): void
    {
        $ticket   = $this->ticket();
        $supplied = $this->makeVendor(['name' => '7 CYLINDER']);
        $billing  = $this->makeVendor(['name' => 'Abu Maroof']);

        $bought = $this->purchase($ticket, ['source_vendor_id' => $supplied->id, 'source_name' => '7 CYLINDER']);

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessageMatches('/7 CYLINDER/');

        $this->bill($ticket, ['part_source' => 'purchase', 'part_source_id' => $bought->id], $billing);
    }

    /** The garage that supplied it bills for it — that one is allowed, and records where it came from. */
    public function test_the_supplying_garage_may_bill_its_own_part(): void
    {
        $ticket = $this->ticket();
        $garage = $this->makeVendor(['name' => '7 CYLINDER']);
        $bought = $this->purchase($ticket, ['source_vendor_id' => $garage->id, 'source_name' => '7 CYLINDER']);

        $invoice = $this->bill($ticket, ['part_source' => 'purchase', 'part_source_id' => $bought->id], $garage);
        $line    = $invoice->lineItems()->where('kind', 'part')->firstOrFail();

        $this->assertSame('purchase', $line->part_source, 'the bill records what it was billed from');
        $this->assertSame($bought->id, (int) $line->part_source_id);
    }

    /**
     * A price with no record behind it is a number nobody can check. Keying a part our records have
     * never heard of is refused, and the message says what to do instead.
     */
    public function test_a_part_the_ticket_never_recorded_is_refused(): void
    {
        $ticket = $this->ticket();

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessageMatches('/not one of the parts recorded on this ticket/');

        $this->bill($ticket, ['description' => 'Alternator']);
    }

    /** Fitting a purchase already wrote its cost line. Billing it again would double it. */
    public function test_a_part_already_billed_when_fitted_is_refused(): void
    {
        $ticket = $this->ticket();
        $garage = $this->makeVendor();

        // A first bill, which is what fitting the part produces.
        $first  = $this->purchase($ticket, ['source_vendor_id' => $garage->id]);
        $billed = $this->bill($ticket, ['part_source' => 'purchase', 'part_source_id' => $first->id], $garage);
        $first->update(['maintenance_line_item_id' => $billed->lineItems()->where('kind', 'part')->firstOrFail()->id]);

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessageMatches('/twice/');

        // A SECOND bill from the same garage, charging the same purchase again.
        $this->bill($ticket, ['part_source' => 'purchase', 'part_source_id' => $first->id], $garage);
    }

    /**
     * The list the form offers rules each part against the garage being billed, so the biller is told
     * before they pick — not after they save. The reasons are CODES, not sentences.
     */
    public function test_the_form_is_told_which_parts_this_garage_may_bill(): void
    {
        $ticket   = $this->ticket();
        $garage   = $this->makeVendor(['name' => '7 CYLINDER']);
        $elsewhere = $this->makeVendor(['name' => 'Abu Maroof']);

        $mine     = $this->purchase($ticket, ['part_name' => 'Alternator', 'source_vendor_id' => $garage->id]);
        $supplier = $this->purchase($ticket, [
            'part_name'       => 'Brake pads',
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'Al Noor Auto Parts',
        ]);
        $other    = $this->purchase($ticket, ['part_name' => 'Radiator', 'source_vendor_id' => $elsewhere->id]);

        $res = $this->getJson("/api/maintenance-tickets/{$ticket->id}/billable-parts?vendor_id={$garage->id}")
            ->assertOk();

        $byId = collect($res->json('data.parts'))->keyBy('id');

        $this->assertTrue($byId[$mine->id]['billable_here']);
        $this->assertNull($byId[$mine->id]['block_code']);

        $this->assertFalse($byId[$supplier->id]['billable_here']);
        $this->assertSame('SUPPLIER_SOURCED', $byId[$supplier->id]['block_code']);
        $this->assertSame('Al Noor Auto Parts', $byId[$supplier->id]['block_params']['supplier']);

        $this->assertFalse($byId[$other->id]['billable_here']);
        $this->assertSame('OTHER_GARAGE', $byId[$other->id]['block_code']);
        $this->assertSame('Abu Maroof', $byId[$other->id]['block_params']['garage']);
    }

    /**
     * A bill that arrives from OUTSIDE cannot name our records — the garage typing into the public
     * portal has never seen them. Refusing it would make those bills unrecordable rather than honest.
     */
    public function test_a_bill_from_the_garage_portal_is_not_asked_to_name_our_records(): void
    {
        $ticket  = $this->ticket();
        $invoice = $this->bill($ticket, ['description' => 'Alternator', 'entry_source' => 'garage']);

        $this->assertSame(1, $invoice->lineItems()->where('kind', 'part')->count());
    }
}
