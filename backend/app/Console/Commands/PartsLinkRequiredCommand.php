<?php

namespace App\Console\Commands;

use App\Models\MaintenanceRequiredPart;
use App\Services\PartCatalogMatcher;
use Illuminate\Console\Command;

/**
 * Which required-part lines still name no catalog part — and link the ones that can be linked.
 *
 * The backfill migration deliberately refused to guess, so some lines came out of it unlinked. That
 * is the correct outcome ("break" identifies nothing), but it leaves work a human has to finish, and
 * work nobody can see does not get finished. This is the report that makes it visible.
 *
 * Run it before switching PARTS_REQUIRE_CATALOG_LINK on: once enforcement is live, a line that names
 * no known part is refused at the door, so any PENDING unlinked line is about to become a problem
 * for whoever tries to re-save that report.
 *
 *   php artisan parts:link-required            # report only
 *   php artisan parts:link-required --link     # also link what matches exactly
 *   php artisan parts:link-required --pending  # only lines still awaiting a decision
 */
class PartsLinkRequiredCommand extends Command
{
    protected $signature = 'parts:link-required
                            {--link : Link lines whose text matches a catalog part exactly}
                            {--pending : Only consider lines still in the pending state}';

    protected $description = 'Report required-part lines with no catalog reference, and optionally link the unambiguous ones';

    public function handle(PartCatalogMatcher $matcher): int
    {
        $query = MaintenanceRequiredPart::query()->whereNull('component_catalog_id');

        if ($this->option('pending')) {
            $query->where('status', MaintenanceRequiredPart::STATUS_PENDING);
        }

        $rows = $query->orderBy('id')->get(['id', 'maintenance_id', 'part_name', 'status']);

        if ($rows->isEmpty()) {
            $this->info('Every required-part line references the catalog. Safe to enable PARTS_REQUIRE_CATALOG_LINK.');

            return self::SUCCESS;
        }

        $linked = 0;
        $table  = [];

        foreach ($rows as $row) {
            $hit = $matcher->resolve($row->part_name);

            if ($hit['catalog_id'] && $this->option('link')) {
                $row->update([
                    'component_catalog_id' => $hit['catalog_id'],
                    'catalog_matched_by'   => $hit['matched_by'],
                ]);
                $linked++;
            }

            $table[] = [
                $row->id,
                $row->maintenance_id,
                $row->status,
                mb_strimwidth((string) $row->part_name, 0, 40, '…'),
                $hit['candidate'] ?? '—',
                $hit['catalog_id'] ? $hit['matched_by'] : 'no match',
            ];
        }

        $this->table(['id', 'ticket', 'status', 'typed text', 'would link to', 'how'], $table);

        $unresolved = collect($table)->where(5, 'no match')->count();

        if ($this->option('link')) {
            $this->info("Linked {$linked} line(s).");
        } elseif ($linked === 0 && $unresolved < count($table)) {
            $this->comment('Re-run with --link to attach the lines that match exactly.');
        }

        if ($unresolved > 0) {
            // These are the ones no rule can settle. A person has to choose, or the line is wrong.
            $this->warn("{$unresolved} line(s) match no catalog part and need a human decision.");
            $this->line('  Fix each by picking the part on the ticket, or add the missing part to the catalog.');
        }

        return self::SUCCESS;
    }
}
