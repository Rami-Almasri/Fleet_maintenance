<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The (contract_no, contract_type) UNIQUE was a Google-Sheets assumption. The API's
 * real identity is ContractSerial (stored as external_id "OM:{serial}"), and the
 * same ContractNo can legitimately recur. Drop the unique, keep a plain index for
 * lookups; de-duplication is handled by external_id on import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique('contracts_contract_no_contract_type_unique');
            $table->index(['contract_no', 'contract_type']);
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['contract_no', 'contract_type']);
            $table->unique(['contract_no', 'contract_type']);
        });
    }
};
