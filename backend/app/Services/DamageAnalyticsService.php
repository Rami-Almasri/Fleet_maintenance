<?php

namespace App\Services;

use App\Models\DamageCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use Illuminate\Support\Facades\DB;

/**
 * DAMAGE ANALYTICS — the surface damage gets in exchange for leaving the fault charts.
 *
 * Excluding damage from reliability is only half the job. If it simply vanished, the fleet would have
 * lost sight of ~6,000 recorded events and the money attached to them, which would be a worse outcome
 * than miscounting them as faults. So damage keeps its own reporting, and this is the one place that
 * reads it.
 *
 * TWO SOURCES, ONE SHAPE — the same dual-grain problem every other analytics service in this codebase
 * has to solve:
 *   • the WORKSHOP LOG (`maintenances.service_main/service_sup`) — ~99% of history, no task rows, so it
 *     is typed at label grain through EventClassificationService;
 *   • the WORKFLOW (`maintenance_tasks.kind = damage`) — everything raised since the domain gained the
 *     kind, typed authoritatively from `damage_catalog`.
 *
 * Nothing here re-derives what damage IS. It asks the one authority and reports what comes back.
 */
class DamageAnalyticsService
{
    public function __construct(
        private readonly EventClassificationService $classifier,
        private readonly MaintenanceAnalyticsService $analytics,
    ) {
    }

