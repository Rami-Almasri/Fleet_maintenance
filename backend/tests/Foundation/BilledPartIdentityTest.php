<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
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

    /** Bill one line on a fresh invoice and hand back the stored row. */
    private function bill(array $line): MaintenanceLineItem
    {
        $ticket  = $this->ticket();
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

    /** The picker's id is a person's claim about what was fitted, and it is kept as one. */
    public function test_a_picked_part_is_stored_as_the_lines_identity(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        $line = $this->bill([
            'component_catalog_id' => $part->id,
            'description'          => 'Alternator',
        ]);

        $this->assertSame($part->id, $line->component_catalog_id);
        $this->assertSame('picked', $line->catalog_matched_by, 'a human chose it — stronger than any text match');
    }

    /** The part decides what kind of part it is. The symptom it was fitted for does not. */
    public function test_the_category_comes_from_the_part_not_from_the_fault(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->firstOrFail();

        $line = $this->bill([
            'component_catalog_id' => $part->id,
            'description'          => 'Alternator',
            // What a fault-derived category would have sent for "Engine noise".
            'category_key'         => 'engine',
        ]);

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

        $line = $this->bill(['description' => $part->name]);

        $this->assertSame($part->id, $line->component_catalog_id);
        $this->assertSame('name', $line->catalog_matched_by, 'the app matched this, and says so');
    }

    /** Wording that resolves to nothing stays unidentified — never guessed onto a plausible part. */
    public function test_unidentifiable_wording_is_left_unlinked(): void
    {
        $line = $this->bill([
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
