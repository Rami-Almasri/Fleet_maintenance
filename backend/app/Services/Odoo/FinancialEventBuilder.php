<?php

namespace App\Services\Odoo;

use App\Contracts\FinancialEventSource;
use App\Models\FinancialEvent;
use App\Models\FinancialEventLine;
use App\Models\User;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns an operational record into (or back into) its financial event.
 *
 * This is the seam between the two halves of the system. Operational code calls {@see syncFor()} when
 * something with money in it changes — an invoice is saved, a tow is dispatched, a registration is
 * renewed — and does not care what happens next. It never constructs a FinancialEvent by hand, and it
 * never asks a user for anything: everything comes from {@see FinancialEventSource}, which the source
 * model implements by reading its own state (§31).
 *
 * ── IT IS AN UPSERT, NOT A CREATE ──────────────────────────────────────────────────────────────────
 *
 * One source raises one obligation, enforced by unique(source_type, source_id). Calling this ten times
 * as somebody edits an invoice keeps ONE event in step with the invoice; it never accumulates ten
 * events that would each post the same cost. That is why the operational hooks can call it freely.
 *
 * ── IT REFUSES TO TOUCH A SENT EVENT ───────────────────────────────────────────────────────────────
 *
 * Once an event is SENDING or SYNCED its figures are history (see FinancialEvent::isFrozen()). This
 * class checks that FIRST and returns the event untouched. Editing an invoice after its bill has been
 * posted to Odoo therefore changes the ticket's cost in FleetView and leaves the posted document alone
 * — which is correct, and is the only safe answer: a document in an accounting system is corrected by a
 * credit note raised there, never by an integration quietly rewriting what it already sent.
 *
 * ── STATUS IS DERIVED, NEVER GUESSED ───────────────────────────────────────────────────────────────
 *
 * After syncing the figures the builder revalidates and lands the event on READY or BLOCKED. There is
 * no path where a status is assigned without the validator having just agreed with it.
 */
class FinancialEventBuilder
{
    public function __construct(
        private FinancialValidator $validator,
    ) {
    }

    /**
     * Create or refresh the financial event for one operational record.
     *
     * Returns null when the record generates no obligation AND has never had an event — there is
     * nothing to say, so nothing is written. When an event already exists and the record has since
     * stopped generating an obligation (a bill corrected to zero), the event is moved to NOT_REQUIRED
     * rather than deleted: an obligation that went away is a fact worth keeping.
     */
    public function syncFor(Model&FinancialEventSource $source, ?User $actor = null): ?FinancialEvent
    {
        $event = FinancialEvent::forSource($source->getMorphClass(), (int) $source->getKey())->first();

        // Anything already committed to Odoo's keeping is left exactly as it was.
        if ($event && $event->isFrozen()) {
            return $event;
        }

        // A cancelled event is a decision somebody took. Re-saving the invoice must not quietly
        // resurrect it — reopening is an explicit action on the event itself.
        if ($event && $event->status === Status::CANCELLED) {
            return $event;
        }

        $expenseType = $source->financialExpenseType();

        if ($expenseType === null) {
            return $event ? $this->markNotRequired($event) : null;
        }

        return DB::transaction(function () use ($source, $event, $expenseType, $actor) {
            $lines = $this->lineSpecs($source);

            // The amount POSTED is the sum of the postable lines, not the source's headline total.
            // For a garage bill those differ by VAT and discount, which Odoo derives from its own tax
            // records — see MaintenanceInvoice::financialLines(). Where a source offers no lines at all
            // its headline figure IS the single service charge, so the two agree by construction.
            $amount = $lines === []
                ? round($source->financialAmount(), 2)
                : round(array_sum(array_map(
                    static fn (array $l) => round($l['quantity'] * $l['unit_price'], 2),
                    $lines
                )), 2);

            $event ??= new FinancialEvent([
                'source_type' => $source->getMorphClass(),
                'source_id'   => (int) $source->getKey(),
                'created_by'  => $actor?->id,
            ]);

            $event->fill([
                'expense_type'   => $expenseType,
                'amount'         => $amount,
                'currency'       => $source->financialCurrency(),
                'description'    => $source->financialDescription(),
                'vehicle_id'     => $source->financialVehicleId(),
                'maintenance_id' => $source->financialMaintenanceId(),
                'vendor_id'      => $source->financialVendorId(),
            ]);

            // A previously blocked/failed event coming back through here starts from a clean slate; the
            // validator below decides where it actually lands.
            if (in_array($event->status, [Status::BLOCKED, Status::FAILED, Status::NOT_REQUIRED], true)
                || ! $event->exists) {
                $event->status = Status::DRAFT;
            }

            $event->save();

            $this->replaceLines($event, $lines, $source);

            return $this->revalidate($event);
        });
    }

