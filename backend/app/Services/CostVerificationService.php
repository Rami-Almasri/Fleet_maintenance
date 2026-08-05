<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\TraceabilitySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verified vs Legacy vs Unverified — the honest state of the fleet's financial record, and the metric the
 * cleanup is measured by.
 *
 * The whole point is to NOT fake history. 173,934 AED of existing ticket cost has no document behind it
 * and never will; inventing invoices for it would corrupt the record far worse than admitting it. So cost
 * is reported in three buckets that mean three genuinely different things:
 *
 *     VERIFIED           every amount on the ticket traces to a source document. The goal state.
 *     LEGACY UNVERIFIED  recorded before the documents existed (`cost_legacy_at` is stamped). A known,
 *                        bounded backlog that shrinks as it is migrated. Nobody's fault.
 *     UNVERIFIED         recorded AFTER the rules, with no document. The closure gate should make this
 *                        impossible, so any non-zero figure here is a live problem, not a historical one.
 *
 * Collapsing the last two would be the mistake: it would leave management staring at a number that never
 * improves, and hide the one case that actually needs chasing.
 *
 * Evidence class: D (derived) — Produces E-traceability-coverage. Consumes maintenances (F),
 * maintenance_line_items (F), and the four source documents through CostSourceResolver.
 */
class CostVerificationService
{
    public const STATE_VERIFIED   = 'verified';
    public const STATE_LEGACY     = 'legacy_unverified';
    public const STATE_UNVERIFIED = 'unverified';
    public const STATE_NO_COST    = 'no_cost';

    public const LABELS = [
        self::STATE_VERIFIED   => 'Verified',
        self::STATE_LEGACY     => 'Legacy — unverified',
        self::STATE_UNVERIFIED => 'Unverified',
        self::STATE_NO_COST    => 'No cost recorded',
    ];

    public function __construct(private CostSourceResolver $sources) {}

    /** Which bucket one ticket falls in, and why. */
    public function stateOf(Maintenance $ticket): array
    {
        $cost = round((float) $ticket->cost, 2);

        if ($cost == 0.0) {
            return ['state' => self::STATE_NO_COST, 'verified' => 0.0, 'unverified' => 0.0, 'total' => 0.0];
        }

        $audit = $this->sources->auditTicket($ticket);

        if ($audit['untraceable'] == 0.0) {
            $state = self::STATE_VERIFIED;
        } else {
            // The distinction that makes the metric meaningful: was this recorded before the rules, or
            // in spite of them?
            $state = $ticket->cost_legacy_at ? self::STATE_LEGACY : self::STATE_UNVERIFIED;
        }

        return [
            'state'       => $state,
            'label'       => self::LABELS[$state],
            'verified'    => $audit['traceable'],
            'unverified'  => $audit['untraceable'],
            'total'       => $audit['total'],
            'coverage_pct' => $audit['coverage_pct'],
            'legacy_at'   => optional($ticket->cost_legacy_at)->toIso8601String(),
            'legacy_note' => $ticket->cost_legacy_note,
        ];
    }

    /**
     * The fleet-wide picture — the report management reads.
     *
     * Walks every ticket that carries cost. That is deliberate rather than a sampled estimate: this is the
     * number the cleanup is judged by, and an approximate honesty metric would be a contradiction.
     */
    public function fleetSummary(): array
    {
        $verifiedCost = 0.0;
        $legacyCost = 0.0;
        $unverifiedCost = 0.0;
        $counts = [self::STATE_VERIFIED => 0, self::STATE_LEGACY => 0, self::STATE_UNVERIFIED => 0];
        $bySource = [];
        $tickets = 0;

        Maintenance::where('cost', '>', 0)
            ->with(['lineItems'])
            ->chunkById(200, function ($chunk) use (
                &$verifiedCost, &$legacyCost, &$unverifiedCost, &$counts, &$bySource, &$tickets
            ) {
                foreach ($chunk as $ticket) {
                    $tickets++;
                    $audit = $this->sources->auditTicket($ticket);

                    $verifiedCost += $audit['traceable'];

                    if ($audit['untraceable'] == 0.0) {
                        $counts[self::STATE_VERIFIED]++;
                    } elseif ($ticket->cost_legacy_at) {
                        $counts[self::STATE_LEGACY]++;
                        $legacyCost += $audit['untraceable'];
                    } else {
                        $counts[self::STATE_UNVERIFIED]++;
                        $unverifiedCost += $audit['untraceable'];
                    }

                    foreach ($audit['by_source'] as $type => $amount) {
                        if ($type === CostSourceResolver::SOURCE_UNSOURCED) {
                            continue;
                        }
                        $bySource[$type] = round(($bySource[$type] ?? 0) + $amount, 2);
                    }
                }
            });

        $total = round($verifiedCost + $legacyCost + $unverifiedCost, 2);

        return [
            'tickets'         => $tickets,
            'tickets_by_state' => $counts,
            'total_cost'      => $total,
            'verified_cost'   => round($verifiedCost, 2),
            'legacy_cost'     => round($legacyCost, 2),
            'unverified_cost' => round($unverifiedCost, 2),
            'coverage_pct'    => $total != 0.0 ? round(($verifiedCost / $total) * 100, 2) : 100.0,
            'by_source'       => $bySource,
        ];
    }

