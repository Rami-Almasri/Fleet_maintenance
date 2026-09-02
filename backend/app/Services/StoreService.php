<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\StoreItem;
use App\Models\StoreMovement;
use App\Models\StorePriceCorrection;
use App\Models\StoreStockRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The STOREHOUSE — the fleet's own shelf of parts, and the single writer of every stock level.
 *
 * WHAT THIS ADDS. Until now a part could only reach a car by being bought for that car, on that day,
 * against that ticket. Real workshops do not work that way: filters, bulbs, pads and belts are
 * bought in tens when they are cheap and fitted months later to whichever car needs one. This
 * service gives that stock a home, and closes the loop at both ends —
 *
 *   IN   {@see receiveStockRequest} / {@see receiveDirect} — the part arrives, the shelf goes UP,
 *        and the price it arrived at re-weights the shelf's average cost.
 *   OUT  {@see issueToRequest} — a job takes one, the shelf goes DOWN, and the unit becomes a
 *        PartPurchase priced at the shelf average, so it travels the EXISTING install → line item →
 *        ticket → invoice chain. Nothing about how a fitted part is costed changes.
 *
 * WHY AN ISSUE IS A PURCHASE. It would have been less code to decrement the shelf and write the
 * component directly. That would also have created a second way for a part to land on a car — one
 * that skips the duplicate engine, the install guards, the fault linkage and the cost bridge. There
 * is ONE write path onto a vehicle ({@see PartWorkflowService}); the storehouse is a SOURCE for that
 * path, never a bypass of it. The purchase it creates carries `purchase_source = store` and is
 * priced at what the fleet actually paid, so a part issued from stock costs the ticket the same
 * money whether it was bought in March or this morning.
 *
 * INVARIANT. store_items.qty_on_hand is a cache of the store_movements ledger. Every method here
 * that changes it does so inside a transaction that has the item row LOCKED and writes the matching
 * movement in the same breath — see {@see move()}, which is the only place either fact is written.
 */
class StoreService
{
    /**
     * The two inbound reasons that re-price a shelf against a price somebody TYPED, and so are the
     * two that have to agree with what the shelf already cost.
     *
     * A return from a vehicle is priced from the shelf's own average, so it can never disagree with
     * itself; an adjustment carries no price at all. Gating either would block honest work to check
     * a number nobody entered.
     */
    private const PRICE_GATED_REASONS = [StoreMovement::REASON_RECEIPT, StoreMovement::REASON_OPENING];

    /**
     * What counts as "this is a different price", needing BOTH to trip.
     *
     * The percentage alone would nag on every cheap part — a 4 AED bulb at 6 AED is a 50% move and
     * nothing anybody needs to justify. The absolute floor alone would nag on every expensive one.
     * Together they ask about the AC compressor and stay quiet about the bulb.
     */
    private const PRICE_VARIANCE_FRACTION = 0.20;   // 20% off the shelf's average…
    private const PRICE_VARIANCE_FLOOR    = 25.00;  // …and at least this many currency units.

    public function __construct(
        private PartIdentityService $identity,
        private PartWorkflowService $workflow,
        private PartInvoiceService $invoices,
        private VehicleLogService $log,
    ) {}

    /**
     * THE PAPER RULE. Booking stock in is a money event, so it needs the supplier's bill — either an
     * existing invoice to add these units to (one trip, several parts, one document) or the details
     * to raise one now.
     *
     * The exception is the OPENING COUNT: stock already on the shelf the day the storehouse started.
     * Its document, if one ever existed, is not ours to invent, and demanding one would force
     * somebody to make a number up — which is worse than an honest "we counted it".
     *
     * @return int|null the invoice id this receipt belongs on (null only for an opening count)
     */
    private function resolveInvoice(array $data, string $reason, User $actor): ?int
    {
        if ($reason !== StoreMovement::REASON_RECEIPT) {
            return null;
        }

        if (! empty($data['part_invoice_id'])) {
            $invoice = PartInvoice::find($data['part_invoice_id']);
            if (! $invoice) {
                abort(422, 'That supplier invoice no longer exists.');
            }

            return $invoice->id;
        }

        $header = (array) ($data['invoice'] ?? []);
        if (empty($header['invoice_no']) && empty($header['vendor_id']) && empty($header['supplier_name'])) {
            abort(422, 'A stock receipt needs the supplier’s invoice. Add the bill — its number and who issued it — or pick the invoice these parts are already on.');
        }

        // Raised through PartInvoiceService, not written here: it owns the variance gate, the photo,
        // and the audit stamps, and a second writer would drift from all three.
        return $this->invoices->create([
            'vendor_id'            => $header['vendor_id'] ?? null,
            'supplier_name'        => $header['supplier_name'] ?? null,
            'invoice_no'           => $header['invoice_no'] ?? null,
            'invoice_date'         => $header['invoice_date'] ?? null,
            'currency'             => $header['currency'] ?? ($data['currency'] ?? 'AED'),
            'tax_amount'           => $header['tax_amount'] ?? 0,
            'discount_amount'      => $header['discount_amount'] ?? 0,
            'notes'                => $header['notes'] ?? null,
        ], $actor, $data['invoice_photo'] ?? null)->id;
    }

