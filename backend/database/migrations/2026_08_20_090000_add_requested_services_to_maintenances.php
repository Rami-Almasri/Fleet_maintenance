<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FOURTH WAY OF ANSWERING "WHY IS THIS CAR GOING IN?" — it is due for a service.
 *
 * The statement columns added in 2026_08_19_090000 gave the requester three answers: name the fault,
 * pick a reason, write a note. All three assume something is WRONG. A very large share of the cars that
 * go to a garage have nothing wrong with them at all — the oil is due, the tyres want rotating, the A/C
 * wants its annual service. Until now the only way to say that was the reason code `scheduled_service`
 * ("Booked service work"), which records that a service is due and NOT WHICH ONE, so the supervisor
 * receives a ticket with no work on it and the garage is told over the phone.
 *
 *   requested_services — mode `service`: the picked service types, each row
 *                        { text, slug, service_catalog_id, category_key }. Sourced from the live
 *                        ServiceCatalog (kind = service), which is the SAME vocabulary the workshop
 *                        already completes services against, so an oil change asked for here and an oil
 *                        change closed at the garage are the one catalog row.
 *
 * DELIBERATELY ITS OWN COLUMN, not a few extra entries in `reported_faults`. Everything that reads
 * reported_faults reads it as a claim that something FAILED — Top Faults, recurrence, health scoring,
 * the "is it this again?" strip. A due oil change is a schedule working, not a car breaking, and putting
 * it in that column would make every one of those readers wrong at once. See
 * docs/Service-vs-Fault-Domain-Separation.md.
 *
 * Null on every row created before this existed, which reads correctly as "no service was named".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('requested_services')->nullable()->after('request_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('requested_services');
        });
    }
};
