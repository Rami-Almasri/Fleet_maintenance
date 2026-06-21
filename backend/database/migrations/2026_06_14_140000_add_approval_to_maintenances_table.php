<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill-approval control: a maintenance job over the threshold (default AED 500)
 * must be approved before it's treated as final.
 *   approval_status: not_required | pending | approved
 *   approved_amount: the job cost at the moment it was approved (re-flag if it rises)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('approval_status')->default('not_required')->after('maintenance_reason_id');
            $table->decimal('approved_amount', 12, 2)->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_amount');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approved_amount', 'approved_at']);
        });
    }
};
