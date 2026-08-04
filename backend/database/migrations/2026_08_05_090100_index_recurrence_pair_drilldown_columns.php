<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3 — index the columns the EVIDENCE DRILL-DOWN reads, not just the ones the aggregates group by.
 *
 * ── THE GAP, AND HOW IT HAPPENED ────────────────────────────────────────────────────────────────
 * fault_recurrence_pairs was indexed for the questions the SCORECARD asks. `first_vendor_id` is
 * indexed because that is what every garage aggregate groups by; `signature` and `days_to_return`
 * are indexed for the fault matrix and the gap distributions.
 *
 * But the click-through — pick a garage, click its comeback number, see the ACTUAL repairs behind it
 * — resolves through first_maintenance_id / next_maintenance_id, and neither had an index. That path
 * is not a footnote: being able to land on the underlying rows is the entire argument the Garage
 * Intelligence work makes. The one query a sceptical reader runs was the one doing a full scan.
 *
 * I indexed for the aggregate and forgot the drill-down. At 9,828 rows a scan is imperceptible, which
 * is exactly why this would have gone unnoticed until the corpus was large enough for it to hurt.
 *
 * ── WHY A PLAIN MIGRATION IS SAFE ON A TABLE THAT GETS REPLACED NIGHTLY ─────────────────────────
 * IntelligenceRebuildRecurrence builds into a staging table and swaps it in with an atomic RENAME,
 * so anything applied only to the live table would normally be discarded on the next rebuild. It
 * survives here because the staging table is created with:
 *
 *     CREATE TABLE <staging> LIKE <live>
 *
 * and LIKE copies the full index definition. The rebuild therefore inherits these indexes from the
 * next run onward, with no change to the command. (Were it CREATE TABLE ... AS SELECT, indexes would
 * NOT be copied and this would have to live in the command instead.)
 *
 * No foreign keys are added: FKs on a table that is dropped and re-created by RENAME would block the
 * swap. These stay projections, deliberately outside the referential graph.
 *
 * next_vendor_id is included for symmetry with first_vendor_id — "who did the car go to next" is the
 * other half of every comeback question, and answering it currently scans as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
            $table->index('first_maintenance_id', 'frp_first_maintenance_id_index');
            $table->index('next_maintenance_id', 'frp_next_maintenance_id_index');
            $table->index('next_vendor_id', 'frp_next_vendor_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('fault_recurrence_pairs', function (Blueprint $table) {
            $table->dropIndex('frp_first_maintenance_id_index');
            $table->dropIndex('frp_next_maintenance_id_index');
            $table->dropIndex('frp_next_vendor_id_index');
        });
    }
};
