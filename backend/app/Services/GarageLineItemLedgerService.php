<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\VehicleComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GarageLineItemLedgerService — the one road from a GARAGE'S BILL into the parts ledger.
 *
 * THE DEFECT THIS CLOSES. A part fitted by a garage lived in exactly one place: a
 * maintenance_line_items row (kind=part) holding its price. It never became a PartPurchase, never
 * reached a vehicle's component list, never carried a warranty, never appeared in that part's
 * history or price statistics. A supplier-bought battery and a garage-fitted battery were the same
 * physical object recorded in two universes that could not see each other — so "how many batteries
 * has this fleet fitted, and what did they cost?" had an answer that was quietly missing most of
 * the fleet's actual parts.
 *
 * WHAT IT DOES NOT DO — and this is the point:
 *
 *   · It does NOT create a second money record. The line item remains the canonical dirham.
 *     PartSpendService already sums "line items + purchases with NO maintenance_line_item_id", so
 *     every purchase written here carries one and is excluded from the total BY THE EXISTING
 *     DESIGN. Nothing in that service changes, and no figure on any page moves.
 *   · It does NOT create a separate "garage part" concept. It writes an ordinary PartPurchase with
 *     purchase_source='garage' — a value the table has carried since it was created, and which
 *     CostSourceResolver already knows to trace back to the garage's bill.
 *   · It does NOT invent facts. Install dates, positions, warranties and canonical identities are
 *     copied when known and left NULL when not. Every refusal is named and reportable.
 *
 * ── WHY RECONCILE, NOT JUST CREATE ──────────────────────────────────────────────────────────────
 * MaintenanceInvoiceService::syncLineItems deletes and recreates every work line on each invoice
 * save. Line ids are therefore not stable, and a create-on-write hook would raise a fresh duplicate
 * purchase on every edit of the same bill. {@see syncInvoice} matches the bill's parts to the
 * purchases already standing against that bill, by part identity and then by ordinal within it, and
 * updates rather than re-creates. A purchase whose line has vanished from the bill is deleted only
 * when nothing physical was built on it; if it produced a component, it is kept and flagged for a
 * human, because deleting it would erase a part that is on a car.
 */
class GarageLineItemLedgerService
{
    /** Refusals that are permanent facts about the data, not a queue to be worked. */
    public const TERMINAL_REASONS = ['no_canonical_part', 'consumable_never_a_component', 'no_vehicle'];

    /** Refusals a human can clear later, once the missing fact is known. */
    public const DEFERRED_REASONS = ['deferred_missing_position', 'deferred_slot_occupied'];

    public function __construct(
        private ComponentService $components,
        private PartIntelligenceService $intelligence,
        private PartIdentityService $identity,
    ) {
    }

    // ───────────────────────────── the read: what WOULD happen ─────────────────────────────

    /**
     * Classify a part line without writing anything — the dry run's unit of work, and the same
     * classification the write path then follows. Kept read-only so `--dry-run` and `--apply` can
     * never disagree about what a line is.
     *
     * @return array{line_id:int, action:string, reason:?string, catalog:?ComponentCatalog}
     */
    public function classify(MaintenanceLineItem $line): array
    {
        $out = fn (string $action, ?string $reason = null, ?ComponentCatalog $cat = null) => [
            'line_id' => $line->id,
            'action'  => $action,
            'reason'  => $reason,
            'catalog' => $cat,
        ];

        if ($line->kind !== MaintenanceLineItem::KIND_PART) {
            return $out('skip', 'not_a_part_line');
        }

        if (! $line->vehicle_id) {
            return $out('skip', 'no_vehicle');
        }

        // A line whose wording never resolved to a catalog entry is not a known part. It gets NO
        // purchase either: a purchase with a null identity is invisible to every repeat-buy and
        // price check that reads this table, so writing one would add a row and no knowledge.
        if (! $line->component_catalog_id) {
            return $out('needs_review', 'no_canonical_part');
        }

        $catalog = ComponentCatalog::find($line->component_catalog_id);
        if (! $catalog) {
            return $out('needs_review', 'no_canonical_part');
        }

        $existing = $this->purchaseFor($line);

        if ($catalog->isConsumable()) {
            // The purchase is still right — oil and filters ARE bought and billed. Only the
            // component is refused, by the standing rule.
            return $out($existing ? 'update_purchase' : 'create_purchase', 'consumable_never_a_component', $catalog);
        }

        if ($catalog->positionsFor() !== []) {
            return $out($existing ? 'update_purchase' : 'create_purchase', 'deferred_missing_position', $catalog);
        }

        if (VehicleComponent::where('source_line_item_id', $line->id)->exists()) {
            return $out($existing ? 'update_purchase' : 'create_purchase', 'already_component', $catalog);
        }

        if (VehicleComponent::forSlot($catalog->id, $line->vehicle_id, null)->exists()) {
            return $out($existing ? 'update_purchase' : 'create_purchase', 'deferred_slot_occupied', $catalog);
        }

        return $out($existing ? 'update_purchase' : 'create_purchase', null, $catalog);
    }

