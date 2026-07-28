<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2, Step P2-1 (blueprint §3a–e) — the RFQ / multi-supplier procurement substrate. Schema only:
 * additive, nothing existing changes behaviour.
 *
 *  - part_rfqs      : the RFQ PROCESS header (not a single part). One RFQ covers one-or-many lines.
 *  - rfq_lines      : one line per part_request; award happens PER LINE (an RFQ can split across suppliers).
 *  - supplier_quotes: a supplier's bid on a line — the multi-supplier comparison lives here.
 *  - part_purchases : promoted to the PO record — linked to the awarded rfq_line + supplier_quote (+ po_number).
 *  - vendors        : a supplier's default lead time, to pre-fill quote ETAs / flag SLA misses later.
 *
 * Note: rfq_lines.awarded_quote_id has NO DB foreign key (it would be circular with supplier_quotes.rfq_line_id);
 * the app (ProcurementService, a later P2 step) owns that link. All new part_purchases/vendors columns are
 * nullable → existing rows and the single-supplier purchase flow are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_rfqs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('open')->index(); // open → partially_awarded → awarded → closed / cancelled
            $table->date('needed_by_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rfq_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_rfq_id')->constrained('part_rfqs')->cascadeOnDelete();
            $table->foreignId('part_request_id')->constrained('part_requests')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            // The winning quote for THIS line — no DB FK (circular with supplier_quotes); the app owns the link.
            $table->unsignedBigInteger('awarded_quote_id')->nullable()->index();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('awarded_by_name')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();
            $table->index('part_request_id');
        });

        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_line_id')->constrained('rfq_lines')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('currency', 3)->default('AED');
            // A quote promises delivery as a lead time and/or an explicit date — either feeds the ETA.
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 10)->default('pending')->index(); // pending → submitted → selected / declined
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_by_name')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['rfq_line_id', 'vendor_id']);
        });

        // part_purchases becomes the PO record: which awarded line/quote it fulfils + a human PO reference.
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->foreignId('rfq_line_id')->nullable()->after('part_request_id')->constrained('rfq_lines')->nullOnDelete();
            $table->foreignId('supplier_quote_id')->nullable()->after('rfq_line_id')->constrained('supplier_quotes')->nullOnDelete();
            $table->string('po_number', 40)->nullable()->after('supplier_quote_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedSmallInteger('default_lead_time_days')->nullable()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->dropForeign(['rfq_line_id']);
            $table->dropForeign(['supplier_quote_id']);
            $table->dropColumn(['rfq_line_id', 'supplier_quote_id', 'po_number']);
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('default_lead_time_days');
        });
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('part_rfqs');
    }
};
