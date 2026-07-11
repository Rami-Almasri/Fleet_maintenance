<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Logistics Dispatch sets a car's operational_status to 'in_transit' while it's on a move (the live
 * "In Transit to {destination}" the grid renders alongside transit_destination). The enum was created
 * before that movement existed, so 'in_transit' was never one of its values — writing it truncated.
 * This adds it. Idempotent-safe to re-run; preserves the original NOT NULL + default 'available'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE vehicles MODIFY operational_status "
            . "ENUM('available','rented','maintenance','test','transfer','sale_prep','in_transit') "
            . "NOT NULL DEFAULT 'available'"
        );
    }

    public function down(): void
    {
        // Park any in-transit cars back to 'available' so the value fits the narrowed enum again.
        DB::table('vehicles')->where('operational_status', 'in_transit')->update(['operational_status' => 'available']);

        DB::statement(
            "ALTER TABLE vehicles MODIFY operational_status "
            . "ENUM('available','rented','maintenance','test','transfer','sale_prep') "
            . "NOT NULL DEFAULT 'available'"
        );
    }
};
