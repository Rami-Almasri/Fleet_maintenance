<?php

use App\Models\Maintenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Ticket → Many Invoices.
 *
 * Until now a maintenance ticket held a SINGLE invoice: the receipt total / variance / reconciliation
 * flag lived as columns on `maintenances`, and every part/labor line hung off the ticket directly. That
 * breaks the moment a car is worked in more than one garage on the same ticket — each garage hands us its
 * OWN bill, covering only the faults IT fixed. This migration makes the invoice a first-class row.
 *
 *   maintenance_invoices          — one garage bill: which garage, its receipt total, parts/labor split,
 *                                    variance + explanation, its OWN reconciliation status, receipt photo.
 *   maintenance_tasks.maintenance_invoice_id  — the fault↔invoice link. A fault belongs to at most one
 *                                    invoice (invoice→many faults, fault→one invoice); null = not billed yet.
 *   maintenance_line_items.maintenance_invoice_id — each cost line now belongs to an invoice.
 *
 * The ticket stays the AGGREGATE: `maintenances.cost` / `parts_total` / `labor_total` remain the sum of
 * the ticket's lines (which are unchanged — lines still carry `maintenance_id`), so every existing reader
 * of the ticket total keeps working. `reconciliation_status` on the ticket becomes a roll-up of its
 * invoices (pending until they are all reconciled). Backfill wraps each already-costed ticket in a single
 * "Invoice #1" so nothing looks empty after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Which garage issued this bill (the ticket's current vendor by default; a transfer means a
            // second invoice from a second garage). Nullable — a legacy/loose invoice may not name one.
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            // The garage's own invoice/reference number, when it prints one.
            $table->string('invoice_no', 120)->nullable();

            // Money — the itemised split (rolled up from this invoice's lines) and the printed receipt total
            // the lines are validated against. `receipt_total` may be null when only lines were keyed.
            $table->decimal('parts_total', 12, 2)->default(0);
            $table->decimal('labor_total', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);        // parts + labor — this invoice's grand total
            $table->decimal('receipt_total', 12, 2)->nullable(); // the printed grand total, hand-keyed
            $table->text('variance_explanation')->nullable();    // mandatory only when itemised ≠ receipt

            // Accounting bridge — each invoice reconciles on its own clock. pending → reconciled.
            $table->string('reconciliation_status', 24)->default(Maintenance::RECON_PENDING)->index();
            $table->timestamp('reconciliation_flagged_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();

            // Receipt photo (best-effort) — where it was stored + its disk, so the audit can show it.
            $table->string('receipt_photo_disk', 20)->nullable();
            $table->string('receipt_photo_key')->nullable();

            $table->text('notes')->nullable();

            // Audit — who recorded the invoice and when (mirrors the ticket's cost_recorded_by/at).
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            $table->index(['maintenance_id', 'reconciliation_status']);
        });

        // The fault↔invoice link. Nullable: a fault not yet billed simply has no invoice. nullOnDelete so
        // deleting an invoice un-bills its faults (returns them to "not invoiced") rather than dropping them.
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->foreignId('maintenance_invoice_id')
                ->nullable()
                ->after('maintenance_id')
                ->constrained('maintenance_invoices')
                ->nullOnDelete();
            $table->index('maintenance_invoice_id');
        });

        // Each cost line now belongs to an invoice. Nullable for the same reason + for legacy loose lines;
        // nullOnDelete keeps a line's cost on the ticket even if its invoice wrapper is removed.
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->foreignId('maintenance_invoice_id')
                ->nullable()
                ->after('maintenance_id')
                ->constrained('maintenance_invoices')
                ->nullOnDelete();
            $table->index('maintenance_invoice_id');
        });

        $this->backfill();
    }

    /**
     * Wrap every already-costed ticket in a single "Invoice #1" so the new model has no empty gaps:
     * the ticket's existing receipt/variance/reconciliation snapshot moves onto the invoice, and all of
     * the ticket's faults + lines are pointed at it. Tickets that were never costed are left untouched.
     */
    private function backfill(): void
    {
        DB::table('maintenances')
            ->where(function ($q) {
                $q->whereNotNull('receipt_total')
                    ->orWhere('cost_is_itemized', true)
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('maintenance_line_items')
                            ->whereColumn('maintenance_line_items.maintenance_id', 'maintenances.id');
                    });
            })
            ->orderBy('id')
            ->chunkById(200, function ($tickets) {
                foreach ($tickets as $t) {
                    $parts = (float) ($t->parts_total ?? 0);
                    $labor = (float) ($t->labor_total ?? 0);
                    $now   = Carbon::now();

                    $invoiceId = DB::table('maintenance_invoices')->insertGetId([
                        'maintenance_id'            => $t->id,
                        'vendor_id'                 => $t->vendor_id,
                        'invoice_no'                => $t->invoice_no,
                        'parts_total'               => $parts,
                        'labor_total'               => $labor,
                        'amount'                    => round($parts + $labor, 2),
                        'receipt_total'             => $t->receipt_total,
                        'variance_explanation'      => $t->variance_explanation,
                        'reconciliation_status'     => $t->reconciliation_status ?: Maintenance::RECON_PENDING,
                        'reconciliation_flagged_at' => $t->reconciliation_flagged_at,
                        'recorded_by'               => $t->cost_recorded_by,
                        'recorded_at'               => $t->cost_recorded_at ?: $t->updated_at,
                        'created_at'                => $now,
                        'updated_at'                => $now,
                    ]);

                    // Point all of this ticket's faults + lines at the one backfilled invoice.
                    DB::table('maintenance_tasks')
                        ->where('maintenance_id', $t->id)
                        ->update(['maintenance_invoice_id' => $invoiceId]);
                    DB::table('maintenance_line_items')
                        ->where('maintenance_id', $t->id)
                        ->update(['maintenance_invoice_id' => $invoiceId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->dropIndex(['maintenance_invoice_id']);
            $table->dropConstrainedForeignId('maintenance_invoice_id');
        });
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropIndex(['maintenance_invoice_id']);
            $table->dropConstrainedForeignId('maintenance_invoice_id');
        });
        Schema::dropIfExists('maintenance_invoices');
    }
};
