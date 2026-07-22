<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Financial Conflicts" — the accounting Clean-up Hub. Surfaces ONLY the invoices that
 * are broken or inconsistent, so they can be fixed; healthy invoices are never listed.
 *
 * Three checks:
 *   1. VAT math       — total_value + vat_value != total_after_vat on the invoice itself.
 *   2. Field mismatch — the invoice's car / out-date / in-date disagree with the contract
 *                       it is attached to (a snapshot that drifted, or a wrong link).
 *   3. Overlap        — two invoices on the SAME contract bill overlapping date ranges
 *                       (possible double-billing). Heuristic, flagged for review.
 *
 * Mirrors DataHealthService: each check returns a group { key, title, severity, description,
 * count, shown, items }. `count` is the true total; `items` is capped at CAP.
 */
class FinancialConflictService
{
    /** Max example rows returned per group (count is always the true total). */
    private const CAP = 100;

    /** Money tolerance — ignore sub-cent rounding noise. */
    private const EPS = 0.01;

    public function all(): array
    {
        $groups = [
            $this->vatMath(),
            $this->fieldMismatch(),
            $this->duplicateBilling(),
            $this->negativeAmounts(),
            $this->zeroTotal(),
            $this->negativeRentDays(),
        ];

        $bySeverity = fn ($sev) => array_sum(array_map(
            fn ($g) => $g['severity'] === $sev ? $g['count'] : 0,
            $groups
        ));

        return [
            'total_conflicts' => array_sum(array_map(fn ($g) => $g['count'], $groups)),
            'flagged_groups'  => count(array_filter($groups, fn ($g) => $g['count'] > 0)),
            'critical'        => $bySeverity('critical'),
            'warning'         => $bySeverity('warning'),
            'info'            => $bySeverity('info'),
            'groups'          => $groups,
        ];
    }

    // ---------------------------------------------------------------- 1. VAT math

    /**
     * Invoices whose figures don't add up — DISCOUNT-AWARE. OfficeManager computes the total
     * as (Value − Discount) + VAT, with VAT on the post-discount base, so reconciling against
     * Value + VAT alone falsely flags every discounted invoice "off by the discount".
     *
     * We reconcile against the POST-DISCOUNT BASE + VAT. The authoritative base is
     * `total_after_discount` (populated on ~96% of invoices, straight from OM's TotalAfterDiscount);
     * the standalone `discount` column is unreliable — legacy invoices carry a real discount baked
     * into total_after_discount while `discount` still reads 0.00, which falsely flagged them as
     * "off by the discount". We therefore prefer total_after_discount and fall back to
     * (Value − Discount) only when it is absent. Only a genuine leftover gap is a real error.
     */
    private function vatMath(): array
    {
        // Post-discount base: trust total_after_discount, else derive from Value − Discount.
        $baseExpr = 'COALESCE(total_after_discount, COALESCE(total_value,0) - COALESCE(discount,0))';
        $expr = "ABS($baseExpr + COALESCE(vat_value,0) - COALESCE(total_after_vat,0))";
        $base = Invoice::whereRaw("$expr > ?", [self::EPS]);

        $count = (clone $base)->count();
        $items = (clone $base)
            ->with(['contract:id,contract_no,vehicle_id', 'contract.vehicle:id,plate_no,make,model'])
            ->orderByRaw("$expr DESC")
            ->limit(self::CAP)->get()
            ->map(function (Invoice $i) {
                // Same precedence as the SQL: prefer total_after_discount as the post-discount base.
                $hasTad   = $i->total_after_discount !== null;
                $afterDisc = $hasTad ? (float) $i->total_after_discount : (float) $i->total_value - (float) $i->discount;
                $discount  = $hasTad ? (float) $i->total_value - (float) $i->total_after_discount : (float) $i->discount;
                $net   = $afterDisc + (float) $i->vat_value;
                $delta = $net - (float) $i->total_after_vat;
                $detail = sprintf(
                    'Value %s − Discount %s + VAT %s = %s, but total is %s (off by %s)',
                    number_format((float) $i->total_value, 2),
                    number_format($discount, 2),
                    number_format((float) $i->vat_value, 2),
                    number_format($net, 2),
                    number_format((float) $i->total_after_vat, 2),
                    number_format(abs($delta), 2),
                );

                return $this->row($i, $detail, number_format((float) $i->total_after_vat, 2));
            })->all();

        return $this->group('vat_math', 'VAT math errors', 'warning',
            'Invoices where the post-discount base + VAT does not add up to the stated Total. The check is discount-aware — it reconciles against OfficeManager\'s TotalAfterDiscount (the real post-discount base), so legitimate business discounts are NOT flagged; only genuinely inconsistent figures remain.',
            $count, $items);
    }

