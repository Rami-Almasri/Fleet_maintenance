<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * supplier_payments + payment_allocations — paying for what we bought, recorded the same way everything
 * else in this platform is: as documents, with the money summed from rows rather than typed beside them.
 *
 * Until now a payment was three columns on the invoice (`paid_amount`, `paid_at`, `payment_reference`).
 * That works for one payment and quietly loses the truth on the second: the amount accumulates, but the
 * date and reference of the earlier payment are overwritten, so "when did we pay, and against what
 * transfer?" becomes unanswerable — the exact class of un-auditable figure this whole design removes.
 *
 * ── One payment, many invoices ──────────────────────────────────────────────────────────────────────
 * A real settlement is rarely one invoice. You transfer AED 5,000 to a supplier covering three bills. So
 * a payment is a HEADER with ALLOCATIONS, mirroring the supplier-invoice → purchases model that already
 * proved itself: the document exists once, and is split across what it settles.
 *
 *     supplier_payment (AED 5,000, TRF-9931)
 *       ├── allocation → supplier invoice INV-1   AED 2,000
 *       ├── allocation → supplier invoice INV-2   AED 1,500
 *       └── allocation → garage invoice   G-77    AED 1,500
 *
 * `maintenance_invoices.paid_amount` / `part_invoices.paid_amount` therefore stop being written by hand
 * and become the SUM of a document's allocations — one ledger for payments, exactly as
 * maintenance_line_items is the one ledger for cost.
 *
 * An allocation deliberately targets either invoice kind, because the fleet pays garages and suppliers
 * out of the same bank account and asking "what do we still owe, in total" should not require two
 * different reports that have to be added up by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();

            // WHO was paid. A vendor row when we have one (supplier or garage — both are paid the same
            // way); payee_name is the free-text fallback for a one-off.
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('payee_name')->nullable();

            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('AED');

            // HOW it was paid — the first thing finance asks when reconciling against the bank.
            $table->string('method', 20);                    // see SupplierPayment::METHODS
            $table->string('reference', 120)->nullable();    // transfer id / cheque no / receipt no
            $table->text('notes')->nullable();

            // Evidence: the transfer screenshot or the stamped receipt.
            $table->string('photo_disk', 20)->nullable();
            $table->string('photo_key')->nullable();

            // A payment is an EVENT, not an obligation, so its life is simpler than an invoice's: it
            // happened, or it was voided because it was keyed wrong or the transfer bounced.
            $table->string('status', 12)->default('recorded'); // 'recorded' | 'cancelled'
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'payment_date']);
            $table->index(['status', 'payment_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();

            // Which bill this slice settles. Two kinds, one table — see the class note above.
            $table->string('document_type', 24);   // 'supplier_invoice' | 'garage_invoice'
            $table->unsignedBigInteger('document_id');

            $table->decimal('amount', 12, 2);

            $table->timestamps();

            // The index the roll-up reads: "everything ever allocated to this document".
            $table->index(['document_type', 'document_id']);
            // A payment may not hit the same bill twice; adjust the existing slice instead.
            $table->unique(['supplier_payment_id', 'document_type', 'document_id'], 'payment_document_unique');
        });

        // Any payment already recorded through the old column-only path is preserved as a real payment
        // record, so history does not develop a hole at the moment the better model arrives.
        $this->backfillExistingPayments();
    }

    /**
     * Turn each invoice that already carries a paid_amount into a proper payment + allocation. The date
     * and reference we have are the only ones that were ever captured, so they transfer exactly; nothing
     * is invented.
     */
    private function backfillExistingPayments(): void
    {
        foreach ([
            ['table' => 'part_invoices', 'type' => 'supplier_invoice'],
            ['table' => 'maintenance_invoices', 'type' => 'garage_invoice'],
        ] as $source) {
            DB::table($source['table'])
                ->where('paid_amount', '>', 0)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($source) {
                    foreach ($rows as $row) {
                        $paymentId = DB::table('supplier_payments')->insertGetId([
                            'vendor_id'        => $row->vendor_id ?? null,
                            'payment_date'     => $row->paid_at ? substr($row->paid_at, 0, 10) : now()->toDateString(),
                            'amount'           => $row->paid_amount,
                            'currency'         => 'AED',
                            'method'           => 'unknown',
                            'reference'        => $row->payment_reference ?? null,
                            'notes'            => 'Migrated from the invoice’s payment columns (2026-08-04).',
                            'status'           => 'recorded',
                            'recorded_by'      => $row->paid_by ?? null,
                            'recorded_at'      => $row->paid_at ?? now(),
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);

                        DB::table('payment_allocations')->insert([
                            'supplier_payment_id' => $paymentId,
                            'document_type'       => $source['type'],
                            'document_id'         => $row->id,
                            'amount'              => $row->paid_amount,
                            'created_at'          => now(),
                            'updated_at'          => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
