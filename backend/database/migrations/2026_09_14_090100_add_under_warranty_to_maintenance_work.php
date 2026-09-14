<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Was this work done under warranty?" — one question, answered on the work itself.
 *
 * This is the whole of the warranty feature's write side. Not a case, not a claim, not a coverage
 * verdict: a yes/no on the thing that was actually done, plus who honoured it, so the car's timeline
 * can read *"Brake sensor replaced — Done under warranty — Provider: BMW"* two years from now.
 *
 * ── WHY THREE TABLES AND NOT ONE ────────────────────────────────────────────────────────────────
 *
 * Because "maintenance work" is genuinely three different records in this system, and the question
 * has to be answerable wherever the work was written down:
 *
 *   maintenance_line_items   a repair or a part replacement — the billed line. "Brake sensor
 *                            replaced" is a line item; this is where most answers will live.
 *   maintenance_tasks        a FAULT being fixed. A fault can be resolved with no line item behind
 *                            it (the garage absorbed it, or the work was never itemised), and that
 *                            is exactly the case most likely to have been a warranty job.
 *   service_records          a SERVICE — an oil change, a filter. Scheduled upkeep, not a fault,
 *                            and it has its own table for that reason.
 *
 * A single shared table would have meant a polymorphic join on every timeline read to answer a
 * boolean. Two columns on each of the three is cheaper to read, cheaper to write and impossible to
 * get wrong.
 *
 * ── IT CHANGES NOTHING ABOUT MONEY. THIS IS THE LOAD-BEARING CONSTRAINT. ────────────────────────
 *
 * `under_warranty` is CLASSIFICATION AND AUDIT ONLY. It does not zero a cost, does not skip an
 * invoice, does not move a figure between accounts, and nothing in the financial layer reads it.
 * A warranty job with a recorded cost of 400 AED keeps that cost: either the garage really did
 * charge us and the warranty covered part of it, or somebody mistyped — and both of those are
 * findable precisely because the number was left alone. The moment this flag starts editing money
 * it becomes a second, silent accounting path, and the one thing this fleet's cost data cannot
 * afford is a second path. [[ticket-cost-journey]]
 *
 * `warranty_provider` is copied as TEXT from the car's live warranty at the moment the work is
 * recorded — not a foreign key to `warranties`. The warranty row can later be corrected, voided or
 * deleted; what the timeline must still say is who honoured the job on the day. Same discipline as
 * every other frozen name in this codebase.
 */
return new class extends Migration
{
    /** The three places maintenance work is recorded. @see the class note for why it is three. */
    private const TABLES = ['maintenance_line_items', 'maintenance_tasks', 'service_records'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'under_warranty')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                /**
                 * Default FALSE, not null.
                 *
                 * Every row that already exists was recorded before anyone was asked, and "nobody
                 * asked" is operationally the same as "not a warranty job" — there is no claim, no
                 * provider and no story attached to any of them. A nullable tri-state would invite a
                 * report to distinguish "no" from "unanswered" on history where the distinction is
                 * not real, and that is how an honest column starts telling a story it cannot back.
                 */
                $t->boolean('under_warranty')->default(false)->after('id');

                /**
                 * WHO honoured it, frozen as text. Null whenever under_warranty is false, and also
                 * legitimately null when it is true but the person recording it did not know or care
                 * to say which provider — a yes with no name is still a useful yes.
                 */
                $t->string('warranty_provider', 160)->nullable()->after('under_warranty');

                // "Show me everything this fleet had done under warranty" — the only query this
                // feature needs to answer, and the only reason there is an index at all.
                $t->index('under_warranty', $table . '_under_warranty_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'under_warranty')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_under_warranty_idx');
                $t->dropColumn(['under_warranty', 'warranty_provider']);
            });
        }
    }
};
