<?php

namespace App\Services;

use App\Models\GarageRoutingRule;
use App\Models\Maintenance;
use App\Models\MaintenanceTaskAssignment;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Smart Routing Engine — suggests which garage a maintenance ticket should go to.
 *
 * Given a ticket, it scores every active garage on four signals and returns a ranked, fully-explained
 * suggestion the Supervisor can accept or override:
 *
 *   1. Fault mapping        — GarageRoutingRule rows on the `fault_category` axis, matched against the
 *                             ticket's fault categories (engine / ac / electrical …).
 *   2. Vehicle specialism   — GarageRoutingRule rows on the `vehicle_class` axis, matched against the
 *                             car's hand-set `vehicle_class` (luxury_suv / standard …).
 *   3. Rating nudge         — a small bump from the garage's own `vendors.rating`.
 *   4. Quality history      — a SOFT penalty (never a hard block, per the agreed strategy) derived from
 *                             this garage's failed re-inspections on the SAME fault categories. A poor
 *                             record subtracts points and raises a ⚠️ warning badge, but the garage stays
 *                             selectable so it can still be used in an emergency.
 *
 * This service is STATELESS and read-only: it never mutates the ticket. Persisting the chosen suggestion
 * (and forcing an override reason) belongs to the workflow controller that calls it. All tuning lives in
 * config/garage_routing.php; the rules themselves live in the `garage_routing_rules` table.
 *
 * See [[maintenance-tasks-container-model]] (fault categories), [[reinspection-qc-layer]] (the failure
 * signal) and the create_garage_routing_rules_table migration.
 */
class GarageRoutingService
{
    /**
     * Produce the ranked garage suggestion for a ticket.
     *
     * @return array{
     *   suggested_vendor_id: int|null,
     *   headline_reason: string|null,
     *   score: float,
     *   inputs: array{fault_categories: array<int,string>, vehicle_class: string, vehicle_class_label: string, fault_severity: string|null},
     *   candidates: array<int, array<string, mixed>>
     * }
     */
    public function suggest(Maintenance $ticket): array
    {
        $faultCategories = $this->faultCategoriesFor($ticket);
        $vehicleClass    = $this->vehicleClassFor($ticket);

        $inputs = [
            'fault_categories'    => array_values($faultCategories),
            'vehicle_class'       => $vehicleClass,
            'vehicle_class_label' => $this->classLabels()[$vehicleClass] ?? $vehicleClass,
            'fault_severity'      => $ticket->fault_severity,
        ];

        // Only active garages are candidates. No garages at all → nothing to suggest.
        $garages = Vendor::query()
            ->where('type', 'garage')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        if ($garages->isEmpty()) {
            return [
                'suggested_vendor_id' => null,
                'headline_reason'     => null,
                'score'               => 0.0,
                'inputs'              => $inputs,
                'candidates'          => [],
            ];
        }

        $vendorIds  = $garages->pluck('id')->all();
        $rulesByVen = $this->matchingRules($faultCategories, $vehicleClass, $vendorIds);
        $signal     = $this->qualitySignal($vendorIds, $faultCategories);

        $candidates = $garages
            ->map(fn (Vendor $g) => $this->scoreGarage($g, $faultCategories, $vehicleClass, $rulesByVen->get($g->id, collect()), $signal))
            ->sortByDesc(fn (array $c) => [$c['score'], (float) $c['rating']])
            ->values();

        // The winner is the top-ranked garage. Flag it so the UI can pre-fill the picker.
        $top = $candidates->first();
        $candidates = $candidates->map(function (array $c, int $i) {
            $c['is_suggested'] = $i === 0;
            return $c;
        });

        return [
            'suggested_vendor_id' => $top['vendor_id'] ?? null,
            'headline_reason'     => $top['headline_reason'] ?? null,
            'score'               => (float) ($top['score'] ?? 0),
            'inputs'              => $inputs,
            'candidates'          => $candidates->all(),
        ];
    }

    // ── Inputs ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * The distinct fault categories on the ticket. Primary signal is the per-fault `category_key`
     * (set when findings are promoted to tasks); for a ticket whose findings aren't yet tasks we fall
     * back to resolving each finding's text against the catalog.
     *
     * @return array<int, string>
     */
    private function faultCategoriesFor(Maintenance $ticket): array
    {
        $fromTasks = $ticket->relationLoaded('tasks')
            ? $ticket->tasks->pluck('category_key')->filter()->all()
            : $ticket->tasks()->pluck('category_key')->filter()->all();

        $categories = $fromTasks;

        if (empty($categories)) {
            foreach ($ticket->findings ?? [] as $finding) {
                $cat = Maintenance::categoryForKeyword($finding['text'] ?? null);
                if ($cat) {
                    $categories[] = $cat;
                }
            }
        }

        return array_values(array_unique(array_filter($categories)));
    }

