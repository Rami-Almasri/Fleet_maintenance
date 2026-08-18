<?php

namespace Tests\Foundation;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Services\ContractRepairInvoiceService;
use App\Services\MaintenanceInvoiceService;

/**
 * EVERY BILL RAISED AGAINST A CONTRACT, READ BACK FROM THE CONTRACT.
 *
 * A workshop visit is billed by more than one party — the garage for the work, the supplier for the
 * parts — and each bill only ever answered to its own ticket. So the contract that paid for the visit
 * could show a total with no paper behind it, and "what were we billed for this contract?" meant opening
 * every ticket in turn and adding up by hand.
 *
 * The two things worth locking down here are the ones that are easy to get quietly wrong:
 *
 *   1. A repair billed DURING A RENTAL belongs to that rental contract too, not only to the workshop
 *      contract the visit opened. Both links count.
 *   2. A supplier invoice can cover several cars at once. Counting its FULL total against this contract
 *      would charge it for another car's parts, so only this contract's share is counted.
 */
class ContractShowsEveryInvoiceTest extends FoundationTestCase
{
    private ContractRepairInvoiceService $repairs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repairs = app(ContractRepairInvoiceService::class);
    }

    private function contract(array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'contract_no'   => 'C-'.uniqid(),
            'contract_type' => 'C',
            'state'         => 'open',
        ], $overrides));
    }

    private function ticket(array $overrides = []): Maintenance
    {
        return Maintenance::create(array_merge([
            'vehicle_id'       => $this->makeVehicle()->id,
            'maintenance_type' => 'Breakdown',
            'status'           => 'pending',
            'date'             => now()->toDateString(),
            'findings'         => [['text' => 'Engine noise', 'source' => 'inspector']],
        ], $overrides));
    }

    /** A repair billed while the car was out on rent is that rental's cost too. */
    public function test_a_repair_billed_during_a_rental_shows_on_the_rental_contract(): void
    {
        $rental = $this->contract();
        $garage = $this->makeVendor(['name' => '7 CYLINDER']);
        $ticket = $this->ticket(['linked_contract_id' => $rental->id]);

        $purchase = PartPurchase::create([
            'vehicle_id'       => $ticket->vehicle_id,
            'maintenance_id'   => $ticket->id,
            'part_name'        => 'Alternator',
            'purchase_source'  => PartPurchase::SOURCE_GARAGE,
            'source_vendor_id' => $garage->id,
            'purchase_price'   => 500,
            'quantity'         => 1,
            'currency'         => 'AED',
        ]);

        app(MaintenanceInvoiceService::class)->create($ticket, [
            'vendor_id'  => $garage->id,
            'invoice_no' => 'INV-2043',
            'line_items' => [[
                'kind'           => 'part',
                'finding_text'   => 'Engine noise',
                'description'    => 'Alternator',
                'quantity'       => 1,
                'unit_price'     => 500,
                'part_source'    => 'purchase',
                'part_source_id' => $purchase->id,
            ]],
        ], $this->admin);

        $out = $this->repairs->forContract($rental);

        $this->assertCount(1, $out['garage_invoices']);
        $this->assertSame('INV-2043', $out['garage_invoices'][0]['invoice_no']);
        $this->assertSame('7 CYLINDER', $out['garage_invoices'][0]['vendor_name']);
        $this->assertSame(500.0, $out['totals']['garage']);
    }

    /**
     * A supplier bill covering two cars contributes only the part that is THIS contract's. Charging the
     * contract the whole invoice would bill it for someone else's car.
     */
    public function test_only_this_contracts_share_of_a_shared_supplier_invoice_is_counted(): void
    {
        $contract = $this->contract();
        $mine     = $this->ticket(['contract_id' => $contract->id]);
        $theirs   = $this->ticket();   // another car entirely, on no contract of ours

        $invoice = PartInvoice::create([
            'supplier_name' => 'Al Noor Auto Parts',
            'invoice_no'    => 'SUP-991',
            'currency'      => 'AED',
        ]);

        foreach ([[$mine, 300], [$theirs, 700]] as [$ticket, $price]) {
            $purchase = PartPurchase::create([
                'vehicle_id'      => $ticket->vehicle_id,
                'maintenance_id'  => $ticket->id,
                'part_name'       => 'Brake pads',
                'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
                'purchase_price'  => $price,
                'quantity'        => 1,
                'currency'        => 'AED',
            ]);
            // Attached the way PartInvoiceService::syncPurchases attaches it — part_invoice_id is not
            // fillable, because keying a purchase and billing it are two separate acts.
            PartPurchase::where('id', $purchase->id)->update(['part_invoice_id' => $invoice->id]);
        }

        $out = $this->repairs->forContract($contract);

        $this->assertCount(1, $out['supplier_invoices']);
        $row = $out['supplier_invoices'][0];

        $this->assertSame(300.0, $row['contract_share'], 'only this contract’s part counts');
        $this->assertTrue($row['is_shared'], 'the invoice covers another car too, and says so');
        $this->assertSame(300.0, $out['totals']['supplier']);
        $this->assertSame(300.0, $out['totals']['all']);
    }

    /** A rental that never went to the workshop is empty, which is the honest answer, not an error. */
    public function test_a_contract_with_no_repairs_comes_back_empty(): void
    {
        $out = $this->repairs->forContract($this->contract());

        $this->assertSame([], $out['garage_invoices']);
        $this->assertSame([], $out['supplier_invoices']);
        $this->assertSame(0.0, $out['totals']['all']);
    }

    /** The endpoint the contract page reads. */
    public function test_the_contract_endpoint_returns_both_kinds_of_bill(): void
    {
        $contract = $this->contract();
        $ticket   = $this->ticket(['contract_id' => $contract->id]);

        $this->getJson("/api/Contract/{$contract->id}/repair-invoices")
            ->assertOk()
            ->assertJsonPath('data.totals.all', 0)
            ->assertJsonPath('data.tickets', [$ticket->id]);
    }
}
