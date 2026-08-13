<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW MANY — the count of physical occurrences one fault record stands for.
 *
 * ── THE SEMANTIC THIS PRESERVES, AND WHY IT MATTERS ──────────────────────────────────────────────
 * A `maintenance_tasks` row is a ROUTABLE UNIT OF WORK: it moves between garages, carries its own
 * cost, its own confirmation verdict, its own repair-time ledger, its own recurrence link. That is
 * the domain's existing meaning and this column does not touch it.
 *
 * `quantity` is a different fact: how many physical instances of the fault that one unit of work
 * covers. Two scratches sent to one body shop as one job are ONE task with quantity 2 — not two
 * tasks. Turning quantity into a row count would have broken everything downstream that counts
 * tasks: fault KPIs, comeback rates, the 40/20/20/10/10 garage score, recurrence pairs, the
 * frozen operational baseline in [[operational-kpi-baseline]]. So it is stored beside the row,
 * never as more rows.
 *
 * Corollary, deliberately: NOTHING that counts faults today should start summing quantity. "How many
 * faults" and "how many scratches" are two questions and the platform can now answer both, separately.
 *
 * DEFAULT 1, NOT NULL. Every historical row means exactly one occurrence — that is not an assumption,
 * it is what a single un-counted fault record has always meant — so the default is the truth for all
 * of them and no backfill is needed. A nullable column would have forced every reader to write
 * `?? 1` and one of them would have forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maintenance_tasks') && ! Schema::hasColumn('maintenance_tasks', 'quantity')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->unsignedSmallInteger('quantity')->default(1)->after('symptom');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('maintenance_tasks') && Schema::hasColumn('maintenance_tasks', 'quantity')) {
            Schema::table('maintenance_tasks', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }
};
