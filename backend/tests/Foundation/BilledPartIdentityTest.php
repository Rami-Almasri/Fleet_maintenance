<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Services\MaintenanceInvoiceService;

/**
 * A billed part line must say WHICH PART it is, and take its category from that part.
 *
 * Two failures this locks down, both of which looked fine on screen:
 *
 *   1. The part was a string. "Alternator", "دينامو" and "Alternator (reman)" billed the same part
 *      under three identities, so nothing could count what a part costs the fleet or how long it
 *      lasts. The picker fixed the entry surface; this fixes the record.
 *
 *   2. The category was taken from the FAULT the line is attributed to. A fault is not a part —
 *      an alternator fitted for "engine noise" is electrical, not engine — so spend filed itself
 *      under the wrong heading exactly when the repair was not the obvious one.
 */
class BilledPartIdentityTest extends FoundationTestCase
{
    private MaintenanceInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(MaintenanceInvoiceService::class);
    }

    /** A ticket carrying one diagnosed fault — Diagnosis-First refuses any line without one. */
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

    /**
     * Bill one line on a fresh invoice and hand back the stored row.
     *
     * WHICH SURFACE IS BILLING matters, because only one of them is required to name a part already
     * recorded on the ticket. A line keyed in our app must ({@see MaintenanceInvoiceService::
     * assertPartBillable}) — we hold the record, so billing a part we never recorded buying would put a
     * price on the invoice that nothing backs. A bill arriving from the public garage portal cannot:
     * the garage typing it has never seen our records. So these tests say which one they are.
     */
    private function bill(array $line, ?Maintenance $ticket = null): MaintenanceLineItem
    {
        $ticket  = $ticket ?: $this->ticket();
        $invoice = $this->invoices->create($ticket, [
            'is_internal' => true,
            'line_items'  => [array_merge([
                'kind'         => 'part',
                'finding_text' => 'Engine noise',
                'quantity'     => 1,
                'unit_price'   => 500,
            ], $line)],
        ], $this->admin);

        return $invoice->lineItems()->firstOrFail();
    }

    /** Our own invoice form: the part is billed FROM the purchase that recorded buying it. */
    private function billFromPurchase(array $line, ?ComponentCatalog $part = null): MaintenanceLineItem
    {
        $ticket = $this->ticket();

        $purchase = PartPurchase::create([
            'vehicle_id'      => $ticket->vehicle_id,
            'maintenance_id'  => $ticket->id,
            'part_name'       => $part?->name ?? ($line['description'] ?? 'Alternator'),
            'purchase_source' => PartPurchase::SOURCE_GARAGE,
            'purchase_price'  => 500,
            'quantity'        => 1,
            'currency'        => 'AED',
        ]);

        return $this->bill(array_merge([
            'part_source'    => 'purchase',
            'part_source_id' => $purchase->id,
        ], $line), $ticket);
    }

    /** The public garage portal: no login, no sight of our records, so its wording stands on its own. */
    private function billFromGaragePortal(array $line): MaintenanceLineItem
    {
        return $this->bill(array_merge(['entry_source' => 'garage'], $line));
    }

    /** The picker's id is a person's claim about what was fitted, and it is kept as one. */
    public function test_a_picked_part_is_stored_as_the_lines_identity(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        $line = $this->billFromPurchase([
            'component_catalog_id' => $part->id,
            'description'          => 'Alternator',
        ], $part);

        $this->assertSame($part->id, $line->component_catalog_id);
        $this->assertSame('picked', $line->catalog_matched_by, 'a human chose it — stronger than any text match');
    }

    /** The part decides what kind of part it is. The symptom it was fitted for does not. */
    public function test_the_category_comes_from_the_part_not_from_the_fault(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        $line = $this->billFromPurchase([
            'component_catalog_id' => $part->id,
            'description'          => 'Alternator',
            // What a fault-derived category would have sent for "Engine noise".
            'category_key'         => 'engine',
        ], $part);

        $this->assertSame($part->category_key, $line->category_key);
        $this->assertNotSame('engine', $line->category_key, 'the fault must not decide the part category');
    }

    /**
     * Surfaces without a picker still land on a real part: the public garage portal types its own
     * wording, and that wording is resolved strictly rather than kept as an unidentified string.
     */
    public function test_typed_wording_is_resolved_to_a_part(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        $line = $this->billFromGaragePortal(['description' => $part->name]);

        $this->assertSame($part->id, $line->component_catalog_id);
        $this->assertSame('name', $line->catalog_matched_by, 'the app matched this, and says so');
    }

    /** Wording that resolves to nothing stays unidentified — never guessed onto a plausible part. */
    public function test_unidentifiable_wording_is_left_unlinked(): void
    {
        $line = $this->billFromGaragePortal([
            'description'  => 'zzz nobody stocks this',
            'category_key' => 'engine',
        ]);

        $this->assertNull($line->component_catalog_id);
        $this->assertNull($line->catalog_matched_by);
        $this->assertSame('engine', $line->category_key, 'with no part to ask, the submitted category stands');
    }

    /** Labor is work, not a part — it never carries a part identity. */
    public function test_a_labor_line_carries_no_part(): void
    {
        $line = $this->bill([
            'kind'        => 'labor',
            'description' => 'Alternator',   // same wording; still not a part
        ]);

        $this->assertNull($line->component_catalog_id);
        $this->assertNull($line->catalog_matched_by);
    }
}
