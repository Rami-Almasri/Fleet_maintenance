<?php

namespace App\Models\Concerns;

use App\Services\PartIdentityService;

/**
 * Keeps a part record's IDENTITY columns filled, on every save, from whatever wording it was given.
 *
 * WHY THIS IS A MODEL HOOK AND NOT A SERVICE'S JOB. `part_name_key` is what makes a row visible to the
 * repeat-buy check: a purchase whose key is null can only be found by its exact stored spelling — and
 * once the check moved onto identity, by nothing at all. Leaving the column to callers means every new
 * write path (a seeder, a console command, an import, a test fixture, the next feature) is one
 * forgotten line away from writing a row the warning cannot see. That failure is silent and looks
 * exactly like "no repeats found", which is the answer this feature must never give wrongly.
 *
 * So the invariant lives where the write happens. Two rules, both narrow:
 *
 *   part_name_key        is ALWAYS recomputed from part_name. It is a pure function of the wording
 *                        (no database, no guessing), so there is nothing for a caller to know better.
 *
 *   component_catalog_id is only ever FILLED IN, never overwritten. A null one is resolved through
 *                        PartCatalogMatcher, which is strict — exact name, Arabic name, slug, curated
 *                        other-name, or a whole-phrase containment — and returns nothing rather than
 *                        guessing. A value already present was put there by someone who knew more than
 *                        this hook does (the buyer picked it from the list), and is left alone.
 *
 * The matcher is a singleton (see AppServiceProvider) so this costs one catalog scan per process, not
 * one per row.
 */
trait HasPartIdentity
{
    public static function bootHasPartIdentity(): void
    {
        static::saving(function ($model) {
            /** @var PartIdentityService $identity */
            $identity = app(PartIdentityService::class);

            $model->part_name_key = $identity->nameKey($model->part_name);

            if ($model->component_catalog_id === null && $model->part_name_key !== null) {
                $hit = $identity->identityFor(null, $model->part_name);

                if ($hit['catalog_id']) {
                    $model->component_catalog_id = $hit['catalog_id'];
                    // Only stamped when this hook is what established the link. 'picked' is reserved
                    // for a human's choice and is set by PartWorkflowService before the save.
                    $model->catalog_matched_by = $model->catalog_matched_by ?: PartIdentityService::VIA_NAME;
                }
            }
        });
    }
}