    // ---------------------------------------------------------- 2. Field mismatch

    /**
     * The invoice's snapshot of the car / period disagrees with the contract it is linked
     * to. Cars are compared by RESOLVED VEHICLE (a serial that maps to a different vehicle
     * in our fleet) — never raw serial strings — so OfficeManager's "one car, many
     * CarSerials" quirk does not create false positives.
     */
    private function fieldMismatch(): array
    {
        $base = DB::table('invoices as i')
            ->join('contracts as c', 'c.id', '=', 'i.contract_id')
            ->leftJoin('vehicles as iv', 'iv.car_serial', '=', 'i.car_serial')   // the invoice's car as a known vehicle
            ->leftJoin('vehicles as v', 'v.id', '=', 'c.vehicle_id')             // the contract's car
            ->whereNull('c.deleted_at')
            ->where(function ($q) {
                $q->whereColumn('iv.id', '<>', 'c.vehicle_id') // invoice serial is a DIFFERENT known vehicle
                  ->orWhereRaw('(i.contract_out_date IS NOT NULL AND c.out_date IS NOT NULL AND DATE(i.contract_out_date) <> DATE(c.out_date))')
                  ->orWhereRaw('(i.contract_in_date IS NOT NULL AND c.in_date IS NOT NULL AND DATE(i.contract_in_date) <> DATE(c.in_date))');
            });

        $count = (clone $base)->count();
        $rows  = (clone $base)
            ->orderByDesc('i.invoice_date')
            ->limit(self::CAP)
            ->get([
                'i.id as invoice_id', 'i.invoice_no', 'i.car_serial as i_car_serial',
                'i.contract_out_date', 'i.contract_in_date',
                'c.id as contract_id', 'c.contract_no', 'c.vehicle_id', 'c.car_serial as c_car_serial',
                'c.out_date', 'c.in_date',
                'iv.id as inv_vehicle_id',
                'v.plate_no', 'v.make', 'v.model',
            ]);

        $items = $rows->map(function ($r) {
            $reasons = [];
            if ($r->inv_vehicle_id && (int) $r->inv_vehicle_id !== (int) $r->vehicle_id) {
                $reasons[] = sprintf('Car: invoice is for serial %s (a different vehicle) — contract car is serial %s', $r->i_car_serial, $r->c_car_serial ?: '—');
            }
            if ($r->contract_out_date && $r->out_date && $this->day($r->contract_out_date) !== $this->day($r->out_date)) {
                $reasons[] = sprintf('Out date: invoice %s vs contract %s', $this->day($r->contract_out_date), $this->day($r->out_date));
            }
            if ($r->contract_in_date && $r->in_date && $this->day($r->contract_in_date) !== $this->day($r->in_date)) {
                $reasons[] = sprintf('In date: invoice %s vs contract %s', $this->day($r->contract_in_date), $this->day($r->in_date));
            }

            return [
                'invoice_id'  => $r->invoice_id,
                'invoice_no'  => $r->invoice_no,
                'contract_id' => $r->contract_id,
                'contract_no' => $r->contract_no,
                'vehicle_id'  => $r->vehicle_id,
                'plate'       => $r->plate_no,
                'car'         => trim((string) ($r->make . ' ' . $r->model)) ?: null,
                'amount'      => null,
                'detail'      => implode(' · ', $reasons),
            ];
        })->all();

        return $this->group('field_mismatch', 'Invoice ↔ contract mismatch', 'warning',
            'Invoices whose car or rental dates disagree with the contract they are attached to. A mismatch means the invoice was linked to the wrong contract, or the contract was edited after the invoice was issued. Cars are compared by resolved vehicle, so the same car under a different OfficeManager serial is not flagged.',
            $count, $items);
    }

