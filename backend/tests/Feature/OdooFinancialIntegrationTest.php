<?php

namespace Tests\Feature;

use App\Models\ComponentCatalog;
use App\Models\ExpenseTypeMapping;
use App\Models\FinancialEvent;
use App\Models\FinancialEventAttempt;
use App\Models\FuelFill;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\OdooMapping;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\VehicleRegistration;
use App\Models\VehicleWashJob;
use App\Models\Vendor;
use App\Services\Odoo\FinancialEventBuilder;
use App\Services\Odoo\FinancialEventSyncService;
use App\Services\Odoo\OdooClient;
use App\Services\Odoo\OdooMappingService;
use App\Support\ExpenseType;
use App\Support\FinancialBlockReason;
use App\Support\FinancialSyncStatus as Status;
use App\Support\OdooDocumentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * END-TO-END guard for the Odoo financial bridge — the acceptance criteria in §50, as tests.
 *
 * WHAT THESE LOCK, in order of what would actually go wrong:
 *
 *   1. IDEMPOTENCY. The single most dangerous outcome of connecting FleetView to an accounting system
 *      is paying a garage twice. test_a_lost_response_never_creates_a_second_document reproduces the
 *      exact §23 scenario — Odoo creates the bill, the response is lost, the push is retried — and
 *      proves the retry ADOPTS the existing document. If a future change makes the pre-create search
 *      conditional, this is the test that fails.
 *   2. THE REFUSALS. Every blocking reason is a decision not to post something wrong, and each is worth
 *      as much as the happy path. A suite that only proved a bill can be sent would let "raise the sync
 *      rate by guessing the product" pass review.
 *   3. THE SEPARATION (§8). Operational completion and financial readiness are different questions, and
 *      a blocked event must never stand in the way of a repair. That is tested explicitly, because it
 *      is the kind of coupling that gets added by accident and is very hard to remove later.
 *
 * THE ODOO DOUBLE. {@see fakeOdoo()} is an in-memory JSON-RPC server that keeps the documents it is
 * told to create. It is a TEST double, not a mock API in the application — nothing in app/ knows it
 * exists, and it answers the real wire protocol OdooClient speaks. It is what lets the idempotency test
 * be honest: the document genuinely survives between the failed attempt and the retry, exactly as one
 * in a real Odoo would.
 *
 * REQUIRES the `fleet_e2e_scratch` MySQL database — run with `-c phpunit.e2e.xml`. No RefreshDatabase:
 * the full migration set cannot be replayed from empty, so the schema is cloned from live and only the
 * touched tables are truncated. @see GarageSuppliedPartLifecycleTest.
 */
class OdooFinancialIntegrationTest extends TestCase
{
    private const TOUCHED = [
        'financial_events', 'financial_event_lines', 'financial_event_attempts',
        'odoo_mappings', 'expense_type_mappings', 'odoo_reference_records',
        'maintenance_line_items', 'maintenance_invoices', 'maintenances',
        'vehicle_log_events', 'vehicles', 'vendors', 'users', 'component_catalog',
        // The four everyday running-cost producers.
        'fuel_fills', 'vehicle_wash_jobs', 'vehicle_registrations', 'logistics_tasks',
    ];

    /** Odoo ids used by the double. Arbitrary but FIXED, so a wrong id in a payload is visible. */
    private const ODOO_ACCOUNT_REPAIR       = 4101;
    private const ODOO_ACCOUNT_RECOVERY     = 4103;
    private const ODOO_ACCOUNT_FUEL         = 4104;
    private const ODOO_ACCOUNT_REGISTRATION = 4105;
    private const ODOO_ACCOUNT_WASH         = 4106;
    private const ODOO_ACCOUNT_TAXI         = 4107;
    private const ODOO_ANALYTIC         = 7001;
    private const ODOO_PARTNER          = 8001;
    private const ODOO_PRODUCT          = 9001;
    private const ODOO_EMPLOYEE         = 501;

    private User $actor;
    private Vehicle $vehicle;
    private Vendor $garage;
    private Maintenance $ticket;
    private ComponentCatalog $brakePads;

