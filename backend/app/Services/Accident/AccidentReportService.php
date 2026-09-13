<?php

namespace App\Services\Accident;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use Illuminate\Support\Facades\DB;

/**
 * The accident dashboard's tiles and queues.
 *
 * ── THE ORDER IS AN ARGUMENT ───────────────────────────────────────────────────────────────────
 *
 * WORK first, MONEY second. The queues at the top are things a person must do today — a police
 * report nobody has chased, a liability decision nobody has taken, an insurer who has gone quiet.
 * The money below is the consequence of having done or not done them. A board that leads with total
 * accident spend tells a manager how bad last quarter was; one that leads with four unchased police
 * reports tells them what to do about this one.
 *
 * ── THE TILE MOST DASHBOARDS WOULD OMIT ────────────────────────────────────────────────────────
 *
 * "Waived police reports". It measures this feature's own guardrail being set aside, and it is
 * exactly the number a system like this quietly leaves out. If it climbs, either the reports are
 * genuinely unobtainable — in which case the intake rule is wrong — or the waiver has become the
 * fast path, and both are things somebody needs to see rather than infer from a lost claim.
 *
 * Read-only. Nothing here writes.
 */
class AccidentReportService
{
    public function dashboard(): array
    {
        $open = AccidentCase::openCases();

        // One pass over the live ledger rather than a query per figure: the ledger is small per case
        // and the grouping is what the tiles need anyway.
        $money = DB::table('accident_financial_entries')
            ->join('accident_cases', 'accident_cases.id', '=', 'accident_financial_entries.accident_case_id')
            ->whereNull('accident_financial_entries.superseded_at')
            ->whereNull('accident_cases.deleted_at')
            ->groupBy('accident_financial_entries.phase', 'accident_financial_entries.party')
            ->selectRaw('accident_financial_entries.phase, accident_financial_entries.party, SUM(accident_financial_entries.amount) as total')
            ->get();

        $sumPhase = fn (string $phase) => (float) $money->where('phase', $phase)->sum('total');
        $sumParty = fn (string $party) => (float) $money->where('party', $party)->sum('total');

        return [
            // ── WORK: what is waiting on a person ─────────────────────────────────────────────
            'open_cases'          => (clone $open)->count(),
            'awaiting_police'     => AccidentCase::awaitingPolice()->count(),
            'awaiting_liability'  => AccidentCase::awaitingLiability()->count(),
            'awaiting_insurer'    => AccidentCase::awaitingInsurer()->count(),
            'insurer_overdue'     => AccidentCase::awaitingInsurer()->get()
                ->filter(fn ($c) => $c->insurerOverdue())->count(),
            'vehicles_restricted' => AccidentCase::rentalBlocking()->distinct('vehicle_id')->count('vehicle_id'),
            'under_repair'        => (clone $open)->where('stage', AccidentCase::STAGE_REPAIR)->count(),

            // The guardrail's own failure measure. @see the docblock.
            'police_waived'       => AccidentCase::where('police_status', AccidentCase::POLICE_BYPASSED)->count(),

            // ── MONEY: never summed across phases ─────────────────────────────────────────────
            'money' => [
                'currency'   => 'AED',
                'estimated'  => round($sumPhase(AccidentFinancialEntry::PHASE_ESTIMATE)
                                    + $sumPhase(AccidentFinancialEntry::PHASE_REVISED_ESTIMATE), 2),
                'approved'   => round($sumPhase(AccidentFinancialEntry::PHASE_APPROVED), 2),
                'actual'     => round($sumPhase(AccidentFinancialEntry::PHASE_ACTUAL), 2),
                'paid'       => round($sumPhase(AccidentFinancialEntry::PHASE_PAID), 2),
                // What is still owed to us, and what nobody has taken responsibility for. Two
                // different problems, and a single "outstanding" figure would hide the second.
                'outstanding' => round($sumPhase(AccidentFinancialEntry::PHASE_ACTUAL) - $sumPhase(AccidentFinancialEntry::PHASE_PAID), 2),
                'unresolved'  => round($sumParty(AccidentFinancialEntry::PARTY_UNRESOLVED), 2),
                'by_party'    => $money->groupBy('party')->map(fn ($rows) => round((float) $rows->sum('total'), 2))->all(),
            ],

            // ── the shape of the year: what kind of accidents, and whose fault ────────────────
            'by_liability' => AccidentCase::query()
                ->groupBy('liability_status')->selectRaw('liability_status, COUNT(*) as n')
                ->pluck('n', 'liability_status')->all(),
            'by_stage' => AccidentCase::query()
                ->groupBy('stage')->selectRaw('stage, COUNT(*) as n')
                ->pluck('n', 'stage')->all(),
            'by_type' => AccidentCase::query()->whereNotNull('accident_type')
                ->groupBy('accident_type')->selectRaw('accident_type, COUNT(*) as n')
                ->orderByDesc('n')->pluck('n', 'accident_type')->all(),
            // How many happened on hire. The single most commercially loaded number here: every one
            // of these is a contract that kept running while the car was off the road.
            'on_rental' => AccidentCase::where('responsible_party_type', AccidentCase::PARTY_RENTAL_CUSTOMER)->count(),
        ];
    }
}
