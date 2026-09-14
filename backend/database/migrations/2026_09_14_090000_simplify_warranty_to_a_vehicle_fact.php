<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty stops being a system and goes back to being a FACT ABOUT A CAR.
 *
 * The previous cut modelled warranty as a cost-recovery department: an itemised coverage list per
 * promise, a decision engine that answered covered / not-covered / unknown per part, a case with a
 * twelve-rung ladder from coverage review to claim recovery, and a gate that refused purchase
 * requests until somebody adjudicated. It worked, it was tested, and it was far more machinery than
 * this fleet asked for or wants to operate.
 *
 * WHAT WAS ACTUALLY WANTED, and all that is wanted:
 *
 *   · a car can be under warranty — start, end, provider. Recorded from the car's own page.
 *   · when that car goes into maintenance, SAY SO. A recommendation, never a block.
 *   · when work is done, ask one question: was this done under warranty?
 *   · the timeline shows the answer.
 *
 * Nothing here decides coverage, because deciding coverage turned out to be a judgement a person
 * makes in a phone call, not something a database can hold. The columns below encoded that judgement
 * and are therefore dropped rather than left to rot half-populated — a nullable column that three
 * surfaces still read is worse than no column at all.
 *
 * WHAT SURVIVES, deliberately:
 *   · `warranties` itself, including kind='vehicle' — that IS the fact.
 *   · both expiry legs (months / kilometres, whichever first) — that is what makes "under warranty"
 *     true or false on a car that is driven 6,000 km a month, and it is one comparison, not a system.
 *   · provider_kind + the contact columns — "send it to the dealer" is useless without the number.
 *   · `warranty_claims` in its ORIGINAL form (a supplier claim against a part/repair warranty),
 *     which predates all of this and is a different, existing feature. Only the case-lifecycle
 *     columns added on top of it are removed.
 *
 * Every drop is guarded: this runs against schemas where the previous cut never landed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The itemised coverage list — the heart of the engine that is going away ──────────────
        // "Which parts does this booklet name?" is a question nobody in this fleet wants to answer
        // row by row, and an un-itemised list made every part read "unknown", which held purchases.
        $this->dropIfPresent('warranties', ['covered_catalog_ids', 'excluded_catalog_ids', 'coverage_notes']);

        // ── The case ladder on warranty_claims ───────────────────────────────────────────────────
        // coverage review → covered → authorization → dealer → claim → recovery → closed. The whole
        // workflow, removed. What is left is the original claim row: a supplier said yes or no.
        $this->dropForeignIfPresent('warranty_claims', ['decided_by', 'maintenance_task_id', 'vehicle_component_id', 'component_catalog_id', 'closed_by', 'updated_by']);
        $this->dropIndexIfPresent('warranty_claims', [
            'warranty_claims_stage_idx', 'warranty_claims_vehicle_stage_idx',
            'warranty_claims_subject_idx', 'warranty_claims_chase_idx',
        ]);
        $this->dropIfPresent('warranty_claims', [
            'stage', 'origin', 'coverage_verdict', 'coverage_reason_code',
            'decided_by_name', 'decided_at', 'subject', 'diagnosis',
            'authorization_ref', 'authorization_requested_at', 'authorized_at',
            'sent_to_provider_at', 'provider_response_due_on',
            'claim_reference', 'submitted_at', 'closed_at', 'closed_by_name',
            'avoided_amount', 'updated_by_name',
        ]);

        // ── The procurement gate's receipt on part_requests ──────────────────────────────────────
        // Nothing blocks a purchase any more, so there is no verdict to freeze and no override to
        // audit. A car under warranty is now surfaced as a recommendation at the maintenance step.
        $this->dropIndexIfPresent('part_requests', ['part_requests_warranty_verdict_idx']);
        $this->dropForeignIfPresent('part_requests', ['warranty_case_id', 'warranty_override_by']);
        $this->dropIfPresent('part_requests', [
            'warranty_verdict', 'warranty_reason_code',
            'warranty_override_by_name', 'warranty_override_at', 'warranty_override_reason',
        ]);

        // ── Warranty paperwork on vehicle_documents ──────────────────────────────────────────────
        // The dossier belonged to the case. With no case there is nothing to attach evidence to, and
        // a Mulkiya trail should not carry two columns nothing writes.
        $this->dropIndexIfPresent('vehicle_documents', ['vehicle_documents_warranty_idx', 'vehicle_documents_warranty_claim_idx']);
        $this->dropForeignIfPresent('vehicle_documents', ['warranty_id', 'warranty_claim_id']);

        /**
         * ── The four permissions that gated a workflow which no longer exists ────────────────────
         *
         * `warranty.review` / `.claim` / `.override` / `.close` were the teeth of the coverage
         * engine: who may adjudicate, who may chase a dealer, who may buy past a warranty. With
         * nothing to adjudicate and nothing blocked, they gate nothing.
         *
         * Deleted rather than left behind, because the seeder no longer lists them — so they would
         * sit in `permissions` forever as rows nothing grants and nothing checks, showing up on the
         * Users page as options that do nothing. Spatie cascades `role_has_permissions` and
         * `model_has_permissions`, so the grants go with them.
         *
         * `warranty.view` and `warranty.manage` survive: seeing a car's warranty and recording it
         * are still real actions. Guarded so this is safe on a schema where Spatie is absent.
         */
        if (Schema::hasTable('permissions')) {
            \Illuminate\Support\Facades\DB::table('permissions')
                ->whereIn('name', ['warranty.review', 'warranty.claim', 'warranty.override', 'warranty.close'])
                ->delete();
        }
    }

    /**
     * Irreversible by design, and said out loud rather than left as an empty method.
     *
     * down() cannot restore this: the columns held judgements people made (a coverage verdict, an
     * override reason) and re-adding them empty would resurrect the schema while silently discarding
     * the only part that mattered. Recovering the old shape means the previous migrations, from the
     * commits that introduced them.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }

    private function dropIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    /**
     * A foreign key must go before its column, and MySQL names it by convention. Wrapped because a
     * schema where the previous cut never landed has neither, and a missing constraint is the
     * expected case here rather than an error.
     */
    private function dropForeignIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$column]));
            } catch (\Throwable $e) {
                // No constraint on that column — nothing to undo.
            }

            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }
    }

    private function dropIndexIfPresent(string $table, array $indexes): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($indexes as $index) {
            try {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            } catch (\Throwable $e) {
                // Already absent.
            }
        }
    }
};