    /**
     * Re-derive an invoice's totals after a receipt landed on it, and hold it to the printed figure.
     *
     * Done AFTER the movement is written rather than inside the create call, because the shelf line is
     * what the invoice is being asked to account for — recalculating before it exists would price the
     * document at zero and then demand an explanation for the whole amount.
     */
    private function settleInvoice(?int $invoiceId, array $data, User $actor): void
    {
        if (! $invoiceId || ! ($invoice = PartInvoice::find($invoiceId))) {
            return;
        }

        $invoice->load(['purchases', 'storeReceipts']);
        $invoice->recalcTotals();

        $stated = $data['invoice']['stated_total'] ?? null;
        if ($stated === null || $stated === '') {
            return;
        }

        // The printed total is the paper's own claim. Routed back through the service so the same
        // variance gate that guards a hand-keyed invoice guards one raised by a stock receipt.
        $this->invoices->update($invoice, [
            'stated_total'         => $stated,
            'variance_explanation' => $data['invoice']['variance_explanation'] ?? null,
        ], $actor);
    }

    // ───────────────────────────── lookup ─────────────────────────────

    /**
     * The shelf holding this part, or null — a pure READ. Never creates: asking "do we have one?"
     * must not leave a row behind for a part the storehouse has never carried, or the shelf list
     * fills with zero-quantity ghosts of every part anyone ever searched for.
     */
    public function findItem(?int $catalogId, ?string $partName = null, ?string $partNumber = null): ?StoreItem
    {
        $q = StoreItem::query();

        if ($catalogId) {
            return $q->where('stock_key', StoreItem::keyFor($catalogId, null))->first();
        }

        if ($key = $this->identity->nameKey($partName)) {
            $hit = $q->where('stock_key', StoreItem::keyFor(null, $key))->first();
            if ($hit) {
                return $hit;
            }
        }

        // Last resort: an exact part number. Weaker than either identity rung above (two different
        // parts can share a blank or a mistyped number), so it is only consulted when neither the
        // catalog nor the wording found anything.
        $partNumber = trim((string) $partNumber);

        return $partNumber === '' ? null : StoreItem::query()->where('part_number', $partNumber)->first();
    }

    /**
     * "Do we already have this part on the shelf?" — the answer the part-request form asks BEFORE
     * anyone buys anything.
     *
     * Always returns a shaped answer, never null, because "we have none" and "we have never heard of
     * it" are both real answers the form must be able to say out loud.
     */
    public function availability(?int $catalogId, ?string $partName = null, ?string $partNumber = null): array
    {
        $item = $this->findItem($catalogId, $partName, $partNumber);

        if (! $item) {
            return [
                'in_stock'    => false,
                'qty_on_hand' => 0.0,
                'item'        => null,
                'unit_cost'   => null,
                'currency'    => 'AED',
            ];
        }

        return [
            'in_stock'    => (float) $item->qty_on_hand > 0,
            'qty_on_hand' => (float) $item->qty_on_hand,
            'item'        => [
                'id'          => $item->id,
                'part_name'   => $item->part_name,
                'part_number' => $item->part_number,
                'location'    => $item->location,
                'min_qty'     => $item->min_qty === null ? null : (float) $item->min_qty,
                'is_low'      => $item->isLow(),
            ],
            // What one unit will cost the ticket if it is taken from here. The weighted average of
            // what we paid — the honest figure, and the one issueToRequest() actually charges.
            'unit_cost'   => $item->avg_unit_cost === null ? null : (float) $item->avg_unit_cost,
            'currency'    => $item->currency ?: 'AED',
        ];
    }