    /**
     * The Damage dashboard payload: how much damage, on how many cars, of what type, costing what.
     *
     * @param  string|null  $from  Y-m-d inclusive
     * @param  string|null  $to    Y-m-d inclusive
     */
    public function report(?string $from = null, ?string $to = null): array
    {
        $log      = $this->fromWorkshopLog($from, $to);
        $workflow = $this->fromWorkflow($from, $to);

        $byLabel = [];
        foreach (array_merge($log['events'], $workflow['events']) as $e) {
            $key = $e['label'];
            $byLabel[$key] ??= ['label' => $key, 'count' => 0, 'vehicles' => [], 'cost' => 0.0, 'source' => []];
            $byLabel[$key]['count']++;
            $byLabel[$key]['vehicles'][$e['vehicle_id']] = true;
            $byLabel[$key]['cost'] += $e['cost'];
            $byLabel[$key]['source'][$e['source']] = true;
        }

        $items = collect($byLabel)
            ->map(fn ($r) => [
                'label'    => $r['label'],
                'count'    => $r['count'],
                'vehicles' => count($r['vehicles']),
                'cost'     => round($r['cost'], 2),
                'sources'  => array_keys($r['source']),
            ])
            ->sortByDesc('count')
            ->values();

        $allEvents = array_merge($log['events'], $workflow['events']);

        return [
            'summary' => [
                'events'         => count($allEvents),
                'vehicles'       => count(array_unique(array_column($allEvents, 'vehicle_id'))),
                'total_cost'     => round(array_sum(array_column($allEvents, 'cost')), 2),
                'from_log'       => count($log['events']),
                'from_workflow'  => count($workflow['events']),
            ],
            'items'       => $items->all(),
            'by_type'     => $this->byCatalogAttribute('damage_type', $workflow['events']),
            'chargeable'  => $this->chargeableSplit($workflow['events']),
            'top_vehicles' => $this->topVehicles($allEvents),

            // [[traceability-visibility-requirement]] — every page states where its numbers came from.
            'origin' => 'Damage events from two sources, merged: the N-Maintenance workshop log (typed at '
                      . 'label grain by EventClassificationService) and maintenance_tasks with kind=damage '
                      . '(typed from damage_catalog). Faults and planned services are excluded by type, not '
                      . 'by keyword. Cost is the visit/task cost attributed to the damage event.',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Workshop-log damage: split each visit's labels and keep only the ones the vocabulary types damage.
     *
     * @return array{events: array<int,array<string,mixed>>}
     */
    private function fromWorkshopLog(?string $from, ?string $to): array
    {
        $rows = DB::table('maintenances as m')
            ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->whereNull('v.deleted_at')
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->when($from, fn ($q) => $q->whereDate('m.out_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('m.out_date', '<=', $to))
            ->get(['m.id', 'm.vehicle_id', 'm.out_date', 'm.cost', 'm.service_main', 'm.service_sup', 'v.plate_no']);

        $events = [];
        foreach ($rows as $r) {
            $labels = $this->analytics->sheetIssueTags(
                new Maintenance(['service_main' => $r->service_main, 'service_sup' => $r->service_sup])
            );
            $damage = $this->classifier->splitLabels($labels)[MaintenanceTask::KIND_DAMAGE];
            if (! $damage) {
                continue;
            }

            // One visit can carry several damage labels; the cost is the VISIT's, so it is split across
            // them rather than counted once per label — otherwise a three-label visit triples its bill.
            $share = count($damage) > 0 ? ((float) $r->cost) / count($damage) : 0.0;

            foreach ($damage as $label) {
                $events[] = [
                    'label'      => $label,
                    'vehicle_id' => (int) $r->vehicle_id,
                    'plate'      => $r->plate_no,
                    'date'       => $r->out_date ? substr((string) $r->out_date, 0, 10) : null,
                    'cost'       => $share,
                    'source'     => 'workshop_log',
                    'catalog'    => null,
                ];
            }
        }

        return ['events' => $events];
    }

    /**
     * Workflow damage: `maintenance_tasks.kind = damage`, authoritative and catalog-linked.
     *
     * @return array{events: array<int,array<string,mixed>>}
     */
    private function fromWorkflow(?string $from, ?string $to): array
    {
        $tasks = MaintenanceTask::query()
            ->damages()
            ->whereNotIn('status', MaintenanceTask::NON_REPAIR_TERMINAL)
            ->when($from, fn ($q) => $q->whereDate('identified_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('identified_at', '<=', $to))
            ->with(['damageCatalog:id,slug,name,damage_type,is_chargeable,is_insurable', 'vehicle:id,plate_no'])
            ->get();

        return ['events' => $tasks->map(fn (MaintenanceTask $t) => [
            'label'      => $t->damageCatalog?->name ?: $t->symptom,
            'vehicle_id' => (int) $t->vehicle_id,
            'plate'      => $t->vehicle?->plate_no,
            'date'       => optional($t->identified_at)->toDateString(),
            'cost'       => (float) $t->parts_cost + (float) $t->labor_cost,
            'source'     => 'workflow',
            'catalog'    => $t->damageCatalog ? [
                'slug'          => $t->damageCatalog->slug,
                'damage_type'   => $t->damageCatalog->damage_type,
                'is_chargeable' => $t->damageCatalog->is_chargeable,
                'is_insurable'  => $t->damageCatalog->is_insurable,
            ] : null,
        ])->all()];
    }

    /**
     * Group catalog-linked damage by one of its catalog attributes (damage_type today).
     * Log-sourced events have no catalog row, so they are reported separately rather than guessed at.
     */
    private function byCatalogAttribute(string $attribute, array $workflowEvents): array
    {
        $out = [];
        foreach ($workflowEvents as $e) {
            $key = $e['catalog'][$attribute] ?? DamageCatalog::TYPE_UNKNOWN;
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    /**
     * The commercial split — what is billable to a renter and what can open an insurance claim.
     * Only catalog-linked events can answer this; log rows are counted as `unclassified` rather than
     * assumed chargeable, because billing somebody on an assumption is not a reporting decision.
     */
    private function chargeableSplit(array $workflowEvents): array
    {
        $out = ['chargeable' => 0, 'not_chargeable' => 0, 'insurable' => 0, 'unclassified' => 0];

        foreach ($workflowEvents as $e) {
            if (! $e['catalog']) {
                $out['unclassified']++;
                continue;
            }
            $out[$e['catalog']['is_chargeable'] ? 'chargeable' : 'not_chargeable']++;
            if ($e['catalog']['is_insurable']) {
                $out['insurable']++;
            }
        }

        return $out;
    }

    /** Cars taking the most damage — the fleet's exposure list, and a renter-behaviour signal. */
    private function topVehicles(array $events, int $limit = 15): array
    {
        $byVehicle = [];
        foreach ($events as $e) {
            $id = $e['vehicle_id'];
            $byVehicle[$id] ??= ['vehicle_id' => $id, 'plate' => $e['plate'], 'events' => 0, 'cost' => 0.0];
            $byVehicle[$id]['events']++;
            $byVehicle[$id]['cost'] += $e['cost'];
        }

        return collect($byVehicle)
            ->map(fn ($r) => ['vehicle_id' => $r['vehicle_id'], 'plate' => $r['plate'], 'events' => $r['events'], 'cost' => round($r['cost'], 2)])
            ->sortByDesc('events')
            ->take($limit)
            ->values()
            ->all();
    }
}
