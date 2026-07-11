<?php

namespace Database\Seeders;

use App\Models\GarageRoutingRule;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Seeds the Garage Preference Rules matrix from config('garage_routing.default_rules').
 *
 * The default_rules array is keyed by garage NAME (vendor_id is deployment-specific, so we can't
 * hardcode it). For each named garage we resolve the vendor and upsert its rules on the
 * (vendor_id, dimension, match_key) triple — so re-running never duplicates and NEVER overwrites a
 * weight / note an admin has tuned in the dashboard (firstOrNew, save only when new). Any garage name
 * that doesn't match a vendor is skipped silently, so this is a safe no-op on a fresh DB (the array
 * ships empty — rules are normally created from the admin dashboard).
 */
class GarageRoutingRuleSeeder extends Seeder
{
    public function run(): void
    {
        $defaults    = config('garage_routing.default_rules', []);
        $dimensions  = config('garage_routing.dimensions', []);
        $classes     = array_column(config('garage_routing.vehicle_classes', []), 'key');
        $categories  = array_column(config('maintenance_findings.categories', []), 'key');
        $baseWeight  = (int) config('garage_routing.scoring.default_weight', 10);

        foreach ($defaults as $garageName => $rules) {
            $vendor = Vendor::where('type', 'garage')->where('name', $garageName)->first();
            if (! $vendor) {
                // Unknown garage in this deployment — leave it for the admin to wire up by hand.
                continue;
            }

            foreach ($rules as $rule) {
                $dimension = $rule['dimension'] ?? null;
                $matchKey  = $rule['match_key'] ?? null;
                if (! $dimension || ! $matchKey || ! in_array($dimension, $dimensions, true)) {
                    continue;
                }

                // Guard the match_key against the live reference lists so a typo in config can't seed
                // a rule the engine will never match.
                $validKey = $dimension === GarageRoutingRule::DIM_FAULT_CATEGORY
                    ? in_array($matchKey, $categories, true)
                    : in_array($matchKey, $classes, true);
                if (! $validKey) {
                    continue;
                }

                $row = GarageRoutingRule::firstOrNew([
                    'vendor_id' => $vendor->id,
                    'dimension' => $dimension,
                    'match_key' => $matchKey,
                ]);

                // Only populate on first create — never clobber an admin-tuned rule.
                if (! $row->exists) {
                    $row->weight        = $rule['weight'] ?? $baseWeight;
                    $row->is_specialist = (bool) ($rule['is_specialist'] ?? false);
                    $row->active        = $rule['active'] ?? true;
                    $row->note          = $rule['note'] ?? null;
                    $row->save();
                }
            }
        }
    }
}