    /**
     * Re-run validation and land the event on READY or BLOCKED.
     *
     * Safe to call at any time and from anywhere — it is how the financial panel refreshes, and how a
     * newly-created mapping unblocks the events that were waiting for it.
     */
    public function revalidate(FinancialEvent $event): FinancialEvent
    {
        if ($event->isFrozen() || $event->status === Status::CANCELLED) {
            return $event;
        }

        // The document type comes from the expense-type mapping, which Finance can change at any time —
        // so it is re-read on every validation rather than being remembered from when the event was
        // built. It is only FROZEN onto the event at send time.
        $mapping = app(OdooMappingService::class)->expenseTypeMapping((string) $event->expense_type);
        if ($mapping?->odoo_document_type) {
            $event->odoo_document_type = $mapping->odoo_document_type;
        }

        $reasons = $this->validator->validate($event);

        $event->block_reasons = $reasons ?: null;
        $event->validated_at  = now();
        $event->status        = $reasons === [] ? Status::READY : Status::BLOCKED;
        $event->save();

        return $event;
    }

    /** The obligation went away — say so, rather than deleting the record of it. */
    private function markNotRequired(FinancialEvent $event): FinancialEvent
    {
        $event->fill([
            'status'        => Status::NOT_REQUIRED,
            'block_reasons' => null,
            'amount'        => 0,
            'validated_at'  => now(),
        ])->save();

        $event->lines()->delete();

        return $event;
    }

    /**
     * @return list<array{origin_type:?string, origin_id:?int, component_catalog_id:?int, kind:string,
     *                    description:string, quantity:float, uom:?string, unit_price:float}>
     */
    private function lineSpecs(FinancialEventSource $source): array
    {
        return array_values(array_filter(
            $source->financialLines(),
            // A zero-priced line is not a charge, and sending it would put an empty row on a real bill.
            static fn (array $l) => round((float) $l['quantity'] * (float) $l['unit_price'], 2) != 0.0
        ));
    }

    /**
     * Replace the event's lines with the source's current ones.
     *
     * Delete-and-rewrite rather than diff-and-patch, matching how MaintenanceInvoiceService already
     * rewrites its own line set. It is safe here for the same reason it is safe there: nothing holds a
     * lasting reference to a financial_event_line id. The mapping snapshot these rows carry is written
     * at SEND time, and by then the event is frozen and this method can no longer run.
     */
    private function replaceLines(FinancialEvent $event, array $lines, FinancialEventSource $source): void
    {
        $event->lines()->delete();

        if ($lines === []) {
            // A source with no itemisation still owes one postable line — the service itself. This is
            // how a tow, a wash or a registration fee becomes a bill without a fabricated product.
            $event->lines()->create([
                'kind'        => FinancialEventLine::KIND_SERVICE,
                'description' => $source->financialDescription(),
                'quantity'    => 1,
                'unit_price'  => round($source->financialAmount(), 2),
            ]);

            return;
        }

        foreach ($lines as $spec) {
            $event->lines()->create([
                'origin_type'          => $spec['origin_type'] ?? null,
                'origin_id'            => $spec['origin_id'] ?? null,
                'component_catalog_id' => $spec['component_catalog_id'] ?? null,
                'kind'                 => $spec['kind'],
                'description'          => $spec['description'],
                'quantity'             => (float) $spec['quantity'],
                'uom'                  => $spec['uom'] ?? null,
                'unit_price'           => (float) $spec['unit_price'],
            ]);
        }
    }
}