    // ───────────────────────────── stock requests (the no-vehicle door) ─────────────────────────────

    /** Ask for a part to be put on the shelf. No vehicle, no ticket, no fault — just the shelf. */
    public function createStockRequest(array $data, User $actor): StoreStockRequest
    {
        $catalogId = $data['component_catalog_id'] ?? null;
        $nameKey   = $this->identity->nameKey($data['part_name']);

        $req = StoreStockRequest::create([
            'status'               => StoreStockRequest::STATUS_REQUESTED,
            // Link it to the shelf NOW when we already carry the part, so "10 more filters" reads
            // against the 3 we have rather than looking like a part we have never stocked.
            'store_item_id'        => $this->findItem($catalogId, $data['part_name'], $data['part_number'] ?? null)?->id,
            'component_catalog_id' => $catalogId,
            'part_name'            => $data['part_name'],
            'part_name_key'        => $nameKey,
            'part_number'          => $data['part_number'] ?? null,
            'category_key'         => $data['category_key'] ?? null,
            'quantity'             => $data['quantity'] ?? 1,
            'estimated_price'      => $data['estimated_price'] ?? null,
            'currency'             => $data['currency'] ?? 'AED',
            'reason'               => $data['reason'],
            'notes'                => $data['notes'] ?? null,
            'supplier_vendor_id'   => $data['supplier_vendor_id'] ?? null,
            'supplier_name'        => $data['supplier_name'] ?? null,
            'requested_by'         => $actor->id,
            'requested_by_name'    => $actor->name ?: $actor->email,
            'requested_at'         => Carbon::now(),
        ]);

        return $req->fresh();
    }

    public function approveStockRequest(StoreStockRequest $req, User $actor, ?string $note = null): StoreStockRequest
    {
        $this->guardStatus($req, [StoreStockRequest::STATUS_REQUESTED], 'approve');

        $req->forceFill([
            'status'           => StoreStockRequest::STATUS_APPROVED,
            'approved_by'      => $actor->id,
            'approved_by_name' => $actor->name ?: $actor->email,
            'approved_at'      => Carbon::now(),
            'notes'            => $note ? trim(($req->notes ? $req->notes . "\n" : '') . $note) : $req->notes,
        ])->save();

        return $req->fresh();
    }

