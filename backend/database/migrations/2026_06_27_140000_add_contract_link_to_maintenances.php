<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-stage workflow support: the contract LINK a maintenance ticket gets when Logistics dispatches
 * it. Stored in its own column, NOT the existing `contract_id` — that column carries a UNIQUE index
 * (the 1:1 maintenance-contract HEADER relationship), so a workflow ticket reusing it would collide
 * with the header row of the very contract we're linking to. `linked_contract_id` is a plain,
 * nullable association: the ticket points at the relevant open maintenance (type-'U') contract once
 * the repair is dispatched, without pretending to BE that contract's header.
 *
 * The new workflow states (inspection_diagnostic, diagnostic_cleared) need no column change — they
 * fit the existing varchar `workflow_status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('linked_contract_id')->nullable()->after('contract_id')->constrained('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('linked_contract_id');
        });
    }
};
