<?php

namespace App\Console\Commands;

use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\VehicleComponent;
use App\Services\GarageLineItemLedgerService;
use Illuminate\Console\Command;

/**
 * Put the parts a GARAGE supplied and billed for into the parts ledger they were always missing from.
 *
 * Historically a part line on a garage's bill was money and nothing else. This walks every one of
 * them through {@see GarageLineItemLedgerService} — the same door the live invoice path now uses, so
 * history and new work land in exactly the same shape.
 *
 * SAFE BY CONSTRUCTION:
 *   · --dry-run is the default. Nothing is written unless --apply is passed.
 *   · Idempotent. Re-running updates the same purchases and creates no second component; the unique
 *     index on part_purchases.maintenance_line_item_id is the backstop under the service's own check.
 *   · Non-destructive. It creates and links; it deletes nothing and rewrites no invoice.
 *   · It invents nothing. A line with no canonical part gets no purchase, a part type needing a
 *     position it does not have gets no component, and an unknown install date stays unknown.
 *
 * The report is the point as much as the write: every line lands in exactly one bucket, and the
 * buckets that are refusals say why in words rather than being quietly absent from the total.
 */
class BackfillGarageLineParts extends Command
{
    protected $signature = 'parts:backfill-garage-lines
        {--apply : Write the changes (without this the command only reports)}
        {--actor= : User id to attribute the records to (defaults to the lowest-id admin)}';

    protected $description = 'Link garage-billed part lines into the unified part lifecycle (purchase + component where the data supports one)';

    public function handle(GarageLineItemLedgerService $ledger): int
    {
        $apply = (bool) $this->option('apply');

        $actor = $this->resolveActor();
        if (! $actor) {
            $this->error('No user to attribute these records to. Pass --actor=<user id>.');

            return self::FAILURE;
        }

        $lines = $ledger->pending();

        if ($lines->isEmpty()) {
            $this->info('No part lines found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(($apply ? 'APPLYING' : 'DRY RUN — nothing will be written') . ' · ' . $lines->count() . ' part lines · actor: ' . ($actor->name ?: $actor->email));
        $this->line('');

        $buckets = [];
        $reasons = [];
        $created = $updated = $components = 0;

        // Slots this run would fill, so a DRY RUN predicts what an apply actually does.
        //
        // Without this the two disagree, and badly: classify() asks the database, which cannot see
        // the components earlier lines in the SAME run are about to create. A car billed twice for
        // one part type reads as two components in the report and produces one in reality — the
        // second correctly deferring, because a car does not have two active alternators. A dry run
        // that overstates what it will do is worse than no dry run, since it is read as a promise.
        $wouldFill = [];

        foreach ($lines as $line) {
            $verdict = $ledger->classify($line);

            if ($verdict['catalog'] && $verdict['reason'] === null) {
                $slot = $line->vehicle_id . ':' . $verdict['catalog']->id;
                if (isset($wouldFill[$slot])) {
                    $verdict['reason'] = 'deferred_slot_occupied';
                } else {
                    $wouldFill[$slot] = true;
                }
            }

            $bucket = $this->bucketFor($verdict);

            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
            if ($verdict['reason']) {
                $reasons[$verdict['reason']] = ($reasons[$verdict['reason']] ?? 0) + 1;
            }

            if (! $apply || in_array($verdict['action'], ['skip', 'needs_review'], true)) {
                continue;
            }

            try {
                $res = $ledger->syncLine($line, $actor);
            } catch (\Throwable $e) {
                // One bad line must not cost the other 86. Report it and carry on — the run is
                // resumable, so a fixed line is picked up by the next pass.
                $this->warn("  line #{$line->id}: {$e->getMessage()}");
                $buckets['failed'] = ($buckets['failed'] ?? 0) + 1;

                continue;
            }

            if ($res['purchase']) {
                $res['created'] ? $created++ : $updated++;
            }
            // Only genuinely NEW ones. installFromGarageLine returns the component it already built
            // when asked again, and counting that as a write would make a no-op re-run report 33
            // creations — the exact false reassurance an idempotency check exists to disprove.
            if ($res['component'] && $res['component']->wasRecentlyCreated) {
                $components++;
            }
        }

        $this->report($apply, $buckets, $reasons, $created, $updated, $components);

        if (! $apply) {
            $this->line('');
            $this->comment('Re-run with --apply to write these changes.');
        }

        return self::SUCCESS;
    }

    /** Which line of the report a classified line belongs on. */
    private function bucketFor(array $verdict): string
    {
        if ($verdict['action'] === 'skip') {
            return 'skipped';
        }

        if ($verdict['action'] === 'needs_review') {
            return 'needs_review';
        }

        return match (true) {
            $verdict['reason'] === null                                              => 'purchase_and_component',
            $verdict['reason'] === 'already_component'                               => 'already_done',
            in_array($verdict['reason'], GarageLineItemLedgerService::DEFERRED_REASONS, true) => 'purchase_component_deferred',
            default                                                                  => 'purchase_only',
        };
    }

    private function report(bool $apply, array $buckets, array $reasons, int $created, int $updated, int $components): void
    {
        $labels = [
            'purchase_and_component'      => 'Purchase + component',
            'purchase_component_deferred' => 'Purchase, component DEFERRED',
            'purchase_only'               => 'Purchase only (component not applicable)',
            'already_done'                => 'Already linked',
            'needs_review'                => 'NOT migratable — needs review',
            'skipped'                     => 'Skipped (not a part line / no vehicle)',
            'failed'                      => 'Failed',
        ];

        $this->table(['Outcome', 'Lines'], collect($labels)
            ->map(fn ($label, $key) => [$label, $buckets[$key] ?? 0])
            ->filter(fn ($row) => $row[1] > 0)
            ->values()
            ->all());

        if ($reasons) {
            $this->line('');
            $this->line('Reasons:');
            foreach ($reasons as $reason => $n) {
                $this->line(sprintf('  %-32s %d', $reason, $n));
            }
        }

        if ($apply) {
            $this->line('');
            $this->info("Written: {$created} purchases created, {$updated} updated, {$components} components created.");
        }

        // The chain the whole exercise exists to produce, counted end to end.
        $this->line('');
        $this->line('Ledger now holds:');
        $this->line('  garage purchases linked to a bill line   ' . PartPurchase::where('purchase_source', PartPurchase::SOURCE_GARAGE)->whereNotNull('maintenance_line_item_id')->count());
        $this->line('  components born from a bill line         ' . VehicleComponent::whereNotNull('source_line_item_id')->count());
        $this->line('  part lines with no canonical part        ' . MaintenanceLineItem::where('kind', MaintenanceLineItem::KIND_PART)->whereNull('component_catalog_id')->count());
    }

    /**
     * Who to attribute the records to. These rows are written by a migration, not by a person, so
     * the attribution names the account that RAN it rather than pretending someone fitted the part.
     */
    private function resolveActor(): ?User
    {
        if ($id = $this->option('actor')) {
            return User::find($id);
        }

        return User::orderBy('id')->first();
    }
}