    public function rejectStockRequest(StoreStockRequest $req, User $actor, string $reason): StoreStockRequest
    {
        $this->guardStatus($req, [StoreStockRequest::STATUS_REQUESTED, StoreStockRequest::STATUS_APPROVED, StoreStockRequest::STATUS_ORDERED], 'reject');

        $req->forceFill([
            'status'           => StoreStockRequest::STATUS_REJECTED,
            'rejected_by'      => $actor->id,
            'rejected_by_name' => $actor->name ?: $actor->email,
            'rejected_at'      => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        return $req->fresh();
    }

    /** Mark it as placed with a supplier. Purely informational — no stock moves until it arrives. */
    public function markOrdered(StoreStockRequest $req, array $data, User $actor): StoreStockRequest
    {
        $this->guardStatus($req, [StoreStockRequest::STATUS_APPROVED], 'mark as ordered');

        $req->forceFill([
            'status'             => StoreStockRequest::STATUS_ORDERED,
            'supplier_vendor_id' => $data['supplier_vendor_id'] ?? $req->supplier_vendor_id,
            'supplier_name'      => $data['supplier_name'] ?? $req->supplier_name,
            'unit_cost'          => $data['unit_cost'] ?? $req->unit_cost,
        ])->save();

        return $req->fresh();
    }

    /**
     * THE PART ARRIVED — the moment the shelf goes up.
     *
     * Creates the shelf if this is a part the storehouse has never carried, books the quantity in at
     * the price actually paid, and closes the request. `received_quantity` may differ from what was
     * asked for: a supplier who sends eight of ten has delivered eight, and the shelf must say eight.
     */
    public function receiveStockRequest(StoreStockRequest $req, array $data, User $actor): StoreStockRequest
    {
        $this->guardStatus($req, StoreStockRequest::RECEIVABLE, 'receive');

        return DB::transaction(function () use ($req, $data, $actor) {
            $qty  = (float) ($data['quantity'] ?? $req->quantity);
            $cost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null
                ? (float) $data['unit_cost']
                : ($req->unit_cost !== null ? (float) $req->unit_cost : ($req->estimated_price !== null ? (float) $req->estimated_price : null));

            $item = $this->itemFor(
                $req->component_catalog_id,
                $req->part_name,
                $req->part_number,
                $req->category_key,
                $data['location'] ?? null,
                $data['currency'] ?? $req->currency,
            );

            // The bill comes FIRST. A receipt that cannot name its paper never reaches the shelf —
            // resolveInvoice aborts, and the whole transaction (shelf included) goes with it.
            $invoiceId = $this->resolveInvoice($data, StoreMovement::REASON_RECEIPT, $actor);

            $this->move($item, StoreMovement::IN, StoreMovement::REASON_RECEIPT, $qty, $actor, [
                'unit_cost'              => $cost,
                'currency'               => $data['currency'] ?? $req->currency ?: 'AED',
                'supplier_vendor_id'     => $data['supplier_vendor_id'] ?? $req->supplier_vendor_id,
                'store_stock_request_id' => $req->id,
                'part_invoice_id'        => $invoiceId,
                'note'                   => $data['note'] ?? "Received against stock request #{$req->id}",
                'price_variance_note'    => $data['price_variance_note'] ?? null,
            ]);

            $this->settleInvoice($invoiceId, $data, $actor);

            $req->forceFill([
                'status'             => StoreStockRequest::STATUS_RECEIVED,
                'store_item_id'      => $item->id,
                'received_quantity'  => $qty,
                'unit_cost'          => $cost,
                'supplier_vendor_id' => $data['supplier_vendor_id'] ?? $req->supplier_vendor_id,
                'supplier_name'      => $data['supplier_name'] ?? $req->supplier_name,
                'received_by'        => $actor->id,
                'received_by_name'   => $actor->name ?: $actor->email,
                'received_at'        => Carbon::now(),
            ])->save();

            return $req->fresh(['item']);
        });
    }

    /**
     * Book stock in with no request behind it — the opening count, a walk-in buy, or parts a job
     * never used coming back to the shelf.
     *
     * The reason is the caller's to state and is not guessed: 'opening' and 'receipt' are money
     * events that re-price the shelf, 'return_from_vehicle' and 'adjustment' are not necessarily.
     */
    public function receiveDirect(array $data, User $actor): StoreItem
    {
        return DB::transaction(function () use ($data, $actor) {
            $item = $this->itemFor(
                $data['component_catalog_id'] ?? null,
                $data['part_name'],
                $data['part_number'] ?? null,
                $data['category_key'] ?? null,
                $data['location'] ?? null,
                $data['currency'] ?? 'AED',
            );

            if (array_key_exists('min_qty', $data) && $data['min_qty'] !== null) {
                $item->min_qty = $data['min_qty'];
                $item->save();
            }

            $reason    = $data['reason'] ?? StoreMovement::REASON_RECEIPT;
            $invoiceId = $this->resolveInvoice($data, $reason, $actor);

            $this->move($item, StoreMovement::IN, $reason, (float) $data['quantity'], $actor, [
                'unit_cost'          => $data['unit_cost'] ?? null,
                'currency'           => $data['currency'] ?? $item->currency,
                'supplier_vendor_id' => $data['supplier_vendor_id'] ?? null,
                'part_invoice_id'    => $invoiceId,
                'note'               => $data['note'] ?? null,
                'price_variance_note' => $data['price_variance_note'] ?? null,
            ]);

            $this->settleInvoice($invoiceId, $data, $actor);

            return $item->fresh();
        });
    }

    /**
     * A correction or a write-off — the two ways stock changes without anything being bought or
     * fitted. `direction` says which way; the note is required by the API because an unexplained
     * change to a count is indistinguishable from a mistake.
     */
    public function adjust(StoreItem $item, array $data, User $actor): StoreItem
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $direction = $data['direction'];

            $this->move($item, $direction, $data['reason'] ?? StoreMovement::REASON_ADJUSTMENT, (float) $data['quantity'], $actor, [
                // A count correction moves no money, so it carries no unit cost and therefore does
                // NOT re-weight the shelf average: five filters found in a corner are five filters
                // we already paid for, at whatever we paid then.
                'unit_cost' => null,
                'note'      => $data['note'],
            ]);

            return $item->fresh();
        });
    }

    // ───────────────────────────── issue to a job (the OUT door) ─────────────────────────────

    /**
     * Hand a part from the shelf to a job: the shelf goes down and a PartPurchase is created from
     * stock, priced at what the fleet actually paid for it.
     *
     * The purchase is stamped DELIVERED immediately — the part is physically here, on our own shelf,
     * so the car is not waiting for anything. That matters beyond neatness: `PartRequest::
     * isOutstanding()` reads delivery to decide whether a repair is blocked on parts, and a part
     * already in the building must never park a ticket in "waiting for parts".
     *
     * APPROVAL. A request still sitting at requested/under_review is approved here, by this actor,
     * with the reason recorded. The spend was authorised when the part was bought INTO the store;
     * asking a second time to release something the fleet already owns would be approving the same
     * money twice. The audit trail says who released it, which is the fact worth keeping.
     */
    public function issueToRequest(PartRequest $req, StoreItem $item, array $data, User $actor): array
    {
        if (in_array($req->status, PartRequest::SETTLED, true)) {
            abort(409, "Cannot issue stock to a part request that is '{$req->status}'.");
        }

        return DB::transaction(function () use ($req, $item, $data, $actor) {
            $qty = (float) ($data['quantity'] ?? $req->quantity ?: 1);

            // Lock and check BEFORE anything else in the transaction: two coordinators issuing the
            // last unit at the same time must not both succeed.
            $locked = StoreItem::query()->lockForUpdate()->findOrFail($item->id);
            if ((float) $locked->qty_on_hand < $qty) {
                abort(409, "The storehouse holds only {$locked->qty_on_hand} of {$locked->part_name} — cannot issue {$qty}.");
            }

            // The price the ticket is charged: what a unit off this shelf cost us. A shelf with no
            // priced receipt behind it (an opening count nobody costed) issues at 0 rather than
            // inventing a number — and says so in the note, so the gap is visible instead of silent.
            $unitCost = $locked->avg_unit_cost !== null ? (float) $locked->avg_unit_cost : 0.0;

            if (in_array($req->status, [PartRequest::STATUS_REQUESTED, PartRequest::STATUS_UNDER_REVIEW], true)) {
                $this->workflow->approve($req, $actor, 'Issued from the storehouse — stock the fleet already owns.');
                $req->refresh();
            }

            $result = $this->workflow->purchase($req, [
                'purchase_source' => PartPurchase::SOURCE_STORE,
                'source_name'     => $data['source_name'] ?? 'Storehouse',
                'purchase_price'  => $unitCost,
                'currency'        => $locked->currency ?: 'AED',
                'quantity'        => $qty,
                'notes'           => trim((string) ($data['notes'] ?? ''))
                    ?: ($unitCost > 0
                        ? "Issued from the storehouse at its average cost of {$unitCost} " . ($locked->currency ?: 'AED') . '.'
                        : 'Issued from the storehouse. No priced receipt stands behind this shelf, so no cost is claimed for it.'),
                // The one caller allowed to book a store-sourced purchase. See PartWorkflowService.
                '_from_store'     => true,
            ], $actor);

            /** @var PartPurchase $purchase */
            $purchase = $result['purchase'];

            $this->move($locked, StoreMovement::OUT, StoreMovement::REASON_ISSUE, $qty, $actor, [
                'unit_cost'        => $unitCost > 0 ? $unitCost : null,
                'currency'         => $locked->currency ?: 'AED',
                'vehicle_id'       => $req->vehicle_id,
                'maintenance_id'   => $req->maintenance_id,
                'part_request_id'  => $req->id,
                'part_purchase_id' => $purchase->id,
                'note'             => $data['note'] ?? null,
            ]);

            // The part is on our own shelf, in our own building. Nothing is in transit, so the
            // ticket is not waiting on it.
            $this->workflow->markDelivered($purchase, $actor);

            $this->logVehicle($req, $actor, $locked, $qty, $purchase);

            return [
                'purchase' => $purchase->fresh(),
                'item'     => $locked->fresh(),
                'verdict'  => $result['verdict'],
            ];
        });
    }

    /**
     * A part issued from stock that was never fitted, coming back to the shelf.
     *
     * Guarded to an UNINSTALLED store purchase on purpose. Once a part is installed it has been
     * billed to a ticket and is bolted to a car; putting it back would credit nothing and would
     * leave the vehicle's configuration claiming a part that is sitting on a shelf.
     */
    public function returnToStore(PartPurchase $purchase, array $data, User $actor): StoreItem
    {
        if ($purchase->purchase_source !== PartPurchase::SOURCE_STORE) {
            abort(422, 'Only a part issued from the storehouse can be returned to it.');
        }
        if ($purchase->isInstalled()) {
            abort(409, 'This part is already installed on the vehicle — it cannot be returned to the storehouse.');
        }

        $issue = StoreMovement::query()
            ->where('part_purchase_id', $purchase->id)
            ->where('reason', StoreMovement::REASON_ISSUE)
            ->latest('id')
            ->first();

        if (! $issue) {
            abort(422, 'No storehouse issue was found behind this purchase.');
        }

        return DB::transaction(function () use ($purchase, $issue, $data, $actor) {
            $item = StoreItem::query()->lockForUpdate()->findOrFail($issue->store_item_id);
            $qty  = (float) ($data['quantity'] ?? $issue->quantity);

            if ($qty > (float) $issue->quantity) {
                abort(422, "Only {$issue->quantity} were issued — cannot return {$qty}.");
            }

            $this->move($item, StoreMovement::IN, StoreMovement::REASON_RETURN_FROM_VEHICLE, $qty, $actor, [
                // Returned at the price it left at, so a part that goes out and comes back leaves
                // the shelf's average exactly where it was.
                'unit_cost'        => $issue->unit_cost === null ? null : (float) $issue->unit_cost,
                'currency'         => $issue->currency,
                'vehicle_id'       => $purchase->vehicle_id,
                'maintenance_id'   => $purchase->maintenance_id,
                'part_request_id'  => $purchase->part_request_id,
                'part_purchase_id' => $purchase->id,
                'note'             => $data['note'] ?? 'Returned unused to the storehouse.',
            ]);

            $purchase->forceFill([
                'result'       => PartPurchase::RESULT_FAILED,
                'delivered_at' => null,
                'notes'        => trim(($purchase->notes ? $purchase->notes . "\n" : '') . 'Returned to the storehouse unused.'),
            ])->save();

            return $item->fresh();
        });
    }

    // ───────────────────────────── the single write ─────────────────────────────

    /**
     * THE ONLY place a shelf level changes. Writes the movement and the new running total together,
     * with the item row locked, so the ledger and the cache can never disagree.
     *
     * Callers must already be inside a transaction — every public method above opens one — so a
     * failure downstream (a purchase that will not save, a guard that trips) takes the stock change
     * with it rather than leaving a phantom decrement behind.
     */
    private function move(StoreItem $item, string $direction, string $reason, float $quantity, User $actor, array $opts = []): StoreMovement
    {
        if ($quantity <= 0) {
            abort(422, 'A stock movement must move at least some quantity.');
        }

        $allowed = $direction === StoreMovement::IN ? StoreMovement::IN_REASONS : StoreMovement::OUT_REASONS;
        if (! in_array($reason, $allowed, true)) {
            abort(422, "'{$reason}' is not a valid reason for a stock movement {$direction}.");
        }

        $locked = StoreItem::query()->lockForUpdate()->findOrFail($item->id);

        $before = (float) $locked->qty_on_hand;
        $after  = $direction === StoreMovement::IN ? $before + $quantity : $before - $quantity;

        if ($after < 0) {
            abort(409, "The storehouse holds only {$before} of {$locked->part_name}.");
        }

        $unitCost = array_key_exists('unit_cost', $opts) && $opts['unit_cost'] !== null ? (float) $opts['unit_cost'] : null;

        // THE PRICE GATE. A priced receipt onto a shelf that already has a cost must agree with what
        // that shelf cost before, or say why it does not.
        //
        // Without this, the weighted average below absorbs anything: one AC compressor at 322 and a
        // second at 2,000 quietly become a shelf that says 1,161 each — a number true of neither
        // receipt, and the number issueToRequest() then charges every future car that takes one off
        // this shelf. A fat-fingered price is therefore not a cosmetic error on one row; it silently
        // re-prices the part for good. The same "explain it or fix it" bar the supplier invoice
        // already applies to its own printed total now applies to the shelf's memory of the part.
        $variance = null;
        if ($direction === StoreMovement::IN && $unitCost !== null && in_array($reason, self::PRICE_GATED_REASONS, true)) {
            $variance = $this->priceVariance($locked, $unitCost);

            if ($variance !== null && trim((string) ($opts['price_variance_note'] ?? '')) === '') {
                $was = number_format((float) $locked->avg_unit_cost, 2);
                $now = number_format($unitCost, 2);
                abort(422, "This shelf has been costing {$was} a unit and this receipt prices it at {$now}. "
                    . 'Correct the price, or say why it changed.');
            }
        }

        // Weighted average, recomputed only when stock ARRIVES with a price on it. An issue is
        // priced FROM the average and must never feed back into it, or the shelf's cost would drift
        // every time a part left it.
        if ($direction === StoreMovement::IN && $unitCost !== null) {
            $currentValue = $before * (float) ($locked->avg_unit_cost ?? $unitCost);
            $locked->avg_unit_cost  = $after > 0 ? round(($currentValue + $quantity * $unitCost) / $after, 2) : $unitCost;
            $locked->last_unit_cost = $unitCost;
        }

        $locked->qty_on_hand = $after;
        $locked->save();

        return StoreMovement::create([
            'store_item_id'          => $locked->id,
            'direction'              => $direction,
            'reason'                 => $reason,
            'quantity'               => $quantity,
            'qty_after'              => $after,
            'unit_cost'              => $unitCost,
            'currency'               => $opts['currency'] ?? $locked->currency ?: 'AED',
            'vehicle_id'             => $opts['vehicle_id'] ?? null,
            'maintenance_id'         => $opts['maintenance_id'] ?? null,
            'part_request_id'        => $opts['part_request_id'] ?? null,
            'part_purchase_id'       => $opts['part_purchase_id'] ?? null,
            'store_stock_request_id' => $opts['store_stock_request_id'] ?? null,
            'supplier_vendor_id'     => $opts['supplier_vendor_id'] ?? null,
            'part_invoice_id'        => $opts['part_invoice_id'] ?? null,
            'note'                   => $opts['note'] ?? null,
            // Stored only when a gap actually had to be explained, so NULL keeps meaning "nothing
            // here needed explaining" rather than "nobody said anything".
            'price_variance_note'    => $variance === null ? null : trim((string) $opts['price_variance_note']),
            'actor_id'               => $actor->id,
            'actor_name'             => $actor->name ?: $actor->email,
            'occurred_at'            => Carbon::now(),
        ]);
    }

    /**
     * Put right what a shelf costs, when a wrong price has already been blended into its average.
     *
     * The ONLY path that assigns `avg_unit_cost` outside a priced receipt, and deliberately narrow:
     *
     *   • It moves no stock. The quantity on the shelf is a separate fact with its own ledger, and a
     *     price being wrong is no evidence that the count is.
     *   • It never rewrites history. The receipts that produced the bad average keep their own
     *     `unit_cost` exactly as keyed — those rows are what the supplier actually charged, and a
     *     correction to our own blend is not a licence to restate the paper. What changes is the
     *     shelf's forward-looking cost, and the correction is stored beside it with both figures.
     *   • It cannot be done by the same permission that caused it. The route requires
     *     parts.investigate — the adjudicating bar — not parts.purchase, which is what whoever keyed
     *     the wrong receipt already holds. That is the second pair of eyes, expressed as authority
     *     rather than as a two-step dance: a wrong price is live and over-charging every job that
     *     touches this shelf, so making the fix wait for an approval queue keeps the wrong number in
     *     service longer, which is the worse failure.
     *
     * `last_unit_cost` is left alone on purpose: it records what the most recent receipt actually
     * cost, which stays true no matter what we decide the shelf is worth going forward.
     */
    public function correctPrice(StoreItem $item, array $data, User $actor): StoreItem
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $locked = StoreItem::query()->lockForUpdate()->findOrFail($item->id);

            $new = round((float) $data['avg_unit_cost'], 2);
            $old = $locked->avg_unit_cost === null ? null : (float) $locked->avg_unit_cost;

            if ($new < 0) {
                abort(422, 'A shelf cannot cost less than nothing.');
            }

            if ($old !== null && abs($new - $old) < 0.01) {
                abort(422, 'That is the price the shelf already carries — nothing to correct.');
            }

            StorePriceCorrection::create([
                'store_item_id'     => $locked->id,
                'old_avg_unit_cost' => $old,
                'new_avg_unit_cost' => $new,
                'currency'          => $locked->currency ?: 'AED',
                'reason'            => trim((string) $data['reason']),
                'actor_id'          => $actor->id,
                'actor_name'        => $actor->name ?: $actor->email,
                'occurred_at'       => Carbon::now(),
            ]);

            $locked->avg_unit_cost = $new;
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * How far a new price sits from what this shelf has been costing — or NULL when there is
     * nothing to disagree with, or the gap is too small to be worth a person's time.
     *
     * A shelf with no average yet (the very first receipt, or an opening count nobody costed) has no
     * opinion about price, so the first priced receipt sets it and is never questioned. Comparing
     * against the AVERAGE rather than the last receipt is deliberate: the average is the number the
     * shelf will actually charge a car, so it is the number a new price has to be reconciled with.
     */
    private function priceVariance(StoreItem $item, float $unitCost): ?array
    {
        $was = $item->avg_unit_cost === null ? null : (float) $item->avg_unit_cost;

        if ($was === null || $was <= 0.0) {
            return null;
        }

        $delta = round($unitCost - $was, 2);

        if (abs($delta) < self::PRICE_VARIANCE_FLOOR || abs($delta) / $was < self::PRICE_VARIANCE_FRACTION) {
            return null;
        }

        return ['was' => $was, 'now' => $unitCost, 'delta' => $delta];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * The shelf for a part, created if the storehouse has never carried it. Only ever called from a
     * path that is about to put stock ON it — see findItem() for why a read must not create.
     */
    private function itemFor(?int $catalogId, string $partName, ?string $partNumber, ?string $categoryKey, ?string $location, ?string $currency): StoreItem
    {
        $nameKey = $this->identity->nameKey($partName);
        $key     = StoreItem::keyFor($catalogId, $nameKey);

        $item = StoreItem::query()->where('stock_key', $key)->lockForUpdate()->first();

        if (! $item) {
            $item = StoreItem::create([
                'component_catalog_id' => $catalogId,
                'stock_key'            => $key,
                'part_name'            => $partName,
                'part_name_key'        => $nameKey,
                'part_number'          => $partNumber,
                'category_key'         => $categoryKey,
                'currency'             => $currency ?: 'AED',
                'location'             => $location,
                'is_active'            => true,
            ]);
        } elseif ($location && $item->location !== $location) {
            // A shelf can be moved; the latest place it was booked into is where it is.
            $item->forceFill(['location' => $location])->save();
        }

        return $item;
    }

    /** @param array<int,string> $allowed */
    private function guardStatus(StoreStockRequest $req, array $allowed, string $action): void
    {
        if (! in_array($req->status, $allowed, true)) {
            abort(409, "Cannot {$action} a stock request that is '{$req->status}'.");
        }
    }

    /**
     * The car's own trail gets the plain sentence: a part came out of our storehouse for this job.
     * Best-effort — the timeline is a witness, never a gate on the stock movement.
     */
    private function logVehicle(PartRequest $req, User $actor, StoreItem $item, float $qty, PartPurchase $purchase): void
    {
        try {
            $qtyText     = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
            $description = "Part taken from the storehouse: {$item->part_name} ×{$qtyText} (left in stock: {$item->qty_on_hand})";

            $opts = [
                'source_tag'  => 'store',
                'description' => $description,
                'meta'        => [
                    'store_item_id'    => $item->id,
                    'part_request_id'  => $req->id,
                    'part_purchase_id' => $purchase->id,
                    'quantity'         => $qty,
                    'qty_remaining'    => (float) $item->qty_on_hand,
                    'unit_cost'        => (float) $purchase->purchase_price,
                    'currency'         => $purchase->currency,
                ],
            ];

            if ($req->maintenance_id && ($ticket = Maintenance::find($req->maintenance_id))) {
                $this->log->record($ticket, VehicleLogEvent::EVENT_PART_DELIVERED, $actor, $opts);
            } elseif ($vehicle = Vehicle::find($req->vehicle_id)) {
                $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_PART_DELIVERED, $actor, $opts);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
