<?php

namespace App\Console\Commands;

use App\Models\MaintenanceTask;
use App\Services\DatabaseBackup;
use App\Services\EventClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move historical DAMAGE out of `fault` — the one-off data migration for the third kind.
 *
 * Every task predates the damage kind, so all of them are currently `fault`, `service` or `inspection`.
 * This walks the resolver-owned rows and promotes the ones whose wording is unambiguously damage.
 *
 * WHAT IT WILL AND WILL NOT DO
 *   • DETERMINISTIC ONLY. A row moves when its symptom EXACTLY names a `damage_catalog` row (or a label
 *     the sheet map declares damage). No fuzzy matching, no "contains the word scratch", no scoring.
 *   • NEVER GUESSES. Wording that merely looks damage-ish — "Interior problem / Chairs" is the live
 *     example, 122 rows, and could equally be a seat that will not adjust — is left as `fault` and
 *     flagged `needs_review` so a human decides. A wrong automatic answer here silently deletes a real
 *     fault from reliability, which is the exact failure this whole domain change exists to stop.
 *   • NEVER TOUCHES a user's own classification (`classification_source` = catalog or manual).
 *   • IDEMPOTENT. A second run writes nothing.
 *
 *   --dry-run      classify inside a rolled-back transaction and report, writing nothing
 *   --skip-backup  skip the pre-flight db:backup (scratch/rehearsal databases only)
 *   --review-only  do not promote anything; only flag the ambiguous rows for review
 *
 * @see docs/Service-Fault-Damage-Domain.md
 */
class EventKindReclassifyDamage extends Command
{
    protected $signature = 'events:reclassify-damage
                            {--dry-run : Report what would change, write nothing}
                            {--skip-backup : Skip the automatic pre-flight db:backup}
                            {--review-only : Flag ambiguous rows for review without promoting anything}';

    protected $description = 'Reclassify historical externally-caused damage from kind=fault to kind=damage';

    /**
     * Wording that is damage-SHAPED but genuinely ambiguous. These are never auto-promoted; they are
     * flagged for a human. Add to this list rather than to the catalog when you are unsure — the whole
     * point is that uncertainty is recorded, not resolved by a coin toss.
     */
    private const AMBIGUOUS = [
        'interior problem / chairs',
        'interior problem',
        'accessories',
        'accessories & mods',
    ];

    public function handle(EventClassificationService $classifier, DatabaseBackup $backup): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $reviewOnly = (bool) $this->option('review-only');

        if (! $dryRun && ! $this->option('skip-backup')) {
            $this->info('Pre-flight backup (safety gate before a live write)...');
            try {
                $this->line('  backup written: ' . $backup->run());
            } catch (\Throwable $e) {
                $this->error('Backup failed — aborting. ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        // Only rows the resolver owns. A catalog pick or an admin override is somebody's decision.
        $query = MaintenanceTask::query()
            ->whereNotIn('classification_source', [MaintenanceTask::CLS_CATALOG, MaintenanceTask::CLS_MANUAL])
            ->whereIn('kind', [MaintenanceTask::KIND_FAULT, MaintenanceTask::KIND_SERVICE]);

        $total     = (clone $query)->count();
        $promoted  = 0;
        $flagged   = 0;
        $samples   = [];

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Scanning {$total} resolver-owned task(s) for damage...");

        $dryRun ? DB::beginTransaction() : null;

        try {
            $query->chunkById(500, function ($tasks) use ($classifier, $reviewOnly, &$promoted, &$flagged, &$samples) {
                foreach ($tasks as $task) {
                    $symptom = mb_strtolower(trim((string) $task->symptom));
                    if ($symptom === '') {
                        continue;
                    }

                    // Ambiguous → flag, never move.
                    if (in_array($symptom, self::AMBIGUOUS, true)) {
                        if (! $task->needs_review) {
                            DB::table('maintenance_tasks')->where('id', $task->id)->update(['needs_review' => true]);
                            $flagged++;
                        }
                        continue;
                    }

                    if ($reviewOnly || $classifier->labelKind($task->symptom) !== MaintenanceTask::KIND_DAMAGE) {
                        continue;
                    }

                    // Deterministic hit — take the catalog id with it so the row is fully typed, not just
                    // relabelled. classifyFromFinding returns the complete attribute set.
                    $attrs = $classifier->classifyFromFinding(['text' => $task->symptom]);
                    if (! $attrs || $attrs['kind'] !== MaintenanceTask::KIND_DAMAGE) {
                        continue;
                    }

                    // Clear EVERY catalog column, then set the damage one — derived from KIND_CATALOG_FK
                    // rather than hand-listed, because this update goes through the query builder and so
                    // bypasses the model's exactly-one-catalog guard.
                    DB::table('maintenance_tasks')->where('id', $task->id)->update(
                        array_fill_keys(array_values(MaintenanceTask::KIND_CATALOG_FK), null) + [
                            'kind'              => MaintenanceTask::KIND_DAMAGE,
                            'damage_catalog_id' => $attrs['damage_catalog_id'],
                            // Provenance stays `resolver`: this was a rule, not somebody's pick.
                            'needs_review'      => false,
                        ]
                    );
                    $promoted++;

                    if (count($samples) < 15) {
                        $samples[$task->symptom] = ($samples[$task->symptom] ?? 0) + 1;
                    }
                }
            });
        } finally {
            $dryRun ? DB::rollBack() : null;
        }

        $this->newLine();
        $this->line(sprintf(
            '  %spromoted to damage: %d · flagged ambiguous for review: %d',
            $dryRun ? '[DRY-RUN] would have ' : '',
            $promoted,
            $flagged
        ));

        if ($samples) {
            $this->newLine();
            $this->line('  sample wordings promoted:');
            foreach ($samples as $wording => $n) {
                $this->line('    ' . str_pad(mb_substr($wording, 0, 40), 42) . $n);
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Done. (nothing written)' : 'Done.');

        return self::SUCCESS;
    }
}
