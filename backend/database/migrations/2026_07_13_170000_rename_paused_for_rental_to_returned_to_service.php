<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generalise the same-day "paused_for_rental" concept into "paused_returned_to_service" — the pause
 * is not rental-specific (any operational reason can pull a mid-repair car back into service). Pure
 * string rename, no schema shape change. Shipped same-day, effectively no prod data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('maintenances')->where('workflow_status', 'paused_for_rental')
            ->update(['workflow_status' => 'paused_returned_to_service']);
        DB::table('maintenances')->where('paused_from_status', 'paused_for_rental')
            ->update(['paused_from_status' => 'paused_returned_to_service']);

        DB::table('vehicle_log_events')->where('event_type', 'paused_for_rental')
            ->update(['event_type' => 'returned_to_service']);
        DB::table('vehicle_log_events')->where('workflow_status', 'paused_for_rental')
            ->update(['workflow_status' => 'paused_returned_to_service']);
    }

    public function down(): void
    {
        DB::table('maintenances')->where('workflow_status', 'paused_returned_to_service')
            ->update(['workflow_status' => 'paused_for_rental']);
        DB::table('maintenances')->where('paused_from_status', 'paused_returned_to_service')
            ->update(['paused_from_status' => 'paused_for_rental']);

        DB::table('vehicle_log_events')->where('event_type', 'returned_to_service')
            ->update(['event_type' => 'paused_for_rental']);
        DB::table('vehicle_log_events')->where('workflow_status', 'paused_returned_to_service')
            ->update(['workflow_status' => 'paused_for_rental']);
    }
};
