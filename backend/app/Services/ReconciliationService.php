<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Financial Reconciliation (read-only MVP) — bridges ONE contract to the company's official
 * accounting system so the numbers FleetView reports can be eyeballed against the real books:
 *
 *   • Fleet side      — the contract's own ledger (billed / recorded-collected / outstanding /
 *                       income), already synced from OfficeManager.
 *   • Cash collected  — the real money in, from the accounting cash-receipts journal
 *                       (/reports/balance) — the ONLY amount-bearing accounting feed.
 *   • Booked vouchers — proof the contract was posted to the books (/accounts/vouchers).
 *                       Voucher rows are HEADERS only: they confirm a posting exists and whether
 *                       it was a journal entry or a receipt, but carry no monetary amount.
 *
 * Nothing is persisted — every call hits the live API. This is the verify-the-numbers MVP before
 * we commit to voucher tables + a sync command. The accounting endpoints expose no per-line amount,
 * so "verification" is: (a) is it posted at all, and (b) does the cash the books actually received
 * match what the dashboard recorded as collected, within a tolerance that absorbs VAT rounding and
 * bank/card fees.
 */
class ReconciliationService
{
    public function __construct(private OfficeManagerClient $om) {}

    /**
     * @param  float  $tolerancePct    cash gap allowed (as % of the larger of recorded/collected)
     *                                 before it counts as an exception — absorbs VAT rounding &
     *                                 bank fees.
     * @param  float  $toleranceFloor  minimum absolute gap (AED) that is ever flagged, so trivial
     *                                 gaps on small contracts don't trip the alert.
     * @return array<string,mixed>
     */
    /** Lists are capped to this many rows; full counts/totals are always reported (no silent truncation). */
    private const FLEET_LIST_CAP = 25;