    /** The car's class, falling back to the configured default so routing never breaks on a blank. */
    private function vehicleClassFor(Maintenance $ticket): string
    {
        $vehicle = $ticket->relationLoaded('vehicle') ? $ticket->vehicle : $ticket->vehicle()->first();

        $class = $vehicle?->vehicle_class;

        return filled($class) ? $class : (string) config('garage_routing.default_vehicle_class', 'standard');
    }

    // ── Rules + signal loading ──────────────────────────────────────────────────────────────────────

    /**
     * Load the ACTIVE rules relevant to this ticket (fault categories on the fault axis + the car's class
     * on the vehicle axis), keyed by vendor_id. One query, then grouped in memory.
     *
     * @return Collection<int, Collection<int, GarageRoutingRule>>
     */
    private function matchingRules(array $faultCategories, string $vehicleClass, array $vendorIds): Collection
    {
        return GarageRoutingRule::query()
            ->active()
            ->whereIn('vendor_id', $vendorIds)
            ->where(function ($q) use ($faultCategories, $vehicleClass) {
                if (! empty($faultCategories)) {
                    $q->orWhere(fn ($q2) => $q2
                        ->where('dimension', GarageRoutingRule::DIM_FAULT_CATEGORY)
                        ->whereIn('match_key', $faultCategories));
                }
                $q->orWhere(fn ($q2) => $q2
                    ->where('dimension', GarageRoutingRule::DIM_VEHICLE_CLASS)
                    ->where('match_key', $vehicleClass));
            })
            ->get()
            ->groupBy('vendor_id');
    }

    /**
     * Per-garage, per-category quality history for the ticket's fault categories, from the fault stint log
     * (maintenance_task_assignments joined to maintenance_tasks). We count only CONCLUDED repair attempts —
     * a stint that ended `resolved` or `failed_reinspection` — so the failure rate reads "of the times this
     * garage said it fixed this fault, how often did QC bounce it back". Transfers/cancellations don't count
     * either way. Returns [vendor_id][category_key] => ['attempts'=>int, 'failures'=>int].
     *
     * @return array<int, array<string, array{attempts:int, failures:int}>>
     */
    private function qualitySignal(array $vendorIds, array $faultCategories): array
    {
        if (empty($faultCategories)) {
            return [];
        }

        $failed = MaintenanceTaskAssignment::OUTCOME_FAILED_REINSPECTION;

        $rows = DB::table('maintenance_task_assignments as a')
            ->join('maintenance_tasks as t', 't.id', '=', 'a.maintenance_task_id')
            ->whereIn('a.vendor_id', $vendorIds)
            ->whereIn('t.category_key', $faultCategories)
            ->whereIn('a.outcome', [MaintenanceTaskAssignment::OUTCOME_RESOLVED, $failed])
            ->groupBy('a.vendor_id', 't.category_key')
            ->selectRaw('a.vendor_id, t.category_key, COUNT(*) as attempts, SUM(a.outcome = ?) as failures', [$failed])
            ->get();

        $signal = [];
        foreach ($rows as $r) {
            $signal[$r->vendor_id][$r->category_key] = [
                'attempts' => (int) $r->attempts,
                'failures' => (int) $r->failures,
            ];
        }

        return $signal;
    }