    /**
     * The migration worklist: legacy tickets ordered by how much undocumented money they carry, so the
     * cleanup starts where it moves the metric most.
     *
     * @return array<int,array>
     */
    public function migrationQueue(int $limit = 50): array
    {
        $rows = [];

        Maintenance::where('cost', '>', 0)
            ->whereNotNull('cost_legacy_at')
            ->with(['lineItems', 'vehicle:id,plate_no'])
            ->orderByDesc('cost')
            ->limit(max(1, $limit) * 3) // over-fetch: some will already have been migrated to verified
            ->get()
            ->each(function ($ticket) use (&$rows) {
                $audit = $this->sources->auditTicket($ticket);
                if ($audit['untraceable'] == 0.0) {
                    return; // already cleaned up — it just hasn't been unstamped
                }

                $rows[] = [
                    'ticket_id'    => $ticket->id,
                    'plate_no'     => $ticket->vehicle?->plate_no,
                    'cost'         => round((float) $ticket->cost, 2),
                    'unverified'   => $audit['untraceable'],
                    'verified'     => $audit['traceable'],
                    'coverage_pct' => $audit['coverage_pct'],
                    'closed_at'    => optional($ticket->wf_closed_at)->toDateString(),
                    'reason'       => $audit['untraceable_items'][0]['why'] ?? null,
                ];
            });

        usort($rows, fn ($a, $b) => $b['unverified'] <=> $a['unverified']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Take today's measurement and store it, so coverage becomes a TREND rather than a fact that only
     * exists while someone is looking at it. Re-running on the same day replaces that day's row.
     */
    public function snapshot(): TraceabilitySnapshot
    {
        $summary = $this->fleetSummary();

        return TraceabilitySnapshot::updateOrCreate(
            ['taken_on' => Carbon::now()->toDateString()],
            [
                'taken_at'         => Carbon::now(),
                'tickets_total'    => $summary['tickets'],
                'tickets_verified' => $summary['tickets_by_state'][self::STATE_VERIFIED],
                'tickets_legacy'   => $summary['tickets_by_state'][self::STATE_LEGACY],
                'total_cost'       => $summary['total_cost'],
                'verified_cost'    => $summary['verified_cost'],
                'legacy_cost'      => $summary['legacy_cost'],
                'unverified_cost'  => $summary['unverified_cost'],
                'coverage_pct'     => $summary['coverage_pct'],
                'by_source'        => $summary['by_source'],
            ],
        );
    }

    /**
     * Spend by category, split by whether it is documented — the query the whole structured-origin work
     * exists to make possible. "How much did we spend on brakes this year, and how much of that can we
     * prove?" is now one grouped query rather than a walk over every ticket.
     *
     * @return array<int,array>
     */
    public function spendByCategory(?string $from = null, ?string $to = null): array
    {
        $q = DB::table('maintenance_line_items as li')
            ->join('maintenances as m', 'm.id', '=', 'li.maintenance_id')
            ->whereNull('m.deleted_at')   // LIVE maintenances only (soft-delete rule)
            ->selectRaw("
                COALESCE(li.category_key, 'uncategorised') as category_key,
                li.kind,
                SUM(li.line_total) as total,
                SUM(CASE WHEN li.source_type IS NOT NULL THEN li.line_total ELSE 0 END) as documented,
                SUM(CASE WHEN li.source_type IS NULL THEN li.line_total ELSE 0 END) as undocumented,
                COUNT(*) as line_count
            ")
            ->groupBy('category_key', 'li.kind');

        if ($from) {
            $q->whereDate('li.created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('li.created_at', '<=', $to);
        }

        return collect($q->get())->map(fn ($r) => [
            'category_key' => $r->category_key,
            'kind'         => $r->kind,
            'total'        => round((float) $r->total, 2),
            'documented'   => round((float) $r->documented, 2),
            'undocumented' => round((float) $r->undocumented, 2),
            // `lines` is reserved in MariaDB, hence the aliased column name in the query above.
            'lines'        => (int) $r->line_count,
            'coverage_pct' => (float) $r->total != 0.0
                ? round(((float) $r->documented / (float) $r->total) * 100, 1)
                : 100.0,
        ])->sortByDesc('total')->values()->all();
    }
}