    /**
     * Fleet-wide Net Profit for ONE month (cash basis), computed entirely from already-synced data —
     * NO live API calls. A live rollup would mean one accounting call per contract (≈300/month against
     * a fragile server), so for the fleet figure we use the synced proxy for real cash:
     *
     *   Net Collected (per rental)  ≈  contract_credit − contract_refunds
     *      (validated: matches the live /reports/balance net to within ~AED 1 on sampled contracts)
     *
     *   Fleet Net Profit  =  Σ Net Collected over rentals (type C) returned this month
     *                      − Σ Maintenance cost over maintenance contracts (type U) closed this month
     *
     * "Closed this month" = the contract's return (in_date) falls in the month. Workshop-log spend is
     * reported separately as a reference (its cost coverage is currently low, so the maintenance-
     * contract debit is the meaningful cost signal). Per-contract live verification stays one click
     * away on each contract / the lookup above.
     *
     * @return array<string,mixed>
     */
    public function fleetMonth(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = (clone $start)->endOfMonth();
        [$s, $e] = [$start->toDateString(), $end->toDateString()];

        // --- Income side: rentals (type C) returned this month -------------------------------
        $rentals = Contract::with(['vehicle:id,plate_no,make,model', 'customer:id,name_en,customer_no'])
            ->where('contract_type', 'C')
            ->whereNotNull('in_date')
            ->whereBetween('in_date', [$s, $e])
            ->get();

        $netCollected = 0.0;
        $billed       = 0.0;
        $rentalRows   = [];
        foreach ($rentals as $r) {
            $net = round((float) $r->contract_credit - (float) $r->contract_refunds, 2);
            $netCollected += $net;
            $billed       += (float) $r->contract_debit;
            $rentalRows[] = [
                'id'            => $r->id,
                'contract_no'   => $r->contract_no,
                'vehicle'       => optional($r->vehicle)->plate_no,
                'customer'      => optional($r->customer)->name_en,
                'in_date'       => optional($r->in_date)->toDateString(),
                'billed'        => round((float) $r->contract_debit, 2),
                'net_collected' => $net,
                'balance'       => round((float) $r->contract_balance, 2),
            ];
        }

        // --- Cost side: maintenance contracts (type U) closed this month ---------------------
        $maint = Contract::with(['vehicle:id,plate_no,make,model'])
            ->where('contract_type', 'U')
            ->whereNotNull('in_date')
            ->whereBetween('in_date', [$s, $e])
            ->get();

        $maintCost = 0.0;
        $maintRows = [];
        foreach ($maint as $m) {
            $cost = round((float) $m->contract_debit, 2);
            $maintCost += $cost;
            $maintRows[] = [
                'id'          => $m->id,
                'contract_no' => $m->contract_no,
                'vehicle'     => optional($m->vehicle)->plate_no,
                'in_date'     => optional($m->in_date)->toDateString(),
                'cost'        => $cost,
            ];
        }

        // Workshop-log spend in the same window — reference only (low coverage today).
        $workshopCost = (float) DB::table('maintenances')
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('out_date')
            ->whereBetween('out_date', [$s, $e])
            ->sum(DB::raw('COALESCE(cost,0)'));

        // Biggest contributors first; cap the lists but always report the true totals/counts.
        usort($rentalRows, fn ($a, $b) => $b['net_collected'] <=> $a['net_collected']);
        usort($maintRows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return [
            'period' => [
                'year'  => $year,
                'month' => $month,
                'label' => $start->format('F Y'),
                'start' => $s,
                'end'   => $e,
            ],
            'totals' => [
                'net_collected'    => round($netCollected, 2),
                'billed'           => round($billed, 2),
                'maintenance_cost' => round($maintCost, 2),
                'workshop_cost'    => round($workshopCost, 2),
                'net_profit'       => round($netCollected - $maintCost, 2),
            ],
            'counts' => [
                'rentals'     => count($rentalRows),
                'maintenance' => count($maintRows),
            ],
            'rentals'         => array_slice($rentalRows, 0, self::FLEET_LIST_CAP),
            'maintenance'     => array_slice($maintRows, 0, self::FLEET_LIST_CAP),
            'list_cap'        => self::FLEET_LIST_CAP,
            'basis'           => 'synced',   // UI note: fleet rollup uses synced figures, not live API
        ];
    }

    public function forContract(Contract $c, float $tolerancePct = 2.0, float $toleranceFloor = 5.0): array
    {
        // ---- Fleet side: the contract's own (already-synced) ledger -------------------------
        $billed      = (float) $c->contract_debit;    // total charged, incl. VAT
        $recorded    = (float) $c->contract_credit;   // what the dashboard recorded as collected
        $outstanding = (float) $c->contract_balance;  // billed − collected, per our books
        $income      = (float) $c->contract_income;   // ex-VAT income (reference only — not the cash target)

        // ---- Cash collected: the accounting cash-receipts journal ---------------------------
        $cash = ['received' => 0.0, 'refunded' => 0.0, 'net' => 0.0, 'count' => 0, 'rows' => [], 'error' => null];
        if ($c->contract_no) {
            try {
                $res = $this->om->balanceReceipts(['contract_no' => (int) $c->contract_no]);
                foreach ($res['items'] as $r) {
                    $amt  = (float) ($r['Amount'] ?? 0);
                    $type = strtolower((string) ($r['Payment Type'] ?? ''));
                    // "Receive" = money in; anything else ("Send") = refund / money out.
                    if (str_starts_with($type, 'rec')) {
                        $cash['received'] += $amt;
                    } else {
                        $cash['refunded'] += $amt;
                    }
                    $cash['rows'][] = [
                        'date'     => $r['Date'] ?? null,
                        'amount'   => round($amt, 2),
                        'type'     => $r['Payment Type'] ?? null,
                        'journal'  => $r['Journal'] ?? null,
                        'memo'     => $r['Memo'] ?? null,
                        'customer' => $r['Customer'] ?? null,
                    ];
                }
                $cash['count'] = count($cash['rows']);
                $cash['received'] = round($cash['received'], 2);
                $cash['refunded'] = round($cash['refunded'], 2);
                $cash['net']      = round($cash['received'] - $cash['refunded'], 2);
            } catch (\Throwable $e) {
                $cash['error'] = $e->getMessage();
            }
        }

        // ---- Booked vouchers: posting proof (headers only, no amounts) ----------------------
        $vouchers = ['total' => 0, 'journal' => 0, 'receipts' => 0, 'rows' => [], 'error' => null];
        if ($c->contract_serial) {
            try {
                $res = $this->om->accountsVouchers(['contract_serial' => (int) $c->contract_serial]);
                foreach ($res['items'] as $v) {
                    // ReceiptNo != 0 => a receipt voucher (money moved); 0 => a journal posting (a charge).
                    $isReceipt = (int) ($v['ReceiptNo'] ?? 0) !== 0;
                    $isReceipt ? $vouchers['receipts']++ : $vouchers['journal']++;
                    $vouchers['rows'][] = [
                        'voucher_no'  => $v['VoucherNo'] ?? null,
                        'serial'      => $v['VoucherSerialNo'] ?? null,
                        'group'       => $v['VoucherGroup'] ?? null,
                        'date'        => $v['VoucherDate'] ?? null,
                        'receipt_no'  => (int) ($v['ReceiptNo'] ?? 0),
                        'record_type' => $v['RelativeRecordType'] ?? null,
                        'kind'        => $isReceipt ? 'receipt' : 'journal',
                    ];
                }
                $vouchers['total'] = count($vouchers['rows']);
            } catch (\Throwable $e) {
                $vouchers['error'] = $e->getMessage();
            }
        }

        // ---- Verdict ------------------------------------------------------------------------
        // Primary check: does the REAL cash movement (net = received − refunds) match what the
        // contract was BILLED? Comparing against billed (not the recorded-collected figure)
        // surfaces genuinely unpaid / partly-paid contracts as a gap — the real cash view. The
        // tolerance absorbs VAT rounding and bank/card fees; only a gap beyond it is an exception.
        $cashGap   = round($cash['net'] - $billed, 2);
        $base      = max(abs($billed), abs($cash['net']), 1.0);
        $tolerance = round(max($base * ($tolerancePct / 100), $toleranceFloor), 2);
        $within    = abs($cashGap) <= $tolerance;
        $hasError  = $cash['error'] !== null || $vouchers['error'] !== null;

        $flags = [];
        if ($vouchers['total'] === 0 && $vouchers['error'] === null && $c->contract_serial) {
            $flags[] = [
                'level'   => 'critical',
                'code'    => 'not_posted',
                'message' => 'No accounting voucher found — this contract appears never to have been posted to the books.',
            ];
        }
        if (! $within && $cash['error'] === null && $c->contract_no) {
            $flags[] = [
                'level'   => 'warning',
                'code'    => 'cash_mismatch',
                'message' => sprintf(
                    'Cash gap of AED %s (%.1f%%) between net cash collected (AED %s) and amount billed (AED %s) — beyond the %.0f%% / AED %s tolerance.',
                    number_format(abs($cashGap), 2),
                    abs($cashGap) / $base * 100,
                    number_format($cash['net'], 2),
                    number_format($billed, 2),
                    $tolerancePct,
                    number_format($toleranceFloor, 2),
                ),
            ];
        }

        $status = $hasError
            ? 'error'
            : (empty($flags) ? 'reconciled' : (in_array('not_posted', array_column($flags, 'code'), true) ? 'exception' : 'review'));

        return [
            'contract' => [
                'id'              => $c->id,
                'contract_no'     => $c->contract_no,
                'contract_serial' => $c->contract_serial,
                'type'            => $c->contract_type,
                'customer'        => optional($c->customer)->name_en,
                'customer_no'     => optional($c->customer)->customer_no,
                'vehicle'         => optional($c->vehicle)->plate_no,
                'out_date'        => optional($c->out_date)->toDateString(),
                'in_date'         => optional($c->in_date)->toDateString(),
            ],
            'fleet' => [
                'income'      => round($income, 2),
                'billed'      => round($billed, 2),
                'recorded'    => round($recorded, 2),
                'outstanding' => round($outstanding, 2),
            ],
            'cash'     => $cash,
            'vouchers' => $vouchers,
            'reconciliation' => [
                'status'           => $status,   // reconciled | review | exception | error
                'cash_gap'         => $cashGap,
                'tolerance'        => $tolerance,
                'tolerance_pct'    => $tolerancePct,
                'within_tolerance' => $within,
                'flags'            => $flags,
            ],
        ];
    }
}
