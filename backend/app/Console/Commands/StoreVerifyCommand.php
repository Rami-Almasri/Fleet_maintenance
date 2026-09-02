<?php

namespace App\Console\Commands;

use App\Models\StoreItem;
use App\Models\StoreMovement;
use Illuminate\Console\Command;

/**
 * Prove that every shelf level is exactly what its movement ledger says it should be.
 *
 *   php artisan store:verify
 *   php artisan store:verify --fix   # rewrite a drifted qty_on_hand from the ledger
 *
 * store_items.qty_on_hand is a running total that StoreService maintains beside the store_movements
 * rows that justify it. That is a cache, and a cache is a claim — this command is what turns the
 * claim into a checked fact. It replays each shelf's movements in id order and compares three
 * things: the sum of the signed quantities against the stored level, and each movement's own
 * `qty_after` against the running total at that point (which catches a level that was corrected
 * without a movement, the failure a bare SUM would miss).
 *
 * --fix trusts the LEDGER, never the cache. The ledger is append-only evidence of things that
 * happened; the cache is a convenience. If the two disagree, the convenience is wrong.
 */
class StoreVerifyCommand extends Command
{
    protected $signature = 'store:verify {--fix : Rewrite a drifted qty_on_hand from its ledger}';

    protected $description = 'Verify every storehouse shelf against its movement ledger';

    public function handle(): int
    {
        $items = StoreItem::query()->orderBy('id')->get();

        if ($items->isEmpty()) {
            $this->info('The storehouse holds no shelves yet — nothing to verify.');

            return self::SUCCESS;
        }

        $drifted = 0;
        $broken  = 0;
        $rows    = [];

        foreach ($items as $item) {
            $running = 0.0;
            $stepMismatch = null;

            $movements = StoreMovement::query()->where('store_item_id', $item->id)->orderBy('id')->get();

            foreach ($movements as $movement) {
                $running += $movement->signedQuantity();

                // A movement whose recorded aftermath disagrees with the replay means something
                // changed the shelf outside move() — the one thing this design forbids.
                if ($stepMismatch === null && abs($running - (float) $movement->qty_after) > 0.001) {
                    $stepMismatch = $movement->id;
                }
            }

            $stored = (float) $item->qty_on_hand;
            $off    = abs($running - $stored) > 0.001;

            if (! $off && $stepMismatch === null) {
                continue;
            }

            $off ? $drifted++ : null;
            $stepMismatch !== null ? $broken++ : null;

            $rows[] = [
                $item->id,
                $item->part_name,
                number_format($stored, 2),
                number_format($running, 2),
                $stepMismatch !== null ? "movement #{$stepMismatch}" : '—',
            ];

            if ($off && $this->option('fix')) {
                $item->forceFill(['qty_on_hand' => $running])->save();
            }
        }

        if ($rows === []) {
            $this->info("✓ All {$items->count()} shelves agree with their ledgers.");

            return self::SUCCESS;
        }

        $this->table(['Shelf', 'Part', 'Stored', 'Ledger says', 'First bad step'], $rows);
        $this->warn("{$drifted} shelf level(s) drifted; {$broken} ledger(s) have a step that does not replay.");

        if ($this->option('fix')) {
            $this->info('Drifted levels were rewritten from their ledgers.');
        } else {
            $this->line('Run again with --fix to rewrite the drifted levels from the ledger.');
        }

        // A broken STEP is not fixable by recomputing a total — it means a write bypassed the
        // service, and that needs a person, so it always fails the command.
        return $broken > 0 ? self::FAILURE : ($this->option('fix') ? self::SUCCESS : self::FAILURE);
    }
}