    // ── Scoring ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Score one garage and build its human-readable reasoning. Returns the candidate row the API/CLI shows.
     *
     * @param  Collection<int, GarageRoutingRule>  $rules  this garage's matching rules
     * @return array<string, mixed>
     */
    private function scoreGarage(Vendor $garage, array $faultCategories, string $vehicleClass, Collection $rules, array $signal): array
    {
        $cfg           = config('garage_routing.scoring');
        $ratingPerStar = (float) ($cfg['rating_weight_per_star'] ?? 0);
        $penaltyEach   = (float) ($cfg['failure_penalty_each'] ?? 0);
        $penaltyMax    = (float) ($cfg['failure_penalty_max'] ?? 0);
        $warnRate      = (float) ($cfg['warn_failure_rate'] ?? 1);

        $catLabels   = $this->categoryLabels();
        $classLabels = $this->classLabels();

        $score    = 0.0;
        $reasons  = [];   // positive/neutral contributions, shown as "why this garage"
        $warnings = [];   // soft-penalty warnings, shown as ⚠️ badges
        $specialistFault = null;   // category label of a matched specialist fault rule (for the headline)
        $specialistClass = null;   // class label of a matched specialist vehicle rule (for the headline)

        // 1 + 2 — rule contributions (fault mapping + vehicle specialism).
        foreach ($rules as $rule) {
            $score += $rule->weight;

            if ($rule->dimension === GarageRoutingRule::DIM_FAULT_CATEGORY) {
                $label = $catLabels[$rule->match_key] ?? $rule->match_key;
                $reasons[] = [
                    'type'   => $rule->is_specialist ? 'specialist' : 'preference',
                    'text'   => $rule->is_specialist ? "Specialist in {$label}" : "Preferred for {$label}",
                    'points' => $rule->weight,
                ];
                if ($rule->is_specialist) {
                    $specialistFault = $label;
                }
            } else { // vehicle_class
                $label = $classLabels[$rule->match_key] ?? $rule->match_key;
                $reasons[] = [
                    'type'   => $rule->is_specialist ? 'specialist' : 'preference',
                    'text'   => $rule->is_specialist ? "Specialist for {$label}" : "Handles {$label}",
                    'points' => $rule->weight,
                ];
                if ($rule->is_specialist) {
                    $specialistClass = $label;
                }
            }
        }

        // 3 — rating nudge.
        $rating = (float) ($garage->rating ?? 0);
        if ($rating > 0 && $ratingPerStar > 0) {
            $bump   = round($rating * $ratingPerStar, 2);
            $score += $bump;
            $reasons[] = ['type' => 'rating', 'text' => "Rated {$rating}★", 'points' => $bump];
        }

        // 4 — soft quality penalty (per matched fault category with failures on record).
        foreach ($faultCategories as $cat) {
            $stat = $signal[$garage->id][$cat] ?? null;
            if (! $stat || $stat['failures'] === 0) {
                continue;
            }

            $penalty = min($penaltyMax, $stat['failures'] * $penaltyEach);
            $score  -= $penalty;

            $label = $catLabels[$cat] ?? $cat;
            $reasons[] = [
                'type'   => 'penalty',
                'text'   => "−{$stat['failures']} failed re-inspection on {$label}",
                'points' => -$penalty,
            ];

            $rate = $stat['attempts'] > 0 ? $stat['failures'] / $stat['attempts'] : 0;
            if ($rate >= $warnRate) {
                $pct = (int) round($rate * 100);
                $warnings[] = "Poor {$label} record — {$stat['failures']}/{$stat['attempts']} attempts failed QC ({$pct}%)";
            }
        }

        return [
            'vendor_id'       => $garage->id,
            'garage'          => $garage->name,
            'rating'          => $rating,
            'score'           => round($score, 2),
            'headline_reason' => $this->headline($specialistFault, $specialistClass, $reasons, $rules->isNotEmpty()),
            'reasons'         => $reasons,
            'warn'            => ! empty($warnings),
            'warnings'        => $warnings,
        ];
    }

    /**
     * The single badge line shown next to the suggestion. Prefers a specialism ("Specialist in Luxury SUV
     * Electrical"), then the strongest positive rule, then rating, then a plain availability note.
     *
     * @param  array<int, array<string, mixed>>  $reasons
     */
    private function headline(?string $specialistFault, ?string $specialistClass, array $reasons, bool $hasRules): string
    {
        if ($specialistFault && $specialistClass) {
            return "Specialist in {$specialistClass} {$specialistFault}";
        }
        if ($specialistFault) {
            return "Specialist in {$specialistFault}";
        }
        if ($specialistClass) {
            return "Specialist for {$specialistClass}";
        }

        // Strongest positive, non-penalty contribution.
        $positives = array_filter($reasons, fn ($r) => $r['points'] > 0);
        if (! empty($positives)) {
            usort($positives, fn ($a, $b) => $b['points'] <=> $a['points']);
            return $positives[0]['text'];
        }

        return $hasRules ? 'Matched on availability' : 'No specialisation rule — ranked by rating';
    }

    // ── Label maps (from config, built once per request) ──────────────────────────────────────────────

    /** @return array<string, string> category_key => label */
    private function categoryLabels(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (config('maintenance_findings.categories', []) as $c) {
                if (! empty($c['key'])) {
                    $map[$c['key']] = $c['label'] ?? $c['key'];
                }
            }
        }
        return $map;
    }

    /** @return array<string, string> vehicle_class key => label */
    private function classLabels(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (config('garage_routing.vehicle_classes', []) as $c) {
                if (! empty($c['key'])) {
                    $map[$c['key']] = $c['label'] ?? $c['key'];
                }
            }
        }
        return $map;
    }
}
