<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Exchange chaining.
 *
 * Purely ADDITIVE: a single self-referencing nullable FK that links a "swap" contract
 * back to the one it replaced. A customer who returns car A and drives off in car B
 * within ~48h has B.parent_contract_id = A.id, so a whole swap history forms a chain
 * (root → child → child). NULL = a normal standalone rental (the overwhelming majority),
 * so this changes nothing about the existing OfficeManager / sheet sync, which matches on
 * (contract_no, contract_type).
 *
 * onDelete is nullOnDelete (NOT cascade): removing a parent must never delete its
 * children — it just detaches them so they become new chain roots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('parent_contract_id')->nullable()->after('customer_id')
                ->constrained('contracts')->nullOnDelete();
            // who linked the exchange and when — the link is a human decision, not synced
            // data, so we keep a light audit trail separate from OfficeManager fields.
            $table->timestamp('exchange_linked_at')->nullable()->after('parent_contract_id');
            $table->string('exchange_linked_by')->nullable()->after('exchange_linked_at');
            // amount carried over from the parent (deposit / remaining credit) when the user
            // hits "Link & Carry Balance". Kept in OUR column, never written by the OfficeManager
            // sync, so it survives re-imports that overwrite the contract_* financial fields.
            $table->decimal('carried_balance', 12, 2)->nullable()->after('exchange_linked_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_contract_id');
            $table->dropColumn(['exchange_linked_at', 'exchange_linked_by', 'carried_balance']);
        });
    }
};
