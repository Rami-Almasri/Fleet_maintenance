<?php

use App\Models\Maintenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `maintenance_tasks.category_key`, which no writer has ever set.
 *
 * The column has existed since the table did and was NULL on every one of the 108 rows written to it.
 * Both creation paths read it off the caller with `?? null` and nothing upstream supplied it, so the
 * failure looked exactly like "these faults have no category" — which is why nobody noticed.
 *
 * It is the middle tier of the repair-history matcher (fault_catalog_id → category_key → symptom), so
 * every lookup fell through to an exact symptom-string match and the "Previous Similar Repairs" panel
 * reported nothing for faults this fleet has repaired hundreds of times.
 *
 * A MIGRATION rather than a command because it must happen exactly once, on every environment, without
 * anyone remembering to run it. [[MaintenanceTask]]'s creating hook now stamps the category on new rows,
 * so this only ever has to catch up the history.
 *
 * SAFE TO RE-RUN and safe to interrupt: it only ever fills rows where the column is NULL, and it never
 * overwrites a value a human or a caller put there.
 *
 * EXACT CATALOG MATCH ONLY. A symptom the catalog does not recognise — a custom issue somebody typed —
 * stays NULL. Guessing a category for it would route the fault to the wrong garage, and these are real
 * tickets: a wrong answer here is worse than the honest gap that has been there all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filled  = 0;
        $skipped = 0;

        DB::table('maintenance_tasks')
            ->whereNull('category_key')
            ->whereNotNull('symptom')
            ->where('symptom', '!=', '')
            ->select(['id', 'symptom'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$filled, &$skipped) {
                foreach ($rows as $row) {
                    $category = Maintenance::categoryForKeyword($row->symptom);

                    if ($category === null) {
                        $skipped++;

                        continue;
                    }

                    // Written straight through the query builder: the model's saving hooks assert
                    // classification integrity on the CURRENT row shape, and replaying them across
                    // historical rows would fail the migration on data it is not here to fix.
                    DB::table('maintenance_tasks')->where('id', $row->id)->update(['category_key' => $category]);
                    $filled++;
                }
            });

        // Reported rather than silent — the skipped count is the honest measure of how much of the
        // history is free text the catalog cannot name, and it is the number worth watching. Quiet on a
        // fresh database (every Crud test migrates from scratch) so the signal stays a signal.
        if ($filled > 0 || $skipped > 0) {
            echo "  Backfilled category_key on {$filled} task(s); {$skipped} left NULL (symptom not in the findings catalog).".PHP_EOL;
        }
    }

    public function down(): void
    {
        // Deliberately NOT reversible. Nulling the column again would discard categories that new
        // writes have legitimately set since, and the column's pre-migration state — empty — is not
        // worth restoring.
    }
};