    // ------------------------------------------------------------- 3. Duplicate billing

    /**
     * Genuine double-billing = the EXACT SAME charge issued more than once: same contract,
     * same invoice date, same amount. This is precise (near-zero false positives).
     *
     * We deliberately do NOT infer overlaps from billing periods: OM's invoice_date is the
     * billing date (not the period start), rent_days is unreliable (even negative), and
     * period_from/period_to are frequently inverted (e.g. From 2024-12-01 / To 2024-11-30).
     * A window-overlap check on that data flags hundreds of normal back-to-back monthly bills
     * as "double-charges" — useless. Exact duplicates are the signal we can actually trust.
     */
    private function duplicateBilling(): array
    {
        $groups = DB::table('invoices')
            ->select('contract_id', 'invoice_date', 'total_after_vat', DB::raw('COUNT(*) as c'))
            ->whereNotNull('contract_id')->whereNotNull('invoice_date')->where('total_after_vat', '<>', 0)
            ->groupBy('contract_id', 'invoice_date', 'total_after_vat')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $count = (int) $groups->sum('c'); // every invoice that participates in a duplicate

        $items = [];
        foreach ($groups as $grp) {
            if (count($items) >= self::CAP) {
                break;
            }
            $dups = Invoice::where('contract_id', $grp->contract_id)
                ->whereDate('invoice_date', $grp->invoice_date)
                ->where('total_after_vat', $grp->total_after_vat)
                ->with(['contract:id,contract_no,vehicle_id', 'contract.vehicle:id,plate_no,make,model'])
                ->orderBy('invoice_no')->get();
            $nos = $dups->pluck('invoice_no')->implode(', #');

            foreach ($dups as $inv) {
                if (count($items) >= self::CAP) {
                    break;
                }
                $items[] = $this->row(
                    $inv,
                    sprintf('Billed %d× — same contract, same date (%s), same amount (%s). Duplicates: #%s',
                        (int) $grp->c, $this->day($grp->invoice_date), number_format((float) $grp->total_after_vat, 2), $nos),
                    number_format((float) $inv->total_after_vat, 2),
                );
            }
        }

        return $this->group('duplicate_billing', 'Duplicate billing (same charge billed twice)', 'critical',
            'The exact same charge issued more than once on a contract — same date and same amount. A precise double-charge signal. (We do not infer overlaps from billing periods: OfficeManager\'s invoice periods are unreliable, so a window-overlap check produced hundreds of false positives on normal monthly billing.)',
            $count, $items);
    }

    // ------------------------------------------------------- 4. Negative amounts

    /** Invoices carrying a negative total or VAT — credit notes / reversals to confirm. */
    private function negativeAmounts(): array
    {
        $base = Invoice::where(fn ($q) => $q->where('total_after_vat', '<', 0)->orWhere('vat_value', '<', 0));

        $count = (clone $base)->count();
        $items = (clone $base)
            ->with(['contract:id,contract_no,vehicle_id', 'contract.vehicle:id,plate_no,make,model'])
            ->orderBy('total_after_vat')
            ->limit(self::CAP)->get()
            ->map(fn (Invoice $i) => $this->row(
                $i,
                sprintf(
                    'Negative figures — value %s, VAT %s, total %s. Usually a credit note / refund; confirm it is intentional and not a sign-flip error.',
                    number_format((float) $i->total_value, 2),
                    number_format((float) $i->vat_value, 2),
                    number_format((float) $i->total_after_vat, 2),
                ),
                number_format((float) $i->total_after_vat, 2),
            ))->all();

        return $this->group('negative_amounts', 'Negative invoice amounts', 'info',
            'Invoices carrying a negative total or VAT — typically credit notes, reversals or refunds. Valid in accounting, but listed so you can confirm each is intentional and not an entry/sign error.',
            $count, $items);
    }

