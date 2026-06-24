<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile contract-level discount with the invoice-level discount.
 *
 * OfficeManager normally records a rental's discount in BOTH `ContractDiscount` (the contract
 * rollup) and on each invoice's `Discount` line, and the two reconcile. A handful of older
 * contracts, however, carry a discount on their invoices that OM never rolled up to the contract
 * — so `contracts.contract_discount` reads 0 while the invoices clearly show a discount. Real Net
 * Profit deducts the CONTRACT field, so those discounts are silently missed (profit overstated).
 *
 * This backfills `contract_discount = SUM(invoices.discount)` for type-'C' rentals where the
 * contract field is empty but the invoices show a real discount. Idempotent (re-running changes
 * nothing once reconciled) and safe to re-run after a sync surfaces new edge cases. Closed
 * contracts fall outside the OM re-sync window, so the backfill persists.
 */
class ReconcileContractDiscounts extends Command
{
    protected $signature = 'contracts:reconcile-discounts {--dry-run : List what would change without writing}';

    protected $description = 'Backfill contract_discount from the invoice discount sum where OM left the contract rollup empty.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Type-'C' rentals whose contract discount is empty/zero but whose invoices carry a discount.
        $candidates = DB::table('contracts as c')
            ->join('invoices as i', 'i.contract_id', '=', 'c.id')
            ->where('c.contract_type', 'C')
            ->whereRaw('COALESCE(c.contract_discount, 0) = 0')
            ->groupBy('c.id', 'c.contract_no')
            ->havingRaw('SUM(COALESCE(i.discount, 0)) > 0')
            ->select('c.id', 'c.contract_no', DB::raw('ROUND(SUM(COALESCE(i.discount,0)), 2) as inv_discount'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to reconcile — every contract discount already matches its invoices.');

            return self::SUCCESS;
        }

        $this->table(
            ['Contract', 'contract_discount (now)', 'invoice discount sum (→ set)'],
            $candidates->map(fn ($c) => [$c->contract_no, '0.00', number_format((float) $c->inv_discount, 2)])->all()
        );
        $total = round((float) $candidates->sum('inv_discount'), 2);
        $this->line(sprintf('%d contract(s), %s AED total.', $candidates->count(), number_format($total, 2)));

        if ($dry) {
            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($candidates as $c) {
            $updated += DB::table('contracts')
                ->where('id', $c->id)
                ->whereRaw('COALESCE(contract_discount, 0) = 0')   // guard: never overwrite a real value
                ->update(['contract_discount' => $c->inv_discount]);
        }

        $this->info("Reconciled {$updated} contract(s).");

        return self::SUCCESS;
    }
}
