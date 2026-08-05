<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Services\CostSourceResolver;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Support\Carbon;

/**
 * There is exactly ONE audited path to a ticket's money, and these pin it shut.
 *
 * The bypass this closes was real and measurable: the ticket-level line writer (`syncLineItems` /
 * `markReady` with line_items) wrote maintenance_line_items with no `maintenance_invoice_id`, so every
 * line it produced was untraceable by construction — the AED 13,685 of "real lines never attached to any
 * invoice" that the traceability audit found. Both entry points now route through
 * MaintenanceInvoiceService, the single writer.
 *
 * If someone re-introduces a direct writer, these tests fail — which is the point of writing them.
 */
class NoFinancialBypassTest extends CrudTestCase
{
    private function ticket(array $overrides = []): Maintenance
    {
        return Maintenance::create(array_merge([
            'vehicle_id'     => $this->makeVehicle(),
            'type'           => 'Breakdown',
            'status'         => 'Open',
            'actual_in_date' => Carbon::now()->toDateString(),
            'findings'       => [['text' => 'Brake noise']],
        ], $overrides));
    }

    private function fault(Maintenance $ticket): MaintenanceTask
    {
        return MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => 'Brake noise',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);
    }

    private function lines(): array
    {
        return [
            ['kind' => 'part',  'description' => 'Brake Pad Set', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 400],
            ['kind' => 'labor', 'description' => 'Fit pads',      'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 150],
        ];
    }

    // ── The line endpoint now produces a real document ───────────────────────────────────────────────

    public function test_the_line_items_endpoint_creates_a_real_invoice(): void
    {
        $ticket = $this->ticket();
        $this->fault($ticket);

        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", [
            'line_items' => $this->lines(),
        ])->assertSuccessful();

        // It used to write bare lines onto the ticket. Now there is a bill behind them.
        $this->assertSame(1, MaintenanceInvoice::where('maintenance_id', $ticket->id)->count());

        foreach (MaintenanceLineItem::where('maintenance_id', $ticket->id)->get() as $line) {
            $this->assertNotNull($line->maintenance_invoice_id, "“{$line->description}” has no invoice behind it.");
            $this->assertSame(CostSourceResolver::SOURCE_GARAGE_INVOICE, $line->source_type);
        }

        $this->assertSame(550.0, round((float) $ticket->fresh()->cost, 2));
    }

    public function test_every_amount_the_endpoint_creates_is_traceable(): void
    {
        $ticket = $this->ticket();
        $this->fault($ticket);

        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", [
            'line_items' => $this->lines(),
        ])->assertSuccessful();

        $audit = app(CostSourceResolver::class)->auditTicket($ticket->fresh());

        // The exact failure this whole audit was about: money on a ticket with nothing behind it.
        $this->assertTrue($audit['fully_traceable'], 'The line-items endpoint produced untraceable money.');
        $this->assertSame(0.0, $audit['untraceable']);
    }

    public function test_editing_lines_reuses_the_same_invoice_rather_than_stacking_documents(): void
    {
        $ticket = $this->ticket();
        $this->fault($ticket);

        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", ['line_items' => $this->lines()])
            ->assertSuccessful();
        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", [
            'line_items' => [['kind' => 'labor', 'description' => 'Fit pads', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 200]],
        ])->assertSuccessful();

        $this->assertSame(1, MaintenanceInvoice::where('maintenance_id', $ticket->id)->count());
        $this->assertSame(200.0, round((float) $ticket->fresh()->cost, 2));
    }

    public function test_a_ticket_with_two_invoices_refuses_the_ambiguous_replace_all(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        foreach (['G-1', 'G-2'] as $no) {
            $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
                'vendor_id'  => $this->makeVendor(),
                'invoice_no' => $no,
                'line_items' => [['kind' => 'labor', 'description' => 'Work', 'finding_text' => 'Brake noise', 'quantity' => 1, 'unit_price' => 100]],
            ])->assertSuccessful();
        }

        // "Replace the ticket's lines" has no single meaning across two bills — it is refused, not guessed.
        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", ['line_items' => $this->lines()])
            ->assertStatus(422);

        $this->assertSame(200.0, round((float) $ticket->fresh()->cost, 2), 'Neither bill was touched.');
    }

    // ── The invariant every UI total rests on ────────────────────────────────────────────────────────

    public function test_the_ticket_total_is_always_the_sum_of_its_lines(): void
    {
        $ticket = $this->ticket();
        $task   = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal'     => true,
            'task_ids'        => [$task->id],
            'line_items'      => $this->lines(),
            'vat_amount'      => 27.5,
            'discount_amount' => 50,
        ])->assertSuccessful();

        $sum = round((float) MaintenanceLineItem::where('maintenance_id', $ticket->id)->sum('line_total'), 2);

        // Every figure the UI prints derives from this equality. If it can be broken, a displayed total
        // can disagree with the documents underneath it.
        $this->assertSame($sum, round((float) $ticket->fresh()->cost, 2));
        $this->assertSame(527.5, $sum);
    }

    public function test_a_lump_sum_cannot_be_typed_over_lines_created_through_the_endpoint(): void
    {
        $ticket = $this->ticket();
        $this->fault($ticket);
        $this->putJson("/api/maintenance-tickets/{$ticket->id}/line-items", ['line_items' => $this->lines()])
            ->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/cost", ['cost' => 9999])
            ->assertStatus(422);

        $this->assertSame(550.0, round((float) $ticket->fresh()->cost, 2));
    }

    // ── The retired writer is genuinely unreachable ──────────────────────────────────────────────────

    /**
     * ONE authoritative writer per financial entity, enforced by scanning the codebase.
     *
     * This is the architectural rule made executable. Every entity below may be CREATED by exactly one
     * service; anything else that learns to create one — a new controller, a helper, a command, a
     * "quick fix" — fails this test rather than quietly re-opening a bypass years from now.
     *
     * @return array<string, array{0:string, 1:array<int,string>}>
     */
    public static function financialWriters(): array
    {
        return [
            'garage invoice'     => ['MaintenanceInvoice::create|invoices\(\)->create', ['MaintenanceInvoiceService.php']],
            'supplier invoice'   => ['PartInvoice::create', ['PartInvoiceService.php']],
            'part purchase'      => ['PartPurchase::create', ['PartWorkflowService.php']],
            'return/credit note' => ['PartReturn::create', ['PartReturnService.php']],
            'cost adjustment'    => ['CostAdjustment::create', ['CostAdjustmentService.php']],
            'supplier payment'   => ['SupplierPayment::create', ['SupplierPaymentService.php']],
            'payment allocation' => ['PaymentAllocation::create|allocations\(\)->create', ['SupplierPaymentService.php']],
            'cost line' => [
                'MaintenanceLineItem::create|lineItems\(\)->create',
                // Four writers, because there are four source documents — each attaches its own.
                ['MaintenanceInvoiceService.php', 'PartWorkflowService.php', 'PartReturnService.php', 'CostAdjustmentService.php'],
            ],
        ];
    }

    /** @param array<int,string> $allowed */
    #[\PHPUnit\Framework\Attributes\DataProvider('financialWriters')]
    public function test_each_financial_entity_has_exactly_one_authoritative_writer(string $pattern, array $allowed): void
    {
        $offenders = [];

        // Services, controllers and console commands — every place application code could write.
        $files = array_merge(
            glob(app_path('Services/*.php')) ?: [],
            glob(app_path('Services/**/*.php')) ?: [],
            glob(app_path('Http/Controllers/*.php')) ?: [],
            glob(app_path('Console/Commands/*.php')) ?: [],
            glob(app_path('Listeners/*.php')) ?: [],
            glob(app_path('Observers/*.php')) ?: [],
        );

        foreach ($files as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }
            // Demo tooling is fenced off from live data by GuardsDemoWrites and is not an app path.
            if (str_contains(basename($file), 'DemoSeed')) {
                continue;
            }
            if (preg_match('/' . $pattern . '/', (string) file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, sprintf(
            'These files create this entity outside its authoritative writer (%s): %s',
            implode(', ', $allowed),
            implode(', ', $offenders),
        ));
    }

    /**
     * No console command may change what an amount IS, or which document backs it.
     *
     * Commands are the easiest place for a bypass to hide — they run outside HTTP, outside permissions,
     * and usually without review. One legitimately touches cost lines (`maintenance:backfill-tasks`
     * re-attributes a legacy line to the fault it belongs to) and that is allowed precisely because it
     * only sets `maintenance_task_id`: attribution, never money, never provenance. If it — or any other
     * command — ever learns to write an amount or a source, this fails.
     */
    public function test_no_console_command_can_alter_an_amount_or_its_source(): void
    {
        // Scoped to THIS domain on purpose. `vehicle_expenses` also has an `amount`, and its importer is
        // a different ledger with its own owner (VehicleExpenseProvider) — flagging it would be noise,
        // and a rule that cries wolf gets deleted by the next person who trips it.
        $inScope   = '/MaintenanceLineItem|MaintenanceInvoice|PartInvoice|PartPurchase|PartReturn|CostAdjustment|SupplierPayment|PaymentAllocation|maintenance_line_items|part_invoices|maintenance_invoices|supplier_payments/';
        $forbidden = '/[\'"](?:line_total|unit_price|paid_amount|source_type|source_id|maintenance_invoice_id|part_invoice_id)[\'"]\s*=>/';
        $offenders = [];

        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Demo tooling is fenced off from live data by GuardsDemoWrites; it is not an app path.
            if (str_contains(basename($file), 'DemoSeed')) {
                continue;
            }
            if (! preg_match($inScope, $source)) {
                continue;
            }
            // Only writes count — a command that READS these columns to report on them is the whole
            // point of the audit tooling.
            if (preg_match($forbidden, $source) && preg_match('/->update\(|::create\(|->save\(\)/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'These commands can rewrite money or its provenance: ' . implode(', ', $offenders));
    }

    public function test_no_service_writes_a_cost_line_outside_the_audited_writers(): void
    {
        // Only these four may create a maintenance_line_items row, and each attaches a source document:
        //   MaintenanceInvoiceService → garage invoice      PartWorkflowService  → supplier invoice
        //   PartReturnService         → credit note         CostAdjustmentService → adjustment
        $allowed = [
            'MaintenanceInvoiceService.php',
            'PartWorkflowService.php',
            'PartReturnService.php',
            'CostAdjustmentService.php',
        ];

        $offenders = [];
        foreach (glob(app_path('Services/*.php')) as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }
            $source = file_get_contents($file);
            if (preg_match('/MaintenanceLineItem::create|lineItems\(\)->create/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These services write cost lines directly, bypassing the audited path: ' . implode(', ', $offenders),
        );
    }

    // ── Closure means financially complete ───────────────────────────────────────────────────────────

    public function test_closing_with_undocumented_money_parks_the_ticket_instead_of_closing_it(): void
    {
        $ticket = $this->ticket([
            'workflow_status' => Maintenance::WF_IN_OUR_PARK,
            'vendor_id'       => $this->makeVendor(),
        ]);
        $this->fault($ticket)->forceFill(['status' => MaintenanceTask::STATUS_COMPLETED])->save();

        // A typed total with no document behind it.
        app(MaintenanceWorkflowService::class)->close($ticket, ['cost' => 900], $this->admin);

        $fresh = $ticket->fresh();
        // The car is back and the operational work is done — but CLOSED would claim the money is
        // accounted for, so it lands in the deferred-invoice lane instead.
        $this->assertSame(Maintenance::WF_AWAITING_INVOICE, $fresh->workflow_status);
        $this->assertNotNull($fresh->awaiting_invoice_since);
    }

    public function test_closing_with_a_documented_bill_really_closes(): void
    {
        $ticket = $this->ticket([
            'workflow_status' => Maintenance::WF_IN_OUR_PARK,
            'vendor_id'       => $this->makeVendor(),
        ]);
        $task = $this->fault($ticket);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => $this->lines(),
        ]);
        $res->assertSuccessful();

        // A DRAFT bill is not an accepted obligation, so it holds the close open until someone approves
        // it — that gate is the point, not an obstacle to work around in the test.
        $invoiceId = data_get($res->json(), 'data.invoices.0.id');
        $this->postJson("/api/financial-documents/garage-invoice/{$invoiceId}/approve")->assertSuccessful();

        $task->forceFill(['status' => MaintenanceTask::STATUS_COMPLETED])->save();

        app(MaintenanceWorkflowService::class)->close($ticket->fresh(), [], $this->admin);

        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);
    }

    public function test_an_unapproved_bill_holds_the_close_open(): void
    {
        $ticket = $this->ticket([
            'workflow_status' => Maintenance::WF_IN_OUR_PARK,
            'vendor_id'       => $this->makeVendor(),
        ]);
        $task = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => $this->lines(),
        ])->assertSuccessful();
        $task->forceFill(['status' => MaintenanceTask::STATUS_COMPLETED])->save();

        app(MaintenanceWorkflowService::class)->close($ticket->fresh(), [], $this->admin);

        $this->assertSame(Maintenance::WF_AWAITING_INVOICE, $ticket->fresh()->workflow_status);
    }

    public function test_a_total_cannot_be_typed_at_closing_time_either(): void
    {
        $ticket = $this->ticket([
            'workflow_status' => Maintenance::WF_IN_OUR_PARK,
            'vendor_id'       => $this->makeVendor(),
        ]);
        $task = $this->fault($ticket);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/invoices", [
            'is_internal' => true,
            'task_ids'    => [$task->id],
            'line_items'  => $this->lines(),
        ])->assertSuccessful();
        $task->forceFill(['status' => MaintenanceTask::STATUS_COMPLETED])->save();

        // The closing screen is not a licence to type over money the invoices already account for.
        $this->expectException(\App\Exceptions\WorkflowTransitionException::class);
        app(MaintenanceWorkflowService::class)->close($ticket->fresh(), ['cost' => 9999], $this->admin);
    }

    public function test_marking_a_ticket_ready_with_lines_also_produces_a_document(): void
    {
        $ticket = $this->ticket(['workflow_status' => Maintenance::WF_UNDER_REPAIR, 'vendor_id' => $this->makeVendor()]);
        // markReady refuses while a fault is still open — resolve it so the test exercises the FINANCIAL
        // path rather than tripping an unrelated workflow precondition.
        $this->fault($ticket)->forceFill(['status' => MaintenanceTask::STATUS_COMPLETED])->save();

        app(MaintenanceWorkflowService::class)->markReady($ticket, [
            'line_items' => $this->lines(),
        ], $this->admin);

        $this->assertSame(1, MaintenanceInvoice::where('maintenance_id', $ticket->id)->count());
        $this->assertTrue(app(CostSourceResolver::class)->auditTicket($ticket->fresh())['fully_traceable']);
    }
}