    /** The double's state — documents it has created, and the faults it has been told to inject. */
    private array $odoo = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'fleet_e2e_scratch') {
            $this->markTestSkipped('needs the fleet_e2e_scratch database — run with -c phpunit.e2e.xml');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TOUCHED as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // A configured integration. Without these the validator blocks everything with
        // `odoo_not_configured`, which is correct behaviour but tests nothing else.
        config()->set('odoo.url', 'https://odoo.example.test');
        config()->set('odoo.database', 'fleetdb');
        config()->set('odoo.username', 'bot@fleet.test');
        config()->set('odoo.password', 's3cret');
        config()->set('odoo.currency', 'AED');
        config()->set('odoo.expense_employee_id', self::ODOO_EMPLOYEE);
        config()->set('odoo.external_ref_prefix', 'FLEETVIEW-FE-');

        $this->actor   = User::create(['name' => 'Finance', 'email' => 'fin@fleet.test', 'password' => 'x', 'status' => 'active']);
        $this->vehicle = Vehicle::create(['plate_no' => 'G63-1', 'vin' => 'W1NYC7GJ1RX502076', 'make' => 'Mercedes', 'model' => 'G63']);
        $this->garage  = Vendor::create(['name' => 'Garage ABC', 'active' => true]);
        $this->ticket  = Maintenance::create([
            'vehicle_id'       => $this->vehicle->id,
            'maintenance_type' => Maintenance::TYPE_BREAKDOWN,
            'workflow_status'  => Maintenance::WF_UNDER_REPAIR,
        ]);
        $this->brakePads = ComponentCatalog::create([
            'slug' => 'brake-pad-front', 'name' => 'Brake Pad Front',
            'category_key' => 'brakes_suspension',
            'tracking_mode' => ComponentCatalog::TRACKING_BATCH, 'is_active' => true,
        ]);

        // The expense-type board, as the seeder would leave it PLUS the account Finance resolved.
        ExpenseTypeMapping::create([
            'expense_type' => ExpenseType::REPAIR, 'label' => 'Repair maintenance',
            'odoo_account_id' => self::ODOO_ACCOUNT_REPAIR, 'odoo_account_name' => 'Fleets Maintenance | Repair Maintenance Expenses',
            'odoo_document_type' => OdooDocumentType::VENDOR_BILL, 'active' => true,
        ]);
        ExpenseTypeMapping::create([
            'expense_type' => ExpenseType::RECOVERY, 'label' => 'Recovery & towing',
            'odoo_account_id' => self::ODOO_ACCOUNT_RECOVERY, 'odoo_account_name' => 'Fleets Maintenance | Recovery Maintenance Expenses',
            'odoo_document_type' => OdooDocumentType::VENDOR_BILL, 'active' => true,
        ]);

        $this->fakeOdoo();
    }

    // ── The Odoo double ───────────────────────────────────────────────────────────────────────────

    /**
     * An in-memory Odoo speaking real JSON-RPC.
     *
     * It keeps created documents in $this->odoo['docs'], which is what makes the idempotency test
     * meaningful: the bill created by the attempt whose response was "lost" is still there when the
     * retry searches for it, exactly as it would be in a real Odoo.
     */
    private function fakeOdoo(): void
    {
        $this->odoo = ['docs' => [], 'next_id' => 18452, 'fail_create' => null, 'creates' => 0, 'searches' => 0];

        Http::fake(function ($request) {
            $body   = json_decode($request->body(), true);
            $params = $body['params'] ?? [];

            if (($params['method'] ?? null) === 'login') {
                return Http::response(['jsonrpc' => '2.0', 'result' => 2]);
            }
            if (($params['method'] ?? null) === 'version') {
                return Http::response(['jsonrpc' => '2.0', 'result' => ['server_version' => '17.0']]);
            }

            // execute_kw(db, uid, password, model, method, args, kwargs)
            [, , , $model, $method, $args] = $params['args'];

            return match ($method) {
                'search'      => $this->odooSearch($model, $args[0] ?? []),
                'read'        => $this->odooRead($args[0] ?? []),
                'search_read' => Http::response(['jsonrpc' => '2.0', 'result' => []]),
                'create'      => $this->odooCreate($model, $args[0] ?? []),
                default       => Http::response(['jsonrpc' => '2.0', 'result' => true]),
            };
        });
    }

    private function odooSearch(string $model, array $domain)
    {
        $this->odoo['searches']++;

        [$field, , $value] = $domain[0] ?? [null, null, null];

        $ids = [];
        foreach ($this->odoo['docs'] as $id => $doc) {
            if ($doc['model'] === $model && ($doc[$field] ?? null) === $value) {
                $ids[] = $id;
            }
        }

        return Http::response(['jsonrpc' => '2.0', 'result' => $ids]);
    }

    private function odooRead(array $ids)
    {
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['id' => $id, 'name' => $this->odoo['docs'][$id]['name'] ?? '/'];
        }

        return Http::response(['jsonrpc' => '2.0', 'result' => $rows]);
    }

    private function odooCreate(string $model, array $values)
    {
        // ir.attachment is a side effect of a successful push, not a document — it gets an id and is
        // otherwise ignored, so attachment transfer never affects a document assertion.
        if ($model === 'ir.attachment') {
            return Http::response(['jsonrpc' => '2.0', 'result' => 99000 + count($this->odoo['docs'])]);
        }

        $this->odoo['creates']++;
        $id = $this->odoo['next_id']++;

        $this->odoo['docs'][$id] = [
            'model'  => $model,
            'ref'    => $values['ref'] ?? null,
            // hr.expense's free reference field is `reference`, not `ref` — the double honours that
            // difference because the pusher depends on it, and searching the wrong field would look
            // exactly like "no duplicate exists".
            'reference' => $values['reference'] ?? null,
            'name'   => $model === 'account.move' ? 'BILL/2026/' . $id : 'EXP/2026/' . $id,
            'values' => $values,
        ];

        // The §23 scenario: Odoo COMMITS the document and the response never arrives.
        if ($this->odoo['fail_create'] === 'timeout') {
            $this->odoo['fail_create'] = null;
            throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms');
        }
        if ($this->odoo['fail_create'] === 'validation') {
            $this->odoo['fail_create'] = null;
            unset($this->odoo['docs'][$id]);   // a refused create leaves nothing behind
            $this->odoo['creates']--;

            return Http::response(['jsonrpc' => '2.0', 'error' => [
                'data' => ['name' => 'odoo.exceptions.ValidationError', 'message' => 'Journal is required.'],
            ]]);
        }

        return Http::response(['jsonrpc' => '2.0', 'result' => $id]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A garage bill for one part and one hour of labour — the ordinary repair case, COMPLETE.
     *
     * The receipt scan is part of "complete" now: a vendor bill may not be sent without its supporting
     * document, so a fixture without one would be testing the blocked path every time. The unbacked
     * case has its own test rather than being the default.
     */
    private function billFor(float $partPrice = 2000, float $labour = 300, bool $withReceipt = true): MaintenanceInvoice
    {
        $invoice = MaintenanceInvoice::create([
            'maintenance_id'     => $this->ticket->id,
            'vendor_id'          => $this->garage->id,
            'invoice_no'         => 'GA-2026-0042',
            'recorded_at'        => now(),
            'receipt_photo_disk' => $withReceipt ? 'public' : null,
            'receipt_photo_key'  => $withReceipt ? 'maintenance/receipts/ga-2026-0042.jpg' : null,
        ]);

        MaintenanceLineItem::create([
            'maintenance_id'       => $this->ticket->id,
            'maintenance_invoice_id' => $invoice->id,
            'vehicle_id'           => $this->vehicle->id,
            'kind'                 => MaintenanceLineItem::KIND_PART,
            'description'          => 'Brake Pads',
            'component_catalog_id' => $this->brakePads->id,
            'quantity'             => 1,
            'unit_price'           => $partPrice,
        ]);
        MaintenanceLineItem::create([
            'maintenance_id'       => $this->ticket->id,
            'maintenance_invoice_id' => $invoice->id,
            'vehicle_id'           => $this->vehicle->id,
            'kind'                 => MaintenanceLineItem::KIND_LABOR,
            'description'          => 'Labour',
            'quantity'             => 1,
            'unit_price'           => $labour,
        ]);

        return $invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']);
    }

    /** Point one expense type at a real account, the way Finance would on the mappings screen. */
    private function mapType(string $expenseType, int $accountId, string $documentType = OdooDocumentType::VENDOR_BILL): ExpenseTypeMapping
    {
        return ExpenseTypeMapping::updateOrCreate(
            ['expense_type' => $expenseType],
            [
                'label'              => ExpenseType::label($expenseType),
                'odoo_account_id'    => $accountId,
                'odoo_account_name'  => ExpenseType::label($expenseType) . ' account',
                'odoo_document_type' => $documentType,
                'active'             => true,
            ]
        );
    }

    /** All seven on the board, as the seeder plus a round of Finance's account resolution would leave it. */
    private function seedAllExpenseTypes(): void
    {
        $this->mapType(ExpenseType::REPAIR, self::ODOO_ACCOUNT_REPAIR);
        $this->mapType(ExpenseType::ROUTINE, self::ODOO_ACCOUNT_REPAIR);
        $this->mapType(ExpenseType::RECOVERY, self::ODOO_ACCOUNT_RECOVERY);
        $this->mapType(ExpenseType::FUEL, self::ODOO_ACCOUNT_FUEL);
        $this->mapType(ExpenseType::CAR_WASH, self::ODOO_ACCOUNT_WASH);
        $this->mapType(ExpenseType::REGISTRATION, self::ODOO_ACCOUNT_REGISTRATION, OdooDocumentType::EXPENSE);
        $this->mapType(ExpenseType::TAXI, self::ODOO_ACCOUNT_TAXI, OdooDocumentType::EXPENSE);
    }

    private function mapEverything(): void
    {
        $m = app(OdooMappingService::class);
        $m->map($this->vehicle, self::ODOO_ANALYTIC, 'G63-W1NYC7GJ1RX502076', 'G63 analytic', $this->actor);
        $m->map($this->garage, self::ODOO_PARTNER, 'GAR-ABC', 'Garage ABC', $this->actor);
        $m->map($this->brakePads, self::ODOO_PRODUCT, 'BP-FRONT', 'Front Brake Pads', $this->actor);
    }

    /** Null is a legitimate answer — a source with no obligation writes no event at all. */
    private function build(MaintenanceInvoice $invoice): ?FinancialEvent
    {
        return app(FinancialEventBuilder::class)->syncFor($invoice, $this->actor);
    }

    private function codes(FinancialEvent $event): array
    {
        return FinancialBlockReason::codes($event->blockReasons());
    }

    // ── The obligation is recognised, and says exactly what it is waiting for ──────────────────────

    public function test_a_garage_bill_raises_an_obligation_that_references_the_operational_data(): void
    {
        $event = $this->build($this->billFor());

        $this->assertSame(ExpenseType::REPAIR, $event->expense_type);
        // 2000 + 300 — summed from the LINE ITEMS, not typed anywhere.
        $this->assertSame(2300.00, (float) $event->amount);
        $this->assertSame($this->vehicle->id, $event->vehicle_id);
        $this->assertSame($this->garage->id, $event->vendor_id);
        $this->assertSame(OdooDocumentType::VENDOR_BILL, $event->odoo_document_type);

        // §31 — the invoice number and date are READ from the bill, never re-keyed onto the event.
        $this->assertSame('GA-2026-0042', $event->resolvedInvoiceNumber());
        $this->assertNull($event->invoice_number, 'the event must not copy what the invoice already holds');
        $this->assertNotNull($event->resolvedInvoiceDate());

        // Each line points AT its maintenance line item rather than copying it.
        $this->assertCount(2, $event->lines);
        $part = $event->lines->firstWhere('kind', 'part');
        $this->assertNotNull($part->origin_id);
        $this->assertSame($this->brakePads->id, $part->component_catalog_id);
    }

    public function test_an_unmapped_repair_is_blocked_and_names_every_missing_mapping_at_once(): void
    {
        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);

        // All of them, not just the first — fixing a blocked event must not be a guessing game with one
        // answer revealed per attempt.
        $this->assertEqualsCanonicalizing(
            ['vehicle_analytic_account_missing', 'product_not_mapped', 'supplier_not_mapped'],
            $this->codes($event)
        );

        // §50 — no Odoo transaction is created for a blocked event.
        $this->assertSame(0, $this->odoo['creates']);
    }

    public function test_an_unmapped_product_alone_blocks_the_bill_and_names_the_part(): void
    {
        $m = app(OdooMappingService::class);
        $m->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        $m->map($this->garage, self::ODOO_PARTNER, null, null, $this->actor);
        // The part is deliberately left unmapped.

        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['product_not_mapped'], $this->codes($event));

        // The reason carries the billed description, so the person fixing it can find the line.
        $reason = collect($event->blockReasons())->firstWhere('code', 'product_not_mapped');
        $this->assertSame('Brake Pads', $reason['params']['description']);
    }

    /**
     * §18 — a suggestion is NOT a mapping. This is the test that stops "match the names, it is
     * obviously the same part" from ever becoming the thing that decides what gets posted.
     */
    public function test_a_suggested_mapping_is_treated_as_absent(): void
    {
        $m = app(OdooMappingService::class);
        $m->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        $m->map($this->garage, self::ODOO_PARTNER, null, null, $this->actor);

        OdooMapping::create([
            'mappable_type' => $this->brakePads->getMorphClass(),
            'mappable_id'   => $this->brakePads->id,
            'odoo_model'    => OdooMapping::MODEL_PRODUCT,
            'odoo_id'       => self::ODOO_PRODUCT,
            'status'        => OdooMapping::STATUS_SUGGESTED,
            'matched_by'    => OdooMapping::MATCHED_SUGGESTED,
        ]);

        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['product_not_mapped'], $this->codes($event));
    }

    /** A mapping that went stale in Odoo stops being usable the moment the pull notices. */
    public function test_a_stale_mapping_stops_being_usable(): void
    {
        $this->mapEverything();
        $this->assertSame(Status::READY, $this->build($this->billFor())->status);

        OdooMapping::where('odoo_model', OdooMapping::MODEL_PRODUCT)
            ->update(['status' => OdooMapping::STATUS_STALE]);

        $event = app(FinancialEventBuilder::class)->revalidate(FinancialEvent::first()->load('lines.catalogPart', 'vehicle', 'vendor'));

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertContains('product_not_mapped', $this->codes($event));
    }

    // ── The happy path ────────────────────────────────────────────────────────────────────────────

    public function test_a_fully_mapped_repair_becomes_a_vendor_bill_in_odoo(): void
    {
        $this->mapEverything();
        $event = $this->build($this->billFor());

        $this->assertSame(Status::READY, $event->status);
        $this->assertSame([], $event->blockReasons());

        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);

        $this->assertSame(Status::SYNCED, $synced->status);
        $this->assertSame('account.move', $synced->odoo_document_model);
        $this->assertSame(18452, $synced->odoo_document_id);
        $this->assertSame('BILL/2026/18452', $synced->odoo_document_reference);
        $this->assertNotNull($synced->synced_at);

        // The document Odoo actually received, coded the way §16/§2 require.
        $doc = $this->odoo['docs'][18452]['values'];
        $this->assertSame('in_invoice', $doc['move_type']);
        $this->assertSame(self::ODOO_PARTNER, $doc['partner_id']);
        $this->assertSame('GA-2026-0042', $doc['payment_reference']);
        $this->assertSame($synced->odooRef(), $doc['ref'], 'the idempotency ref must be on the document');

        $lines = array_map(fn ($l) => $l[2], $doc['invoice_line_ids']);
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame(self::ODOO_ACCOUNT_REPAIR, $line['account_id']);
            // The per-asset analytic account — this is what makes the cost roll up against the CAR.
            $this->assertSame([(string) self::ODOO_ANALYTIC => 100], $line['analytic_distribution']);
        }

        // A part posts as its mapped product; labour posts against the account alone.
        $part = collect($lines)->firstWhere('name', 'Brake Pads');
        $this->assertSame(self::ODOO_PRODUCT, $part['product_id']);
        $labour = collect($lines)->firstWhere('name', 'Labour');
        $this->assertArrayNotHasKey('product_id', $labour);
    }

    public function test_the_mapping_snapshot_and_audit_trail_are_written(): void
    {
        $this->mapEverything();
        $synced = app(FinancialEventSyncService::class)->sync($this->build($this->billFor()), $this->actor);

        // The snapshot — what this event actually posted with, kept answerable after a re-mapping.
        $this->assertSame(self::ODOO_ACCOUNT_REPAIR, $synced->odoo_account_id);
        $this->assertSame(self::ODOO_ANALYTIC, $synced->odoo_analytic_account_id);
        $this->assertSame(self::ODOO_PARTNER, $synced->odoo_partner_id);
        $this->assertSame(self::ODOO_PRODUCT, (int) $synced->lines->firstWhere('kind', 'part')->odoo_product_id);

        // §33 — the financial act is in the CAR's own timeline, not only in an integration log.
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $this->ticket->id,
            'event_type'     => VehicleLogEvent::EVENT_FINANCIAL_SYNCED,
        ]);

        $attempt = FinancialEventAttempt::where('financial_event_id', $synced->id)->latest('id')->first();
        $this->assertSame(FinancialEventAttempt::OUTCOME_CREATED, $attempt->outcome);
        $this->assertSame(18452, $attempt->odoo_document_id);
    }

    // ── IDEMPOTENCY — the guarantee this whole design exists for (§23, §50) ────────────────────────

    /**
     * THE test. Odoo creates the bill; the response is lost to a timeout; the push is retried.
     *
     * The retry must find the document that already exists and ADOPT it. One document, not two — and
     * the attempt recorded as `linked` rather than `created`, which is the proof that a duplicate was
     * avoided rather than simply never attempted.
     */
    public function test_a_lost_response_never_creates_a_second_document(): void
    {
        $this->mapEverything();
        $event = $this->build($this->billFor());
        $sync  = app(FinancialEventSyncService::class);

        // Attempt 1 — Odoo commits the bill, then the connection dies.
        $this->odoo['fail_create'] = 'timeout';
        $failed = $sync->sync($event, $this->actor);

        $this->assertSame(Status::FAILED, $failed->status);
        $this->assertSame(FinancialEventAttempt::ERROR_TIMEOUT, $failed->failure_code);
        $this->assertNull($failed->odoo_document_id, 'we never learned the id — that is the whole problem');
        // But the document DOES exist in Odoo.
        $this->assertSame(1, $this->odoo['creates']);

        // Attempt 2 — the retry.
        $retried = $sync->sync($failed->fresh(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']), $this->actor);

        $this->assertSame(Status::SYNCED, $retried->status);
        $this->assertSame(18452, $retried->odoo_document_id, 'the retry must adopt the document that already existed');

        // The guarantee, stated three ways.
        $this->assertSame(1, $this->odoo['creates'], 'NO second document may be created');
        $this->assertCount(1, $this->odoo['docs']);
        $this->assertSame(
            FinancialEventAttempt::OUTCOME_LINKED,
            FinancialEventAttempt::where('financial_event_id', $event->id)->latest('id')->first()->outcome,
            'the adoption must be recorded as `linked`, which is what proves a duplicate was avoided'
        );
    }

    /**
     * The same guarantee from the other direction: the search runs on the FIRST attempt too, not only
     * on retries. Making it conditional is the change that would reintroduce duplicates, so it is
     * asserted directly.
     */
    public function test_the_idempotency_search_runs_before_the_very_first_create(): void
    {
        $this->mapEverything();
        $event = $this->build($this->billFor());

        $this->assertSame(0, $this->odoo['searches']);
        app(FinancialEventSyncService::class)->sync($event, $this->actor);

        $this->assertGreaterThanOrEqual(1, $this->odoo['searches'],
            'the pre-create search must run on the first attempt, not only on retries');
    }

    /** A process that died mid-push leaves SENDING, and is resolved by ASKING Odoo — never by resending. */
    public function test_an_event_stranded_in_sending_is_reconciled_rather_than_resent(): void
    {
        $this->mapEverything();
        $event = $this->build($this->billFor());

        // Simulate the crash: the document exists in Odoo under our ref, and the row says SENDING.
        $ref = $event->odooRef();
        $this->odoo['docs'][18452] = ['model' => 'account.move', 'ref' => $ref, 'reference' => null, 'name' => 'BILL/2026/18452', 'values' => []];
        $this->odoo['next_id'] = 18453;
        $event->update(['status' => Status::SENDING, 'attempts' => 1]);

        $result = app(FinancialEventSyncService::class)
            ->sync($event->fresh(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']), $this->actor);

        $this->assertSame(Status::SYNCED, $result->status);
        $this->assertSame(18452, $result->odoo_document_id);
        $this->assertSame(0, $this->odoo['creates'], 'a stranded event must never create a second document');
    }

    // ── Failure and retry ─────────────────────────────────────────────────────────────────────────

    public function test_an_odoo_refusal_fails_the_event_with_a_readable_reason_and_stays_retryable(): void
    {
        $this->mapEverything();
        $event = $this->build($this->billFor());

        $this->odoo['fail_create'] = 'validation';
        $failed = app(FinancialEventSyncService::class)->sync($event, $this->actor);

        $this->assertSame(Status::FAILED, $failed->status);
        $this->assertSame(FinancialEventAttempt::ERROR_VALIDATION, $failed->failure_code);
        $this->assertSame('Journal is required.', $failed->failure_reason);
        $this->assertTrue($failed->isSendable(), 'a failed event must remain retryable');

        // Nothing was left behind in Odoo by a refused create, so the retry genuinely creates.
        $retried = app(FinancialEventSyncService::class)
            ->sync($failed->fresh(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']), $this->actor);

        $this->assertSame(Status::SYNCED, $retried->status);
        $this->assertSame(2, $retried->attempts);
    }

    public function test_an_unconfigured_odoo_blocks_with_its_own_reason_rather_than_a_mapping_one(): void
    {
        config()->set('odoo.url', null);
        config()->set('odoo.password', null);
        $this->mapEverything();

        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertContains('odoo_not_configured', $this->codes($event));
    }

    // ── A synced event is history ─────────────────────────────────────────────────────────────────

    /**
     * Editing the bill after its document has been posted changes the ticket's cost in FleetView and
     * leaves the posted document alone. Anything else would be an integration quietly rewriting what an
     * accounting system has already recorded.
     */
    public function test_a_synced_event_is_frozen_against_later_edits(): void
    {
        $this->mapEverything();
        $invoice = $this->billFor();
        $synced  = app(FinancialEventSyncService::class)->sync($this->build($invoice), $this->actor);

        $this->assertSame(2300.00, (float) $synced->amount);

        // The garage sends a corrected line — the ticket changes, the posted event does not.
        $invoice->lineItems()->where('kind', 'part')->update(['unit_price' => 5000, 'line_total' => 5000]);
        $again = $this->build($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']));

        $this->assertSame(Status::SYNCED, $again->status);
        $this->assertSame(2300.00, (float) $again->amount, 'what Odoo was told is history and may not be rewritten');
        $this->assertSame(1, $this->odoo['creates']);
    }

    // ── §8 — operational completion and financial readiness are different questions ────────────────

    public function test_a_blocked_obligation_never_stands_in_the_way_of_the_repair(): void
    {
        $event = $this->build($this->billFor());
        $this->assertSame(Status::BLOCKED, $event->status);

        // The car is finished and goes back into service with the bookkeeping still outstanding.
        $this->ticket->update(['workflow_status' => Maintenance::WF_CLOSED]);

        $this->assertSame(Maintenance::WF_CLOSED, $this->ticket->fresh()->workflow_status);
        $this->assertSame(Status::BLOCKED, $event->fresh()->status);
    }

    // ── Zero-cost and withdrawal ──────────────────────────────────────────────────────────────────

    public function test_a_bill_with_nothing_to_pay_raises_no_obligation(): void
    {
        $invoice = MaintenanceInvoice::create([
            'maintenance_id' => $this->ticket->id,
            'vendor_id'      => $this->garage->id,
            'recorded_at'    => now(),
        ]);

        $this->assertNull($this->build($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor'])));
        $this->assertSame(0, FinancialEvent::count());
    }

    /** A bill corrected to zero does not delete its event — an obligation that went away is a fact. */
    public function test_an_obligation_that_goes_away_becomes_not_required(): void
    {
        $invoice = $this->billFor();
        $event   = $this->build($invoice);
        $this->assertSame(Status::BLOCKED, $event->status);

        // A mass delete fires no model events, so the roll-up is triggered by hand — and on a FRESH
        // instance, because recalcTotals() reads an already-loaded lineItems relation when there is one
        // and the one on $invoice still holds the rows we just removed.
        $invoice->lineItems()->delete();
        $reloaded = $invoice->fresh();
        $reloaded->recalcTotals();

        $after = $this->build($reloaded->fresh(['lineItems', 'maintenance.vehicle', 'vendor']));

        $this->assertSame(Status::NOT_REQUIRED, $after->status);
        $this->assertSame($event->id, $after->id, 'the record of the obligation is kept, not deleted');
        $this->assertSame(0, $after->lines()->count());
    }

    public function test_one_source_never_raises_two_obligations(): void
    {
        $invoice = $this->billFor();

        $a = $this->build($invoice);
        $b = $this->build($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']));

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, FinancialEvent::count());
        $this->assertSame(2, $b->lines()->count(), 'rebuilding must not accumulate duplicate lines');
    }

    // ── Recovery — the tow leg of an existing ticket, not a second module (§7) ─────────────────────

    public function test_a_recovery_cost_on_a_ticket_raises_a_recovery_obligation(): void
    {
        $recoveryCo = Vendor::create(['name' => 'ABC Recovery', 'active' => true]);

        $this->ticket->update([
            'recovery_unit_name'    => 'Recovery Truck #05',
            'recovery_cost'         => 500,
            'recovery_currency'     => 'AED',
            'recovery_vendor_id'    => $recoveryCo->id,
            'recovery_invoice_no'   => 'REC-2026-0042',
            'recovery_invoice_date' => now()->toDateString(),
            // The towing company's bill. A vendor bill may not be sent without its document.
            'recovery_invoice_disk' => 'public',
            'recovery_invoice_key'  => 'maintenance/recovery-invoices/rec-2026-0042.jpg',
        ]);

        app(OdooMappingService::class)->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        app(OdooMappingService::class)->map($recoveryCo, self::ODOO_PARTNER, null, null, $this->actor);

        $event = app(FinancialEventBuilder::class)->syncFor($this->ticket->fresh(['vehicle', 'recoveryVendor']), $this->actor);

        $this->assertSame(ExpenseType::RECOVERY, $event->expense_type);
        $this->assertSame(500.00, (float) $event->amount);
        $this->assertSame($recoveryCo->id, $event->vendor_id, 'the tow is billed by the RECOVERY company, not the garage');
        $this->assertSame('REC-2026-0042', $event->resolvedInvoiceNumber());
        $this->assertSame(Status::READY, $event->status);

        // A tow has no catalogued part, so it posts as ONE service line — no fabricated Odoo product.
        $this->assertCount(1, $event->lines);
        $this->assertSame('service', $event->lines->first()->kind);

        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);
        $this->assertSame(Status::SYNCED, $synced->status);

        $line = $this->odoo['docs'][$synced->odoo_document_id]['values']['invoice_line_ids'][0][2];
        $this->assertSame(self::ODOO_ACCOUNT_RECOVERY, $line['account_id']);
        $this->assertArrayNotHasKey('product_id', $line, 'a service must not require a product');
    }

    /**
     * A ticket's repair cost is NOT sourced from the ticket — it comes from its garage invoices. If it
     * were both, a ticket with one bill would raise two obligations for the same money.
     */
    public function test_a_ticket_with_no_tow_raises_nothing_even_when_it_has_repair_cost(): void
    {
        $this->billFor();
        $this->ticket->refresh();

        $this->assertGreaterThan(0, (float) $this->ticket->cost, 'the ticket does carry repair cost');
        $this->assertNull($this->ticket->financialExpenseType());
        $this->assertNull(app(FinancialEventBuilder::class)->syncFor($this->ticket, $this->actor));
    }

    // ── Document type drives the paperwork, not the expense type (§3, §4, §28) ────────────────────

    /**
     * The separation §3 insists on: REPAIR is not "a vendor bill", it is a repair that TODAY happens to
     * be recorded as one. Finance switches the document type on a row and the requirements change with
     * it — no code moves.
     */
    public function test_switching_an_expense_type_to_an_expense_changes_what_it_demands(): void
    {
        // Vehicle and part mapped; the SUPPLIER deliberately is not.
        $m = app(OdooMappingService::class);
        $m->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        $m->map($this->brakePads, self::ODOO_PRODUCT, null, null, $this->actor);

        $event = $this->build($this->billFor());

        // As a VENDOR BILL, an unmapped supplier is fatal — somebody billed us and we cannot say who.
        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['supplier_not_mapped'], $this->codes($event));
        $this->assertTrue($event->documentRequirements()['supplier']);

        ExpenseTypeMapping::where('expense_type', ExpenseType::REPAIR)
            ->update(['odoo_document_type' => OdooDocumentType::EXPENSE]);

        $event = app(FinancialEventBuilder::class)->revalidate($event->fresh(['lines.catalogPart', 'vehicle', 'vendor']));

        // As an EXPENSE CLAIM the same cost needs no partner at all — nobody billed us, an employee
        // claimed it back. One row changed on the mappings screen; no code moved.
        $this->assertSame(OdooDocumentType::EXPENSE, $event->odoo_document_type);
        $this->assertFalse($event->documentRequirements()['supplier']);
        $this->assertSame(Status::READY, $event->status);
    }

    public function test_an_expense_claim_with_no_configured_employee_is_blocked_rather_than_invented(): void
    {
        config()->set('odoo.expense_employee_id', null);
        ExpenseTypeMapping::where('expense_type', ExpenseType::REPAIR)
            ->update(['odoo_document_type' => OdooDocumentType::EXPENSE]);
        $this->mapEverything();

        $event = $this->build($this->billFor());

        $this->assertContains('expense_employee_missing', $this->codes($event));
    }

    public function test_a_switched_off_expense_type_blocks_everything_of_that_kind(): void
    {
        $this->mapEverything();
        ExpenseTypeMapping::where('expense_type', ExpenseType::REPAIR)->update(['active' => false]);

        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['expense_type_inactive'], $this->codes($event));
    }

    public function test_an_unresolved_expense_account_blocks_rather_than_guessing_one(): void
    {
        $this->mapEverything();
        ExpenseTypeMapping::where('expense_type', ExpenseType::REPAIR)->update(['odoo_account_id' => null]);

        $event = $this->build($this->billFor());

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['expense_account_unresolved'], $this->codes($event));
        $this->assertSame(0, $this->odoo['creates']);
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_synced_event_can_never_be_moved_again(): void
    {
        $this->mapEverything();
        $synced = app(FinancialEventSyncService::class)->sync($this->build($this->billFor()), $this->actor);

        $this->assertTrue($synced->isTerminal());
        $this->assertFalse(Status::canMove(Status::SYNCED, Status::CANCELLED));

        $this->expectException(\RuntimeException::class);
        app(FinancialEventSyncService::class)->cancel($synced, $this->actor, 'changed my mind');
    }

    public function test_cancelling_withdraws_the_obligation_and_survives_a_rebuild(): void
    {
        $invoice = $this->billFor();
        $event   = $this->build($invoice);

        $cancelled = app(FinancialEventSyncService::class)->cancel($event, $this->actor, 'Billed to the customer instead');
        $this->assertSame(Status::CANCELLED, $cancelled->status);

        // Re-saving the invoice must not quietly resurrect a decision somebody took.
        $again = $this->build($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']));
        $this->assertSame(Status::CANCELLED, $again->status);
    }

    public function test_sending_something_that_is_not_ready_is_refused(): void
    {
        $event = $this->build($this->billFor());
        $this->assertSame(Status::BLOCKED, $event->status);

        $this->expectException(\RuntimeException::class);
        app(FinancialEventSyncService::class)->sync($event, $this->actor);
    }

    // ── The supporting document is mandatory before READY ──────────────────────────────────────────

    /**
     * A number and a date can be typed from memory. The scan is the only thing that proves the bill
     * exists and says what we claim it says, so a vendor bill without one may not be sent.
     */
    public function test_a_vendor_bill_without_its_document_cannot_become_ready(): void
    {
        $this->mapEverything();

        $event = $this->build($this->billFor(withReceipt: false));

        $this->assertSame(Status::BLOCKED, $event->status);
        $this->assertSame(['attachment_missing'], $this->codes($event));
        $this->assertTrue($event->documentRequirements()['attachment']);
        $this->assertSame(0, $this->odoo['creates'], 'nothing may reach Odoo without its document');
    }

    /** Attaching the document is what unblocks it — nothing else changes. */
    public function test_attaching_the_document_turns_the_event_ready(): void
    {
        $this->mapEverything();
        $invoice = $this->billFor(withReceipt: false);
        $this->assertSame(Status::BLOCKED, $this->build($invoice)->status);

        $invoice->update([
            'receipt_photo_disk' => 'public',
            'receipt_photo_key'  => 'maintenance/receipts/late-arrival.pdf',
        ]);

        $event = $this->build($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']));

        $this->assertSame(Status::READY, $event->status);
        $this->assertTrue($event->resolvedAttachment() !== null);
    }

    /**
     * The requirement is Finance's to set per category — a AED 20 fare and a AED 40,000 rebuild do not
     * deserve the same insistence. The override lives on the expense type, not in code.
     */
    public function test_finance_can_waive_the_document_requirement_for_one_expense_type(): void
    {
        $this->mapEverything();
        $this->assertSame(Status::BLOCKED, $this->build($this->billFor(withReceipt: false))->status);

        ExpenseTypeMapping::where('expense_type', ExpenseType::REPAIR)
            ->update(['requires_attachment' => false]);

        $event = app(FinancialEventBuilder::class)->revalidate(
            FinancialEvent::first()->load('lines.catalogPart', 'vehicle', 'vendor')
        );

        $this->assertSame(Status::READY, $event->status);
        $this->assertFalse($event->documentRequirements()['attachment']);
    }

    // ── Mapping lifecycle states ──────────────────────────────────────────────────────────────────

    /**
     * The six states the mapping screen renders are genuinely different answers, and `changed` is the
     * one that matters most: it still posts, but it says documents may have been coded against a
     * different target than the row now names.
     */
    public function test_a_mapping_reports_its_lifecycle_state(): void
    {
        $service = app(OdooMappingService::class);

        // Never looked at.
        $this->assertNull($this->vehicle->odooMappingFor(OdooMapping::MODEL_ANALYTIC));

        // Confirmed.
        $mapping = $service->map($this->vehicle, self::ODOO_ANALYTIC, 'G63-VIN', 'G63', $this->actor);
        $this->assertSame('mapped', $mapping->displayState());
        $this->assertSame(0, (int) $mapping->change_count);
        $this->assertNotNull($mapping->mapped_at, 'a confirmed mapping records who accepted it and when');

        // Re-confirming the SAME target is not a change and must not inflate the counter.
        $mapping = $service->map($this->vehicle, self::ODOO_ANALYTIC, 'G63-VIN', 'G63', $this->actor);
        $this->assertSame(0, (int) $mapping->change_count);
        $this->assertSame('mapped', $mapping->displayState());

        // Re-pointing IS a change, and leaves the previous target behind.
        $mapping = $service->map($this->vehicle, 7002, 'G63-NEW', 'G63 (new)', $this->actor);
        $this->assertSame('changed', $mapping->displayState());
        $this->assertSame(1, (int) $mapping->change_count);
        $this->assertSame(self::ODOO_ANALYTIC, (int) $mapping->previous_odoo_id);
        $this->assertSame('G63', $mapping->previous_odoo_name);
        $this->assertNotNull($mapping->changed_at);
        $this->assertTrue($mapping->isUsable(), 'a changed mapping still posts — it is a flag, not a block');

        // "No counterpart" is an ANSWER, not an open question — it leaves the backlog.
        $none = $service->markUnmapped($this->garage, $this->actor, 'paid in cash, never a partner');
        $this->assertSame('none', $none->displayState());
        $this->assertFalse($none->isUsable());
    }

    /**
     * A part mapped once resolves to the SAME Odoo product on every later bill. This is what stops the
     * integration from creating duplicate or ambiguous products for a part we have already identified.
     */
    public function test_a_mapped_part_resolves_to_the_same_odoo_product_on_every_bill(): void
    {
        $this->mapEverything();

        $first = app(FinancialEventSyncService::class)->sync($this->build($this->billFor()), $this->actor);

        // A second, independent bill for the same part on a second ticket.
        $ticket2 = Maintenance::create([
            'vehicle_id'       => $this->vehicle->id,
            'maintenance_type' => Maintenance::TYPE_BREAKDOWN,
            'workflow_status'  => Maintenance::WF_UNDER_REPAIR,
        ]);
        $invoice2 = MaintenanceInvoice::create([
            'maintenance_id'     => $ticket2->id,
            'vendor_id'          => $this->garage->id,
            'invoice_no'         => 'GA-2026-0043',
            'recorded_at'        => now(),
            'receipt_photo_disk' => 'public',
            'receipt_photo_key'  => 'maintenance/receipts/ga-2026-0043.jpg',
        ]);
        MaintenanceLineItem::create([
            'maintenance_id'         => $ticket2->id,
            'maintenance_invoice_id' => $invoice2->id,
            'vehicle_id'             => $this->vehicle->id,
            'kind'                   => MaintenanceLineItem::KIND_PART,
            'description'            => 'Front Brake Pads',   // a DIFFERENT spelling of the same part
            'component_catalog_id'   => $this->brakePads->id,
            'quantity'               => 1,
            'unit_price'             => 2100,
        ]);

        $second = app(FinancialEventSyncService::class)->sync(
            $this->build($invoice2->fresh(['lineItems', 'maintenance.vehicle', 'vendor'])),
            $this->actor
        );

        $this->assertSame(Status::SYNCED, $second->status);

        // Two different bills, two different descriptions, ONE Odoo product — because the catalogue
        // part is what resolves, never the wording on the invoice.
        $productOf = fn (FinancialEvent $e) => collect($this->odoo['docs'][$e->odoo_document_id]['values']['invoice_line_ids'])
            ->map(fn ($l) => $l[2])->firstWhere('product_id', '!=', null)['product_id'];

        $this->assertSame(self::ODOO_PRODUCT, $productOf($first));
        $this->assertSame(self::ODOO_PRODUCT, $productOf($second));
        $this->assertNotSame($first->odoo_document_id, $second->odoo_document_id, 'two bills are two documents');
    }

    // ── FUEL ──────────────────────────────────────────────────────────────────────────────────────

    public function test_a_fuel_fill_raises_a_fuel_obligation_and_reaches_odoo(): void
    {
        $station = Vendor::create(['name' => 'ADNOC Station 12', 'active' => true]);
        $this->mapType(ExpenseType::FUEL, self::ODOO_ACCOUNT_FUEL);
        app(OdooMappingService::class)->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        app(OdooMappingService::class)->map($station, self::ODOO_PARTNER, null, null, $this->actor);

        $fill = FuelFill::create([
            'vehicle_id'   => $this->vehicle->id,
            'filled_at'    => now(),
            'litres'       => 62.5,
            'odometer_km'  => 41230,
            'vendor_id'    => $station->id,
            'cost'         => 310.00,
            'currency'     => 'AED',
            'receipt_no'   => 'ADNOC-99881',
            'receipt_date' => now()->toDateString(),
            'receipt_disk' => 'public',
            'receipt_key'  => 'vehicles/fuel-receipts/adnoc-99881.jpg',
        ]);

        $event = app(FinancialEventBuilder::class)->syncFor($fill->fresh(['vehicle', 'vendor']), $this->actor);

        $this->assertSame(ExpenseType::FUEL, $event->expense_type);
        $this->assertSame(310.00, (float) $event->amount);
        $this->assertSame(Status::READY, $event->status);

        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);
        $this->assertSame(Status::SYNCED, $synced->status);

        $line = $this->odoo['docs'][$synced->odoo_document_id]['values']['invoice_line_ids'][0][2];
        $this->assertSame(self::ODOO_ACCOUNT_FUEL, $line['account_id']);
        // Fuel is a service charge — no fabricated "fuel product" in somebody's Odoo catalogue.
        $this->assertArrayNotHasKey('product_id', $line);

        // The odometer reading is EVIDENCE of this fill, not a write to the car's odometer.
        $this->assertSame(41230, $fill->fresh()->odometer_km);
        $this->assertNotSame(41230, (int) $this->vehicle->fresh()->odometer);
    }

    // ── CAR WASH ──────────────────────────────────────────────────────────────────────────────────

    public function test_an_external_wash_raises_an_obligation_and_an_internal_one_does_not(): void
    {
        $washer = Vendor::create(['name' => 'Sparkle Car Wash', 'active' => true]);
        $this->mapType(ExpenseType::CAR_WASH, self::ODOO_ACCOUNT_WASH);
        app(OdooMappingService::class)->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);
        app(OdooMappingService::class)->map($washer, self::ODOO_PARTNER, null, null, $this->actor);

        $external = VehicleWashJob::create([
            'vehicle_id'        => $this->vehicle->id,
            'washed_at'         => now(),
            'wash_type'         => VehicleWashJob::TYPE_FULL,
            'performed_by_kind' => VehicleWashJob::BY_EXTERNAL,
            'vendor_id'         => $washer->id,
            'cost'              => 75,
            'currency'          => 'AED',
            'invoice_no'        => 'SCW-771',
            'invoice_date'      => now()->toDateString(),
            'receipt_disk'      => 'public',
            'receipt_key'       => 'vehicles/wash-receipts/scw-771.jpg',
        ]);

        $event = app(FinancialEventBuilder::class)->syncFor($external->fresh(['vehicle', 'vendor']), $this->actor);
        $this->assertSame(ExpenseType::CAR_WASH, $event->expense_type);
        $this->assertSame(Status::READY, $event->status);

        // Our own bay, our own staff — nobody billed us, so there is no obligation and no nil bill.
        $internal = VehicleWashJob::create([
            'vehicle_id'        => $this->vehicle->id,
            'washed_at'         => now(),
            'wash_type'         => VehicleWashJob::TYPE_EXTERIOR,
            'performed_by_kind' => VehicleWashJob::BY_INTERNAL,
        ]);

        $this->assertNull($internal->financialExpenseType());
        $this->assertNull(app(FinancialEventBuilder::class)->syncFor($internal, $this->actor));
        $this->assertSame(1, FinancialEvent::count(), 'the internal wash must not raise an event');
    }

    /** Recording a wash marks the car clean through the SAME field the readiness gate reads. */
    public function test_recording_a_wash_marks_the_car_clean(): void
    {
        $this->vehicle->update(['cleaning_status' => 'dirty']);

        app(\App\Services\VehicleOperatingCostService::class)->recordWash(
            $this->vehicle,
            ['performed_by_kind' => VehicleWashJob::BY_INTERNAL, 'wash_type' => VehicleWashJob::TYPE_FULL],
            $this->actor
        );

        $this->assertSame('clean', $this->vehicle->fresh()->cleaning_status);
        $this->assertDatabaseHas('vehicle_log_events', [
            'vehicle_id' => $this->vehicle->id,
            'event_type' => VehicleLogEvent::EVENT_CLEANING_UPDATED,
        ]);
    }

    // ── REGISTRATION ──────────────────────────────────────────────────────────────────────────────

    public function test_a_registration_renewal_raises_an_expense_claim(): void
    {
        $this->mapType(ExpenseType::REGISTRATION, self::ODOO_ACCOUNT_REGISTRATION, OdooDocumentType::EXPENSE);
        app(OdooMappingService::class)->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);

        $registration = VehicleRegistration::create([
            'vehicle_id'           => $this->vehicle->id,
            'chasis_no'            => $this->vehicle->vin,
            'expiry_date'          => now()->addYear()->toDateString(),
            'renewal_cost'         => 1200,
            'renewal_currency'     => 'AED',
            'renewal_date'         => now()->toDateString(),
            'renewal_reference'    => 'RTA-2026-5510',
            'renewal_receipt_disk' => 'public',
            'renewal_receipt_key'  => 'vehicles/registration-receipts/rta-5510.pdf',
        ]);

        $event = app(FinancialEventBuilder::class)->syncFor($registration->fresh(['vehicle', 'renewalVendor']), $this->actor);

        $this->assertSame(ExpenseType::REGISTRATION, $event->expense_type);
        $this->assertSame(OdooDocumentType::EXPENSE, $event->odoo_document_type);
        // An expense claim needs no supplier — that is the point of it being an expense, not a bill.
        $this->assertNull($event->vendor_id);
        $this->assertSame(Status::READY, $event->status);

        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);
        $this->assertSame(Status::SYNCED, $synced->status);
        $this->assertSame('hr.expense', $synced->odoo_document_model);

        $doc = $this->odoo['docs'][$synced->odoo_document_id]['values'];
        $this->assertSame(self::ODOO_EMPLOYEE, $doc['employee_id']);
        $this->assertSame(self::ODOO_ACCOUNT_REGISTRATION, $doc['account_id']);
        // hr.expense carries our idempotency key in `reference`, not `ref`.
        $this->assertSame($synced->odooRef(), $doc['reference']);
    }

    /** The OM sync writes a closed list of fields — a renewal fee keyed by finance must survive it. */
    public function test_the_renewal_fee_is_not_a_field_the_officemanager_sync_writes(): void
    {
        $importerFields = (new \ReflectionClass(\App\Services\VehicleRegistrationImporter::class));
        $source = file_get_contents($importerFields->getFileName());

        foreach (['renewal_cost', 'renewal_vendor_id', 'renewal_reference', 'renewal_receipt_key'] as $column) {
            $this->assertStringNotContainsString(
                $column,
                $source,
                "VehicleRegistrationImporter must never write {$column} — a re-import would null a fee somebody keyed."
            );
        }
    }

    // ── TAXI ──────────────────────────────────────────────────────────────────────────────────────

    public function test_a_taxi_fare_on_a_movement_raises_an_expense_claim(): void
    {
        $this->mapType(ExpenseType::TAXI, self::ODOO_ACCOUNT_TAXI, OdooDocumentType::EXPENSE);
        app(OdooMappingService::class)->map($this->vehicle, self::ODOO_ANALYTIC, null, null, $this->actor);

        $task = LogisticsTask::create([
            'vehicle_id'        => $this->vehicle->id,
            'vehicle_plate'     => $this->vehicle->plate_no,
            'purpose'           => 'garage_drop',
            'destination'       => 'Garage ABC',
            'assigned_to_name'  => 'Driver One',
            'status'            => 'completed',
            'taxi_fare'         => 45,
            'taxi_currency'     => 'AED',
            'taxi_date'         => now()->toDateString(),
            'taxi_leg'          => LogisticsTask::TAXI_LEG_RETURN,
            'taxi_from'         => 'Garage ABC',
            'taxi_to'           => 'Office',
            'taxi_receipt_disk' => 'public',
            'taxi_receipt_key'  => 'vehicles/taxi-receipts/fare-1.jpg',
        ]);

        $event = app(FinancialEventBuilder::class)->syncFor($task->fresh(['vehicle']), $this->actor);

        $this->assertSame(ExpenseType::TAXI, $event->expense_type);
        $this->assertSame(45.00, (float) $event->amount);
        $this->assertNull($event->vendor_id, 'a fare is a claim, not a bill from a vendor');
        $this->assertSame(Status::READY, $event->status);

        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);
        $this->assertSame(Status::SYNCED, $synced->status);

        // The car rides along as the analytic account even though TAXI is not vehicle-bound, so the
        // cost of moving THIS car stays visible in Odoo.
        $doc = $this->odoo['docs'][$synced->odoo_document_id]['values'];
        $this->assertSame([(string) self::ODOO_ANALYTIC => 100], $doc['analytic_distribution']);
    }

    /**
     * A fare is NOT blocked by an unmapped vehicle. TAXI is deliberately outside VEHICLE_BOUND — the
     * cost is the driver's transport, not the car's running cost — so demanding an analytic account
     * would strand a legitimate claim behind a mapping that has nothing to do with it.
     *
     * (A logistics task always names a car — `vehicle_id` is NOT NULL — so the case being guarded is an
     * UNMAPPED vehicle, not an absent one. The analytic account is simply omitted from the document.)
     */
    public function test_a_taxi_fare_is_not_blocked_by_an_unmapped_vehicle(): void
    {
        $this->mapType(ExpenseType::TAXI, self::ODOO_ACCOUNT_TAXI, OdooDocumentType::EXPENSE);
        // The vehicle is deliberately left with no analytic-account mapping.

        $task = LogisticsTask::create([
            'vehicle_id'        => $this->vehicle->id,
            'vehicle_plate'     => $this->vehicle->plate_no,
            'purpose'           => 'errand',
            'destination'       => 'Office',
            'status'            => 'completed',
            'taxi_fare'         => 30,
            'taxi_currency'     => 'AED',
            'taxi_date'         => now()->toDateString(),
            'taxi_receipt_disk' => 'public',
            'taxi_receipt_key'  => 'vehicles/taxi-receipts/fare-2.jpg',
        ]);

        $event = app(FinancialEventBuilder::class)->syncFor($task->fresh(['vehicle']), $this->actor);

        $this->assertSame(Status::READY, $event->status);
        $this->assertNotContains('vehicle_analytic_account_missing', $this->codes($event));

        // And the document simply carries no analytic distribution — never a guessed one.
        $synced = app(FinancialEventSyncService::class)->sync($event, $this->actor);
        $this->assertSame(Status::SYNCED, $synced->status);
        $this->assertArrayNotHasKey(
            'analytic_distribution',
            $this->odoo['docs'][$synced->odoo_document_id]['values']
        );
    }

    // ── All seven, through one pipeline ───────────────────────────────────────────────────────────

    /**
     * The claim this whole integration rests on: seven different operational events, ONE pipeline.
     *
     * Not a formality — it is the test that fails the day somebody adds an eighth cost with its own
     * private path to Odoo.
     */
    public function test_every_expense_type_is_configured_and_routes_through_the_same_pipeline(): void
    {
        foreach (ExpenseType::ALL as $type) {
            $this->assertNotNull(
                ExpenseType::label($type),
                "{$type} must have a label"
            );
        }

        // Every type the seeder ships knows which document it becomes.
        $this->seedAllExpenseTypes();

        foreach (ExpenseType::ALL as $type) {
            $mapping = ExpenseTypeMapping::where('expense_type', $type)->first();
            $this->assertNotNull($mapping, "{$type} must have a mapping row");
            $this->assertTrue(
                OdooDocumentType::isValid($mapping->odoo_document_type),
                "{$type} must resolve to a valid Odoo document type"
            );
        }

        // And every producer implements the ONE contract — there is no second way in.
        foreach ([
            MaintenanceInvoice::class, Maintenance::class, FuelFill::class,
            VehicleWashJob::class, VehicleRegistration::class, LogisticsTask::class,
        ] as $producer) {
            $this->assertTrue(
                in_array(\App\Contracts\FinancialEventSource::class, class_implements($producer), true),
                "{$producer} must reach Odoo through FinancialEventSource, not a private path"
            );
        }
    }
}
