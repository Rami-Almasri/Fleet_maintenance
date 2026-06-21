<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\MaintenanceReasonMatcher;
use Illuminate\Console\Command;

/**
 * Categorize each maintenance row by cross-referencing the sheet's MAIN column with the
 * controlled "Maintenance Reason" (سبب الصيانة) vocabulary, storing the link in
 * maintenances.maintenance_reason_id. MAIN is the primary key; SUP refines it ONLY on an
 * exact reason-name match. Deterministic — no heuristics. Idempotent; safe to re-run.
 */
class LinkMaintenanceReasons extends Command
{
    protected $signature = 'maintenance:link-reasons {--dry-run} {--relink : re-evaluate rows that already have a reason}';

    protected $description = 'Categorize maintenance rows against the Maintenance Reason vocabulary (MAIN column, SUP exact refine)';

    public function handle(MaintenanceReasonMatcher $matcher): int
    {
        $dry = (bool) $this->option('dry-run');
        $relink = (bool) $this->option('relink');

        $query = Maintenance::query();
        if (! $relink) {
            $query->whereNull('maintenance_reason_id');
        }

        $total = (clone $query)->count();
        $this->info(($dry ? '[dry-run] ' : '') . "Categorizing {$total} maintenance row(s) via MAIN…");

        $linked = 0; $unmatched = 0; $changed = 0;
        $byLevel = ['critical' => 0, 'minor' => 0, 'routine' => 0, 'special' => 0];

        $query->orderBy('id')->chunkById(500, function ($chunk) use ($matcher, $dry, &$linked, &$unmatched, &$changed, &$byLevel) {
            foreach ($chunk as $m) {
                $reason = $matcher->resolve($m->service_main, $m->service_sup);

                if (! $reason) {
                    $unmatched++;
                    continue;
                }

                $byLevel[$reason->level] = ($byLevel[$reason->level] ?? 0) + 1;
                $linked++;

                if ($m->maintenance_reason_id !== $reason->id) {
                    $changed++;
                    if (! $dry) {
                        $m->forceFill(['maintenance_reason_id' => $reason->id])->save();
                    }
                }
            }
        });

        $this->info(sprintf(
            '%s matched %d (changed %d), unmatched %d  |  critical %d · minor %d · routine %d · special %d',
            $dry ? '[dry-run]' : 'Done —',
            $linked, $changed, $unmatched,
            $byLevel['critical'], $byLevel['minor'], $byLevel['routine'], $byLevel['special']
        ));

        return self::SUCCESS;
    }
}
