<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "MUST THE PAPER BE ATTACHED BEFORE THIS MAY BE SENT?" — PER EXPENSE TYPE.
 *
 * The business rule is that a document-backed expense may not become READY until the supporting
 * document is actually attached. The default now lives in OdooDocumentType::REQUIREMENTS (a vendor bill
 * requires its scan, an expense claim requires its receipt), but a blanket rule is the wrong shape for
 * a fleet: a taxi fare of AED 20 and a AED 40,000 engine rebuild are both "document-backed" and do not
 * deserve the same insistence.
 *
 * So this is a NULLABLE OVERRIDE, and the three states are meaningfully different:
 *
 *   null   follow the document type's default (the normal case — one place to change the policy)
 *   true   always require the scan for this expense type, whatever its document type says
 *   false  never require it for this type
 *
 * Nullable rather than a boolean-with-default because "Finance has not expressed an opinion" is a real
 * state and must not be indistinguishable from "Finance decided no". A default of false would silently
 * switch the requirement off for every existing row the moment this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_type_mappings', function (Blueprint $table) {
            $table->boolean('requires_attachment')->nullable()->after('odoo_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_type_mappings', function (Blueprint $table) {
            $table->dropColumn('requires_attachment');
        });
    }
};
