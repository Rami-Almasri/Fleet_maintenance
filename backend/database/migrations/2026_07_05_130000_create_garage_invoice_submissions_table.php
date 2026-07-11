<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garage Invoice Portal — outside garages submit their own itemised invoice through a secure, tokenised
 * public link (no login), and it lands here as a REVIEW-QUEUE record, never touching the ticket's real
 * cost until the team audits and accepts it.
 *
 * One row = one invitation/submission for a ticket:
 *   - `token` + `expires_at`   → the short-lived secure link the garage opens (single ticket, unguessable).
 *   - lifecycle `status`        → pending (link issued) → submitted (garage sent it, ticket "Awaiting Audit")
 *                                 → accepted (applied to the ticket) / rejected / cancelled / expired.
 *   - the submitted payload     → a SNAPSHOT of the parts/labor lines + totals + receipt total/variance +
 *                                 receipt photo, held here (not in maintenance_line_items) until accepted.
 *   - audit                     → who issued the link, when/where the garage submitted, who reviewed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_invoice_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();

            // Secure link — an unguessable per-ticket token with an expiry. Treated as a secret.
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();

            // Lifecycle. Kept as a short string (not an enum) so states can grow without a migration.
            $table->string('status', 20)->default('pending')->index();

            // Submitted payload — a self-contained snapshot so the review shows exactly what the garage
            // sent, independent of any later edits. Lines mirror the maintenance_line_items shape.
            $table->json('line_items')->nullable();
            $table->decimal('parts_total', 12, 2)->nullable();
            $table->decimal('labor_total', 12, 2)->nullable();
            $table->decimal('itemized_total', 12, 2)->nullable();   // parts + labor, as the garage entered it
            $table->decimal('receipt_total', 12, 2)->nullable();    // the printed grand total they keyed
            $table->decimal('variance', 12, 2)->nullable();         // itemized − receipt (signed)
            $table->text('variance_explanation')->nullable();
            $table->text('garage_note')->nullable();

            // Receipt photo (best-effort): where it was stored + its disk, so the review can show it.
            $table->string('receipt_photo_disk', 20)->nullable();
            $table->string('receipt_photo_key')->nullable();

            // Audit trail
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // team member who issued the link
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // The board asks "which tickets are awaiting audit?" — one submitted row per ticket at a time.
            $table->index(['maintenance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_invoice_submissions');
    }
};
