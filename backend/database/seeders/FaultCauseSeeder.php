<?php

namespace Database\Seeders;

use App\Models\FaultCause;
use Illuminate\Database\Seeder;

/**
 * Upserts the curated Symptom → Root-Cause library from config/fault_causes.php into the
 * fault_causes table. Idempotent: matched on (symptom_key, root_cause), so re-running only fills
 * gaps and refreshes labels/category — it never duplicates, never demotes an already-approved row,
 * and never touches user-submitted ('pending') rows (those live only in the DB).
 *
 * Run standalone after deploy:  php artisan db:seed --class=FaultCauseSeeder
 */
class FaultCauseSeeder extends Seeder
{
    public function run(): void
    {
        $blocks = config('fault_causes.symptoms', []);
        $count = 0;

        foreach ($blocks as $block) {
            $symptomLabel = trim((string) ($block['symptom'] ?? ''));
            if ($symptomLabel === '') {
                continue;
            }
            $symptomKey = FaultCause::normalizeKey($symptomLabel);
            $category   = $block['category'] ?? null;

            foreach (($block['causes'] ?? []) as $cause) {
                $cause = trim((string) $cause);
                if ($cause === '') {
                    continue;
                }

                FaultCause::updateOrCreate(
                    ['symptom_key' => $symptomKey, 'root_cause' => $cause],
                    [
                        'symptom_label' => $symptomLabel,
                        'category_key'  => $category,
                        // Seeded rows are authoritative and always live. updateOrCreate only sets
                        // these on the matched row, so a curated cause is never left 'pending'.
                        'status'        => FaultCause::STATUS_APPROVED,
                        'source'        => FaultCause::SOURCE_SEED,
                    ],
                );
                $count++;
            }
        }

        $this->command?->info("FaultCauseSeeder: upserted {$count} symptom→cause mappings.");
    }
}
