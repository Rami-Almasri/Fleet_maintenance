<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty paperwork reuses the vehicle document trail rather than inventing a second one.
 *
 * vehicle_documents already owns everything a stored file needs in this system — the disk, the key,
 * the mime type, the uploader, the version chain and a `viewUrl()` that degrades to "preview
 * unavailable" instead of throwing. A warranty certificate is a scan belonging to a car; a claim's
 * repair invoice is a scan belonging to a car. Neither needs a new disk, a new upload endpoint or a
 * second set of storage rules, and having two would guarantee that one of them eventually loses a
 * file nobody notices is gone.
 *
 * WHAT IS ACTUALLY MISSING is only the pointer: which warranty, or which case, a scan belongs to.
 * Both nullable, because the overwhelming majority of rows in this table are Mulkiyas that belong to
 * neither, and nullOnDelete because tidying a warranty away must never take the scan of the
 * manufacturer's contract with it — that document is the evidence, and it outlives our record of it.
 *
 * VERSIONING DOES NOT APPLY THE SAME WAY HERE, and that is deliberate rather than an oversight. A
 * Mulkiya is a SLOT: exactly one card is in force, so a replacement supersedes its predecessor. A
 * warranty file is a DOSSIER: the certificate, the purchase invoice, the dealer's authorisation
 * email and four photographs of a cracked housing all coexist, and none supersedes another. Warranty
 * documents are therefore never stamped `superseded_at`, so `scopeCurrent()` returns all of them,
 * which is the correct answer for a dossier and the wrong one for a slot. The distinction lives in
 * how the two kinds are written, not in a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * GUARDED, because vehicle_documents belongs to another feature and may not exist yet on
         * every schema this runs against (it does not, today, on fleet_test). Warranty paperwork is
         * an ENHANCEMENT to the warranty layer, not a prerequisite for it: cases, coverage decisions
         * and the procurement gate all work without a single file attached. Failing the whole
         * migration because an optional attachment point is missing would take the load-bearing half
         * of the feature down with the decorative half.
         */
        if (! Schema::hasTable('vehicle_documents')) {
            return;
        }

        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->foreignId('warranty_id')->nullable()->after('kind')
                ->constrained('warranties')->nullOnDelete();
            $table->foreignId('warranty_claim_id')->nullable()->after('warranty_id')
                ->constrained('warranty_claims')->nullOnDelete();

            // "Show me this warranty's paperwork" / "show me this case's evidence" — one indexed read
            // each, which is what lets the dossier render inline instead of behind a second request.
            $table->index('warranty_id', 'vehicle_documents_warranty_idx');
            $table->index('warranty_claim_id', 'vehicle_documents_warranty_claim_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicle_documents')) {
            return;
        }

        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->dropIndex('vehicle_documents_warranty_idx');
            $table->dropIndex('vehicle_documents_warranty_claim_idx');
            $table->dropConstrainedForeignId('warranty_id');
            $table->dropConstrainedForeignId('warranty_claim_id');
        });
    }
};