    // ───────────────────────────── the write: one line ─────────────────────────────

    /**
     * Put ONE billed part line into the ledger: a purchase, and a component when the data supports
     * one. Idempotent — running it twice on the same line updates the same purchase and never
     * produces a second component (the unique index on maintenance_line_item_id is the backstop).
     *
     * @return array{line_id:int, purchase:?PartPurchase, component:?VehicleComponent, created:bool, reason:?string}
     */
    public function syncLine(MaintenanceLineItem $line, User $actor): array
    {
        $verdict = $this->classify($line);

        $blank = [
            'line_id'   => $line->id,
            'purchase'  => null,
            'component' => null,
            'created'   => false,
            'reason'    => $verdict['reason'],
        ];

        if (in_array($verdict['action'], ['skip', 'needs_review'], true)) {
            return $blank;
        }

        return DB::transaction(function () use ($line, $actor, $verdict, $blank) {
            $purchase = $this->upsertPurchase($line, $actor, $verdict['catalog']);

            $result = array_merge($blank, [
                'purchase' => $purchase,
                'created'  => $purchase->wasRecentlyCreated,
            ]);

            // Terminal refusals never reach the asset layer — asking it would only make it repeat
            // the same answer, and a consumable would abort rather than decline.
            if (in_array($verdict['reason'], self::TERMINAL_REASONS, true)) {
                return $result;
            }

            $reason    = null;
            $component = $this->components->installFromGarageLine($purchase, $line, $actor, $reason);

            if (! $component && $reason) {
                $this->noteDeferral($purchase, $reason);
            }

            return array_merge($result, ['component' => $component, 'reason' => $reason ?: $verdict['reason']]);
        });
    }

    // ───────────────────────────── the write: a whole bill ─────────────────────────────

    /**
     * Reconcile every part line on a garage's bill against the purchases already standing behind it.
     *
     * Called after MaintenanceInvoiceService has rewritten the bill's lines, which is why this
     * matches on IDENTITY rather than on line id: the ids the caller just wrote are new, but the
     * parts they describe are mostly the same parts as before the edit.
     *
     * @return array{created:int, updated:int, components:int, deferred:array<string,int>, orphaned:int, flagged:int}
     */
    public function syncInvoice(MaintenanceInvoice $invoice, User $actor): array
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $lines = MaintenanceLineItem::where('maintenance_invoice_id', $invoice->id)
                ->where('kind', MaintenanceLineItem::KIND_PART)
                ->orderBy('id')
                ->get();

            $standing = PartPurchase::where('maintenance_invoice_id', $invoice->id)
                ->where('purchase_source', PartPurchase::SOURCE_GARAGE)
                ->orderBy('id')
                ->get()
                ->groupBy(fn (PartPurchase $p) => $this->identityKey($p->component_catalog_id, $p->part_name));

            $tally = ['created' => 0, 'updated' => 0, 'components' => 0, 'deferred' => [], 'orphaned' => 0, 'flagged' => 0];
            $claimed = [];

            foreach ($lines->groupBy(fn (MaintenanceLineItem $l) => $this->identityKey($l->component_catalog_id, $l->description)) as $key => $group) {
                foreach ($group->values() as $ordinal => $line) {
                    // Re-point the purchase that described this same part on the previous save at
                    // the line that describes it now. Without this the old line id (now deleted)
                    // would keep the unique index occupied and a duplicate would be created.
                    if ($match = ($standing[$key][$ordinal] ?? null)) {
                        $claimed[] = $match->id;
                        $match->maintenance_line_item_id = $line->id;
                        $match->save();
                    }

                    $res = $this->syncLine($line, $actor);

                    if (! $res['purchase']) {
                        continue;
                    }

                    $tally[$res['created'] ? 'created' : 'updated']++;
                    if ($res['component']) {
                        $tally['components']++;
                    } elseif ($res['reason']) {
                        $tally['deferred'][$res['reason']] = ($tally['deferred'][$res['reason']] ?? 0) + 1;
                    }
                }
            }

            // A purchase whose line is no longer on the bill. If nothing physical stands on it, the
            // line was a data-entry correction and the purchase goes with it. If a component DOES
            // stand on it, the part is on a car — deleting the purchase would orphan a physical
            // object, so it is kept and put in front of a human instead.
            foreach ($standing->flatten() as $purchase) {
                if (in_array($purchase->id, $claimed, true)) {
                    continue;
                }

                if ($purchase->component()->exists()) {
                    $purchase->requires_review = true;
                    $this->noteDeferral($purchase, 'line_removed_from_bill');
                    $tally['flagged']++;

                    continue;
                }

                $purchase->delete();
                $tally['orphaned']++;
            }

