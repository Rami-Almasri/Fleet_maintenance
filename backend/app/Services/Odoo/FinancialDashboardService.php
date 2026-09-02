<?php

namespace App\Services\Odoo;

use App\Models\ComponentCatalog;
use App\Models\FinancialEvent;
use App\Models\OdooMapping;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Support\ExpenseType;
use App\Support\FinancialBlockReason;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Support\Facades\DB;

/**
 * What the integration is doing, in numbers somebody can act on (§40).
 *
 * The whole design goal is stated in §40 itself: "4 blocked → 2 missing product mapping, 1 missing
 * supplier mapping, 1 missing analytic account" is useful; "Odoo Sync Failed" is not. So the counts are
 * never just per-status — every blocked event is grouped by the REASON CODE that blocked it, which is
 * possible only because blocking reasons are stored as codes rather than as English sentences
 * ({@see FinancialBlockReason}).
 *
 * The mapping backlog is reported the same way: not "some things are unmapped" but how many vehicles,
 * parts and suppliers, so the work is a finite list rather than a mood.
 */
class FinancialDashboardService
{
    public function __construct(
        private OdooClient $client,
    ) {
    }

    /**
     * The headline panel.
     *
     * @return array{connection:array, counts:array<string,int>, value:array<string,float>,
     *               blocked_by_reason:list<array{code:string,count:int}>,
     *               failed_by_code:list<array{code:string,count:int}>,
     *               by_expense_type:list<array{expense_type:string,label:string,counts:array}>,
     *               mapping_backlog:array<string,int>}
     */
    public function summary(): array
    {
        return [
            'connection'        => $this->connection(),
            'counts'            => $this->countsByStatus(),
            'value'             => $this->valueByStatus(),
            'blocked_by_reason' => $this->blockedByReason(),
            'failed_by_code'    => $this->failedByCode(),
            'by_expense_type'   => $this->byExpenseType(),
            'mapping_backlog'   => $this->mappingBacklog(),
        ];
    }

    /**
     * Whether Odoo is reachable at all.
     *
     * Reported WITHOUT making a network call — `configured` is read from config alone. The dashboard is
     * loaded constantly, and an unreachable Odoo would make every page wait for a TCP timeout. The live
     * check is its own explicit endpoint (`/odoo/health`), pressed by an administrator who is asking
     * that question on purpose.
     */
    public function connection(): array
    {
        return [
            'configured' => $this->client->isConfigured(),
            'currency'   => (string) config('odoo.currency', 'AED'),
        ];
    }

    /** @return array<string,int> every status, including the ones at zero, so the panel never shifts. */
    public function countsByStatus(): array
    {
        $counts = FinancialEvent::query()
            ->select('status', DB::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $out = [];
        foreach (Status::ALL as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }

    /** How much money is sitting in each state — the reason a blocked queue matters. */
    public function valueByStatus(): array
    {
        $sums = FinancialEvent::query()
            ->select('status', DB::raw('SUM(amount) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $out = [];
        foreach (Status::ALL as $status) {
            $out[$status] = round((float) ($sums[$status] ?? 0), 2);
        }

        return $out;
    }

    /**
     * Blocked events grouped by WHY.
     *
     * One event can carry several reasons and is counted under each of them, deliberately: the question
     * this answers is "how much work is there of each kind", and an event blocked by both an unmapped
     * supplier and an unmapped vehicle is genuinely on both lists. The totals therefore do not sum to
     * the blocked count, which is why the panel labels them as reasons rather than as events.
     *
     * @return list<array{code:string, count:int}>
     */
    public function blockedByReason(): array
    {
        $tally = [];

        FinancialEvent::query()
            ->where('status', Status::BLOCKED)
            ->select(['id', 'block_reasons'])
            ->chunkById(500, function ($events) use (&$tally) {
                foreach ($events as $event) {
                    foreach (FinancialBlockReason::codes($event->blockReasons()) as $code) {
                        $tally[$code] = ($tally[$code] ?? 0) + 1;
                    }
                }
            });

        arsort($tally);

        return array_map(
            static fn ($code, $count) => ['code' => $code, 'count' => $count],
            array_keys($tally),
            array_values($tally)
        );
    }

    /** @return list<array{code:string, count:int}> */
    public function failedByCode(): array
    {
        return FinancialEvent::query()
            ->where('status', Status::FAILED)
            ->select('failure_code', DB::raw('COUNT(*) as n'))
            ->groupBy('failure_code')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($row) => ['code' => (string) ($row->failure_code ?: 'unknown'), 'count' => (int) $row->n])
            ->all();
    }

    /** Per expense type: how its events are distributed, so a category in trouble is visible. */
    public function byExpenseType(): array
    {
        $rows = FinancialEvent::query()
            ->select('expense_type', 'status', DB::raw('COUNT(*) as n'))
            ->groupBy('expense_type', 'status')
            ->get();

        $out = [];
        foreach (ExpenseType::ALL as $type) {
            $counts = [];
            foreach (Status::ALL as $status) {
                $counts[$status] = (int) $rows
                    ->where('expense_type', $type)
                    ->where('status', $status)
                    ->sum('n');
            }

            $out[] = [
                'expense_type' => $type,
                'label'        => ExpenseType::label($type),
                'counts'       => $counts,
            ];
        }

        return $out;
    }

    /**
     * How much mapping work is outstanding.
     *
     * Counts things that are NOT usably mapped — never mapped, only suggested, or gone stale. A record
     * explicitly marked "has no counterpart" is excluded, because somebody has already answered for it
     * and it is not work any more.
     *
     * Vehicles are narrowed by the eligibility rule from config: a car the fleet has disposed of will
     * never accrue another cost, so listing it as unmapped work forever would make the backlog
     * permanently unfinishable. That rule is about the BACKLOG only — §17 — and never about whether an
     * existing cost may be posted.
     */
    public function mappingBacklog(): array
    {
        return [
            'vehicles'  => $this->unmappedCount(Vehicle::query()->whereNotIn(
                'status',
                (array) config('odoo.vehicle_eligibility.exclude_statuses', [])
            ), Vehicle::class, OdooMapping::MODEL_ANALYTIC),

            'parts'     => $this->unmappedCount(
                ComponentCatalog::query()->where('is_active', true),
                ComponentCatalog::class,
                OdooMapping::MODEL_PRODUCT
            ),

            'suppliers' => $this->unmappedCount(
                Vendor::query()->where('active', true),
                Vendor::class,
                OdooMapping::MODEL_PARTNER
            ),
        ];
    }

    /** Rows of $query that have no USABLE mapping and have not been answered for. */
    private function unmappedCount($query, string $class, string $odooModel): int
    {
        $morph = (new $class)->getMorphClass();

        $answered = OdooMapping::query()
            ->where('odoo_model', $odooModel)
            ->where('mappable_type', $morph)
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->where('status', OdooMapping::STATUS_MAPPED)->whereNotNull('odoo_id');
                })->orWhere('status', OdooMapping::STATUS_UNMAPPED);
            })
            ->pluck('mappable_id');

        return (int) $query->whereNotIn('id', $answered)->count();
    }
}