    // ----------------------------------------------------------- 5. Zero / blank totals

    /** Invoices with a zero or NULL total — voided, draft, or a charge never billed. */
    private function zeroTotal(): array
    {
        $base = Invoice::where(fn ($q) => $q->whereNull('total_after_vat')->orWhere('total_after_vat', 0));

        $count = (clone $base)->count();
        $items = (clone $base)
            ->with(['contract:id,contract_no,vehicle_id', 'contract.vehicle:id,plate_no,make,model'])
            ->orderByDesc('invoice_date')
            ->limit(self::CAP)->get()
            ->map(fn (Invoice $i) => $this->row(
                $i,
                'Total is zero or blank — a voided / draft invoice, or one not yet billed. Verify it should genuinely carry no charge.',
                number_format((float) $i->total_after_vat, 2),
            ))->all();

        return $this->group('zero_total', 'Zero-total invoices', 'info',
            'Invoices whose total is zero or blank — often voided/cancelled or not-yet-billed documents. Surfaced so a genuinely missing charge is not overlooked.',
            $count, $items);
    }

    // -------------------------------------------------------- 6. Negative rent days

    /** Invoices whose RentDays is negative — the source computed the period backwards. */
    private function negativeRentDays(): array
    {
        $base = Invoice::where('rent_days', '<', 0);

        $count = (clone $base)->count();
        $items = (clone $base)
            ->with(['contract:id,contract_no,vehicle_id', 'contract.vehicle:id,plate_no,make,model'])
            ->orderBy('rent_days')
            ->limit(self::CAP)->get()
            ->map(fn (Invoice $i) => $this->row(
                $i,
                sprintf('Rent days is negative (%d) — OfficeManager has this invoice\'s contract in/out dates reversed.', (int) $i->rent_days),
                number_format((float) $i->total_after_vat, 2),
            ))->all();

        return $this->group('negative_rent_days', 'Negative rent days', 'warning',
            'Invoices whose RentDays is negative (in-date before out-date at the source). Stored verbatim from OfficeManager; flagged for correction there.',
            $count, $items);
    }

    // ------------------------------------------------------------------------ helpers

    /** A standard invoice row for the table (VAT-math / single-invoice groups). */
    private function row(Invoice $i, string $detail, ?string $amount = null): array
    {
        $c = $i->contract;

        return [
            'invoice_id'  => $i->id,
            'invoice_no'  => $i->invoice_no,
            'contract_id' => $c?->id ?? $i->contract_id,
            'contract_no' => $c?->contract_no,
            'vehicle_id'  => $c?->vehicle_id,
            'plate'       => $c?->vehicle?->plate_no,
            'car'         => $c?->vehicle ? (trim((string) ($c->vehicle->make . ' ' . $c->vehicle->model)) ?: null) : null,
            'amount'      => $amount,
            'detail'      => $detail,
        ];
    }

    /** Normalise any date-ish value to a Y-m-d string for comparison/display. */
    private function day($value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateString() : Carbon::parse($value)->toDateString();
    }

    private function group(string $key, string $title, string $severity, string $description, int $count, array $items): array
    {
        return [
            'key'         => $key,
            'title'       => $title,
            'severity'    => $severity,
            'description' => $description,
            'count'       => $count,
            'shown'       => count($items),
            'items'       => $items,
        ];
    }
}