            return $tally;
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /**
     * Create or refresh the purchase behind a billed part line.
     *
     * Money fields are refreshed on every run because the bill is the authority on them: an invoice
     * corrected from 700 to 650 must not leave the ledger quoting the old figure. Identity fields
     * are refreshed for the same reason — a line re-matched to the right catalog entry should carry
     * the correction forward.
     */
    private function upsertPurchase(MaintenanceLineItem $line, User $actor, ?ComponentCatalog $catalog): PartPurchase
    {
        $purchase = $this->purchaseFor($line) ?: new PartPurchase();
        $ticket   = $line->maintenance;
        $vendor   = $ticket?->vendor;

        $purchase->fill([
            'vehicle_id'             => $line->vehicle_id,
            'maintenance_id'         => $line->maintenance_id,
            'maintenance_task_id'    => $line->maintenance_task_id,
            'maintenance_invoice_id' => $line->maintenance_invoice_id,
            'maintenance_line_item_id' => $line->id,

            'part_name'            => $line->description,
            'part_number'          => $line->part_number,
            'component_catalog_id' => $line->component_catalog_id,
            'catalog_matched_by'   => $line->catalog_matched_by,
            'category_key'         => $line->category_key ?: $catalog?->category_key,
            'part_class'           => $this->intelligence->classify($line->description, $line->part_number, $line->category_key),

            // WHO SUPPLIED IT: the garage on the ticket. This is the fact that makes the part
            // traceable to a party, and it is present on every one of these lines.
            'purchase_source' => PartPurchase::SOURCE_GARAGE,
            'source_vendor_id' => $ticket?->vendor_id,
            'source_name'      => $vendor?->name,

            // The bill's unit price, matching what installPurchase writes in the other direction.
            'purchase_price' => $line->unit_price ?: 0,
            'quantity'       => $line->quantity ?: 1,
            'currency'       => 'AED',

            // WHEN. Only dates the paperwork actually carries. A bill keyed in September for work
            // done in May must not date the part to September, so an unknown date stays unknown
            // rather than borrowing the day this row was written.
            'purchased_at' => $line->installed_on ?: $line->invoice?->recorded_at,
            'installed_at' => $line->installed_on,
            'installed_odometer' => $line->installed_odometer,

            // The part was supplied and fitted — that is what a part line on a repair bill means.
            'result' => PartPurchase::RESULT_SUCCESS,
        ]);

        // Attribution of the ACT of recording, which is a different fact from who fitted the part.
        $purchase->purchased_by      = $purchase->purchased_by ?: $actor->id;
        $purchase->purchased_by_name = $purchase->purchased_by_name ?: ($actor->name ?: $actor->email);

        $purchase->save();

        return $purchase;
    }

    /** The standing purchase for this line, if the ledger already knows it. */
    private function purchaseFor(MaintenanceLineItem $line): ?PartPurchase
    {
        return PartPurchase::where('maintenance_line_item_id', $line->id)->first();
    }

    /**
     * Group key for reconciling a bill's parts across an edit: the canonical part when one is known,
     * else the normalised wording — the same two-rung identity StoreService::findItem uses, so a
     * part recognised on one board is the same part on the other.
     */
    private function identityKey(?int $catalogId, ?string $name): string
    {
        return $catalogId
            ? 'cat:' . $catalogId
            : 'name:' . ($this->identity->nameKey($name) ?: 'unnamed');
    }

    /**
     * Say on the purchase itself why no component was built, in a sentence a person reading the
     * purchase can act on.
     *
     * Deliberately NOT a new status column. The deferral is DERIVABLE — a garage purchase with no
     * component is deferred, and `parts:backfill-garage-lines --dry-run` recomputes the reason from
     * live data whenever it is asked. A stored status would be a second copy of that answer, and the
     * copy goes stale the moment somebody fits the part or names the position. The note is here so
     * the reason is visible where the row is read; the report is where it is counted.
     */
    private function noteDeferral(PartPurchase $purchase, string $reason): void
    {
        $sentence = match ($reason) {
            'deferred_missing_position' => 'No component recorded: this part type is fitted in a named position (' .
                implode(' / ', $purchase->catalogPart?->positionsFor() ?: []) . ') and the bill does not say which.',
            'deferred_slot_occupied' => 'No component recorded: this car already has an active ' .
                ($purchase->catalogPart?->name ?: 'part of this type') .
                ', and the bill does not say what happened to the old one.',
            'consumable_never_a_component' => 'No component recorded: consumables are work, not assets.',
            'line_removed_from_bill' => 'This part is no longer on the garage bill it was billed on, but a component was already built from it. Needs a human decision.',
            default => 'No component recorded (' . $reason . ').',
        };

        if ($purchase->notes === $sentence) {
            return; // idempotent: re-running must not append the same sentence twice
        }

        $purchase->notes = $sentence;
        $purchase->save();
    }

    /**
     * Every part line that has never been put through this door — the backfill's work list.
     *
     * @return Collection<int, MaintenanceLineItem>
     */
    public function pending(): Collection
    {
        return MaintenanceLineItem::where('kind', MaintenanceLineItem::KIND_PART)
            ->with(['maintenance.vendor', 'invoice'])
            ->orderBy('id')
            ->get();
    }
}
