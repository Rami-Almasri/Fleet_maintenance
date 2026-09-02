<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One Ticket → Many Invoices — the lifecycle of a single garage bill on a maintenance ticket.
 *
 * A ticket is worked in one or more garages; each garage hands us its OWN invoice, covering only the
 * faults it fixed. This service owns the create / update / delete / reconcile of those invoice rows, and
 * keeps the ticket's aggregate honest afterwards (its `cost` sums the lines across all invoices; its
 * headline reconciliation status rolls up from them — see Maintenance::recalcInvoiceAggregate).
 *
 * It reuses the two invariants the single-invoice path already enforced:
 *   - Diagnosis-First: every part/labor line must be attributed to a finding that exists on the ticket.
 *   - Variance gate: when a printed receipt total is supplied, a gap over a cent needs an explanation.
 */
class MaintenanceInvoiceService
{
    /** Controllers/managers who own the money (mirrors the workflow + garage-portal services). */
    private const NOTIFY_CONTROLLERS = 'maintenance.manage';

    /** Receipt vs itemised agree within a cent. */
    private const VARIANCE_TOLERANCE = 0.01;

    public function __construct(
        private NotificationScanner $notifier,
        private VehicleLogService $log,
        private IncorrectFaultCostGuard $incorrect,
        private GarageLineItemLedgerService $partsLedger,
        // The accounting bridge. A garage bill IS the repair's financial obligation, so the event is
        // kept in step from here rather than from a separate finance screen — §6/§31. Injected as a
        // collaborator like the parts ledger above, because it is the same shape of concern: one more
        // downstream ledger that a bill keeps honest as it is written.
        private \App\Services\Odoo\FinancialEventBuilder $financial,
    ) {}

    /**
     * Keep this bill's financial event in step with the bill.
     *
     * Deliberately best-effort. The obligation is DERIVED from the invoice, so it can always be rebuilt
     * (`odoo:rebuild-events`), and an accounting-bridge problem must never roll back a garage bill that
     * somebody just keyed — the same rule the audit log already follows in VehicleLogService::record().
     * An event that fails to build is one that stays absent and visible on the sync dashboard, which is
     * far better than a lost invoice.
     *
     * Called as the LAST step inside the invoice's own transaction — after the lines, the bands and the
     * receipt are written, so the event is built from the final figures rather than a half-written set,
     * and still inside the transaction so a rolled-back invoice cannot leave an obligation behind for a
     * bill that never existed.
     */
    private function syncFinancialEvent(MaintenanceInvoice $invoice, User $actor): void
    {
        try {
            $this->financial->syncFor($invoice->fresh(['lineItems', 'maintenance.vehicle', 'vendor']), $actor);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The bill is being deleted — retire its obligation.
     *
     * An UNSENT event is deleted with the bill: it described a cost that no longer exists, and keeping it
     * would leave the sync dashboard asking somebody to post a bill that has been withdrawn.
     *
     * A SENT event (SENDING or SYNCED) is kept and marked, because it is the only link between the
     * document sitting in Odoo and the work it paid for. See the call site for the full reasoning.
     */
    private function retireFinancialEvent(MaintenanceInvoice $invoice, User $actor): void
    {
        try {
            $event = \App\Models\FinancialEvent::forSource($invoice->getMorphClass(), (int) $invoice->id)->first();

            if (! $event) {
                return;
            }

            if ($event->isFrozen()) {
                $event->update([
                    'cancellation_reason' => 'The garage invoice this cost came from was deleted by '
                        . $actor->name . '. The Odoo document it created still stands.',
                ]);

                return;
            }

            $event->lines()->delete();
            $event->delete();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Create a new invoice under a ticket: name its garage, link the faults it covers, key its parts/labor
     * lines + printed receipt total, and attach the receipt photo. Runs the Diagnosis-First + variance
     * gates, flags it pending reconciliation, then rolls the ticket up. Returns the fresh invoice.
     *
     * @param array{vendor_id?:?int, invoice_no?:?string, task_ids?:array<int>, line_items?:array<int,array>,
     *              receipt_total?:?float, variance_explanation?:?string, notes?:?string} $data
     */
    public function create(Maintenance $ticket, array $data, User $actor, ?UploadedFile $photo = null): MaintenanceInvoice
    {
        return DB::transaction(function () use ($ticket, $data, $actor, $photo) {
            $internal = $this->isInternal($data);
            $invoice = $ticket->invoices()->create([
                'is_internal'               => $internal,
                // An in-house bill has no external garage — force the vendor null even if one was passed.
                'vendor_id'                 => $internal ? null : $this->resolveVendorId($ticket, $data),
                'invoice_no'                => $this->clean($data['invoice_no'] ?? null),
                'notes'                     => $this->clean($data['notes'] ?? null),
                'reconciliation_status'     => Maintenance::RECON_PENDING,
                'reconciliation_flagged_at' => Carbon::now(),
                'recorded_by'               => $actor->id,
                'recorded_at'               => Carbon::now(),
            ]);

            $this->syncFaults($ticket, $invoice, $data['task_ids'] ?? []);
            $this->replaceLineItems($ticket, $invoice, $data['line_items'] ?? [], $actor);
            $this->applyVatAndDiscount($ticket, $invoice, $data, $actor);
            $this->applyReceiptGate($invoice, $data);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($invoice, $photo);
                $invoice->receipt_photo_disk = $disk;
                $invoice->receipt_photo_key  = $key;
            }
            $invoice->save();

            $this->logAndNotify($ticket, $invoice, $actor, 'created');
            $this->syncFinancialEvent($invoice, $actor);

            return $invoice->fresh(['vendor', 'tasks', 'lineItems']);
        });
    }

    /**
     * Edit an existing invoice — re-point which faults it covers, replace its line set, re-key the receipt.
     * Same gates as create. The line set is replaced wholesale (the editor always submits the full set).
     */
    public function update(MaintenanceInvoice $invoice, array $data, User $actor, ?UploadedFile $photo = null): MaintenanceInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor, $photo) {
            $ticket = $invoice->maintenance;

            if (array_key_exists('is_internal', $data)) {
                $invoice->is_internal = $this->isInternal($data);
            }
            // Vendor + internal are mutually exclusive: marking in-house clears any garage; naming a garage
            // clears in-house. Whichever the caller touched wins.
            if ($invoice->is_internal) {
                $invoice->vendor_id = null;
            } elseif (array_key_exists('vendor_id', $data)) {
                $invoice->vendor_id = $this->resolveVendorId($ticket, $data);
            }
            if (array_key_exists('invoice_no', $data)) {
                $invoice->invoice_no = $this->clean($data['invoice_no'] ?? null);
            }
            if (array_key_exists('notes', $data)) {
                $invoice->notes = $this->clean($data['notes'] ?? null);
            }

            if (array_key_exists('task_ids', $data)) {
                $this->syncFaults($ticket, $invoice, $data['task_ids'] ?? []);
            }
            if (array_key_exists('line_items', $data)) {
                $this->replaceLineItems($ticket, $invoice, $data['line_items'] ?? [], $actor);
            }
            $this->applyVatAndDiscount($ticket, $invoice, $data, $actor);
            $this->applyReceiptGate($invoice, $data);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($invoice, $photo);
                $invoice->receipt_photo_disk = $disk;
                $invoice->receipt_photo_key  = $key;
            }
            $invoice->save();

            $this->logAndNotify($ticket, $invoice, $actor, 'updated');
            $this->syncFinancialEvent($invoice, $actor);

            return $invoice->fresh(['vendor', 'tasks', 'lineItems']);
        });
    }

    /**
     * Delete an invoice — un-bill its faults (they return to "not invoiced") and drop its cost lines, then
     * roll the ticket back down. The faults themselves are NEVER deleted; only their billing link is cut.
     */
    public function delete(MaintenanceInvoice $invoice, User $actor): Maintenance
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $ticket = $invoice->maintenance;
            $amount = (float) $invoice->amount;
            $no     = $invoice->invoice_no;

            // The obligation this bill raised goes with it — but only if it never reached Odoo. An event
            // that HAS been posted keeps its row (and its odoo_document_id), because deleting our record
            // of a document that exists in the accounting system would strand it: nothing would connect
            // the bill in Odoo to anything here, and the next rebuild would happily post a second one.
            // Withdrawing a posted bill is a credit note raised in Odoo, not a delete here.
            $this->retireFinancialEvent($invoice, $actor);

            // Un-bill the faults, drop the invoice's cost lines, then remove the invoice.
            $invoice->tasks()->update(['maintenance_invoice_id' => null]);
            $invoice->lineItems()->delete();
            $invoice->delete();

            // The FK on tasks/lines nulled out; re-derive the ticket cost + aggregate from what remains.
            $ticket->recalcFromTasks(true);
            $ticket->recalcInvoiceAggregate(true);

            $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
                'description' => 'Invoice removed' . ($no ? ' (' . $no . ')' : '')
                    . ' — AED ' . number_format($amount, 2) . ' (by ' . $actor->name . ')',
                'meta'        => ['deleted_invoice_amount' => $amount, 'ticket_cost' => (float) $ticket->fresh()->cost],
            ]);

            return $ticket->fresh();
        });
    }

    /**
     * Mark an invoice reconciled — finance has matched this bill against the accounting API. The ticket's
     * headline reconciliation status flips to "reconciled" only once EVERY invoice on it is (handled by the
     * model's aggregate roll-up).
     */
    public function reconcile(MaintenanceInvoice $invoice, User $actor): MaintenanceInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice->reconciliation_status = Maintenance::RECON_RECONCILED;
            $invoice->reconciled_by = $actor->id;
            $invoice->reconciled_at = Carbon::now();
            $invoice->save();

            $this->log->record($invoice->maintenance, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
                'description' => 'Invoice reconciled' . ($invoice->invoice_no ? ' (' . $invoice->invoice_no . ')' : '')
                    . ' — AED ' . number_format((float) $invoice->amount, 2) . ' (by ' . $actor->name . ')',
                'meta'        => ['invoice_id' => $invoice->id, 'amount' => (float) $invoice->amount],
            ]);

            return $invoice->fresh(['vendor', 'tasks', 'lineItems']);
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Point the given faults at this invoice (fault → one invoice), and release any fault it previously
     * covered that is no longer selected. Every id must be a fault on the SAME ticket — no cross-ticket
     * billing. Moving a fault off another invoice is allowed (it's a re-assignment).
     *
     * @param array<int> $taskIds
     */
    private function syncFaults(Maintenance $ticket, MaintenanceInvoice $invoice, array $taskIds): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($taskIds))));

        if ($ids) {
            $valid = $ticket->tasks()->whereIn('id', $ids)->pluck('id')->all();
            if (count($valid) !== count($ids)) {
                throw new WorkflowTransitionException(
                    'Every fault on an invoice must belong to this ticket.',
                    ['field' => 'task_ids'],
                );
            }

            // A fault ruled a mis-diagnosis cannot be put on a bill — the car did not have that problem.
            // Cost already spent on it before the ruling is untouched; see IncorrectFaultCostGuard.
            $this->incorrect->assertNoneIncorrect($ids, 'covered by an invoice');
        }

        // Release faults this invoice used to cover but that are no longer selected.
        $invoice->tasks()->whereNotIn('id', $ids ?: [0])->update(['maintenance_invoice_id' => null]);

        // Claim the selected faults for this invoice (re-pointing them off any other invoice).
        if ($ids) {
            MaintenanceTask::whereIn('id', $ids)->update(['maintenance_invoice_id' => $invoice->id]);
        }
    }

    /**
     * Replace this invoice's part/labor lines. Validates Diagnosis-First against the ticket's findings,
     * deletes the invoice's current lines, writes the new set (tagged to this invoice + the ticket, and to
     * the covered fault whose symptom the line names, when it matches), then re-derives the invoice total.
     *
     * @param array<int,array> $items
     */
    private function replaceLineItems(Maintenance $ticket, MaintenanceInvoice $invoice, array $items, User $actor): void
    {
        // Allowed findings on the ticket (inspector + garage), matched case-insensitively on text.
        $findingTexts = collect($ticket->findings ?? [])
            ->pluck('text')->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->flip();

        // Faults on this ticket ruled a mis-diagnosis — no NEW line may be charged to one of them. Fetched
        // once for the whole batch rather than per line (see IncorrectFaultCostGuard).
        $incorrect = $this->incorrect->incorrectSymptoms($ticket);

        // Validate the WHOLE batch before touching anything, so a bad line fails with no side effects.
        foreach ($items as $row) {
            if (! is_array($row) || $this->clean($row['description'] ?? null) === null) {
                continue;
            }
            $finding = $this->clean($row['finding_text'] ?? null);
            if ($finding === null) {
                throw new WorkflowTransitionException(
                    'Every part or labor line must be linked to a finding on this ticket (Diagnosis-First).',
                    ['field' => 'finding_text'],
                );
            }
            if (! $findingTexts->has(mb_strtolower($finding))) {
                throw new WorkflowTransitionException(
                    "“{$finding}” is not a finding on this ticket — link each cost to an existing symptom.",
                    ['field' => 'finding_text'],
                );
            }
            $this->incorrect->assertLineNotOnIncorrectFault($finding, $incorrect, 'charged for parts or labour');
        }

        // A PART ON THIS BILL MUST BE A PART THIS TICKET RECORDED, SUPPLIED BY WHOEVER IS BILLING.
        // Checked for the whole batch before anything is written, and before the existing lines are
        // deleted — a refused part leaves the invoice exactly as it was. {@see assertPartBillable}
        $grandfathered = $this->partIdentityKeys($invoice->lineItems()->where('kind', MaintenanceLineItem::KIND_PART)->get());
        foreach ($items as $row) {
            if (! is_array($row) || ($row['kind'] ?? null) === MaintenanceLineItem::KIND_LABOR) {
                continue;
            }
            if ($this->clean($row['description'] ?? null) === null) {
                continue;
            }
            // ONLY WHAT WE KEY OURSELVES. A bill that arrives from OUTSIDE — the garage typing its own
            // invoice into the public portal, an OCR'd receipt, an import — cannot name a row in our
            // parts records, because whoever wrote it has never seen them. Demanding it would not make
            // those bills more honest, it would make them unrecordable. Their wording is resolved
            // strictly instead ({@see resolveCatalogPart}), which is the check that surface can pass.
            if (($row['entry_source'] ?? 'manual') !== 'manual') {
                continue;
            }
            $this->assertPartBillable($ticket, $invoice, $row, $grandfathered);
        }

        // Map a finding text → the covered fault it names, so a line also feeds per-fault cost (best-effort).
        $taskBySymptom = $invoice->tasks()->get()
            ->keyBy(fn (MaintenanceTask $t) => mb_strtolower(trim((string) $t->symptom)));

        // Only the WORK lines are replaced. VAT and discount sit on the same invoice but are owned by
        // applyVatAndDiscount(), and an edit that submits line_items without touching them must not
        // silently wipe them — so they are scoped out here rather than caught by a blanket delete.
        $invoice->lineItems()->whereIn('kind', MaintenanceLineItem::WORK_KINDS)->delete();

        $fallbackOdo = $ticket->return_odometer ?: $ticket->receive_odometer ?: null;

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $description = $this->clean($row['description'] ?? null);
            if ($description === null) {
                continue;
            }

            $kind   = ($row['kind'] ?? null) === MaintenanceLineItem::KIND_LABOR
                ? MaintenanceLineItem::KIND_LABOR : MaintenanceLineItem::KIND_PART;
            $isPart = $kind === MaintenanceLineItem::KIND_PART;
            $qty    = isset($row['quantity']) && is_numeric($row['quantity']) ? round((float) $row['quantity'], 2) : 1;
            $price  = isset($row['unit_price']) && is_numeric($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;
            $finding = $this->clean($row['finding_text'] ?? null);

            // WHICH PART was fitted — the reference, not the wording. A picked id is taken as given
            // (a person said so); otherwise the typed wording is resolved strictly, which is how the
            // public garage portal and OCR lines still land on a real part. Unresolvable wording stays
            // null: visibly unidentified beats plausibly mislabelled.
            [$catalogId, $matchedBy, $catalogPart] = $isPart
                ? $this->resolveCatalogPart($row, $description)
                : [null, null, null];

            $invoice->lineItems()->create([
                'maintenance_id'     => $ticket->id,
                'maintenance_task_id'=> optional($taskBySymptom->get(mb_strtolower((string) $finding)))->id,
                'vehicle_id'         => $ticket->vehicle_id,
                'kind'               => $kind,
                'finding_text'       => $finding,
                // A part states its OWN category, from the catalog entry. It used to be derived from
                // the fault the line is attributed to, which files spend under the wrong heading
                // whenever the part fitted for a symptom isn't the obvious one ("engine noise" repaired
                // with a belt is not an engine part). The submitted key is the fallback for a line with
                // no catalog reference — labor, and history typed before the picker.
                'category_key'       => $catalogPart?->category_key ?: $this->clean($row['category_key'] ?? null),
                'description'        => $description,
                'part_number'        => $isPart ? $this->clean($row['part_number'] ?? null) : null,
                'component_catalog_id' => $catalogId,
                'catalog_matched_by' => $matchedBy,
                // WHERE the part came from — the purchase / request / required line it was billed from.
                // Null only on a line kept from before the parts record existed.
                'part_source'        => $isPart ? $this->cleanPartSource($row) : null,
                'part_source_id'     => $isPart && $this->cleanPartSource($row) ? (int) $row['part_source_id'] : null,
                'tire_brand'         => $isPart ? $this->clean($row['tire_brand'] ?? null) : null,
                'tire_dot'           => $isPart ? $this->clean($row['tire_dot'] ?? null) : null,
                'tire_tread_mm'      => $isPart && isset($row['tire_tread_mm']) && is_numeric($row['tire_tread_mm']) ? (float) $row['tire_tread_mm'] : null,
                'quantity'           => max(0, $qty),
                'uom'                => $this->clean($row['uom'] ?? null) ?: ($isPart ? 'unit' : 'hour'),
                'unit_price'         => max(0, $price),
                'installed_on'       => $isPart ? ($this->clean($row['installed_on'] ?? null) ?: $ticket->actual_in_date?->toDateString() ?: null) : null,
                'installed_odometer' => $isPart ? ((isset($row['installed_odometer']) && is_numeric($row['installed_odometer'])) ? (int) $row['installed_odometer'] : $fallbackOdo) : null,
                'warranty_months'    => $isPart && isset($row['warranty_months']) && is_numeric($row['warranty_months']) ? (int) $row['warranty_months'] : null,
                'created_by'         => $actor->id,
                'entry_source'       => in_array(($row['entry_source'] ?? null), ['manual', 'ocr', 'import', 'garage'], true)
                                            ? $row['entry_source'] : 'manual',
                // Structured origin: every line on this bill is backed by this bill.
                'source_type'        => CostSourceResolver::SOURCE_GARAGE_INVOICE,
                'source_id'          => $invoice->id,
            ]);
        }

        // Re-derive this invoice's own total from the freshly written lines (bubbles up to the ticket).
        $invoice->load('lineItems');
        $invoice->recalcTotals();

        // THE PART IS NOW A PART, not just a price. Every part line this bill carries enters the
        // same lifecycle a supplier-bought part travels: a PartPurchase (purchase_source=garage),
        // and a physical component on the car when the data supports one. Reconciled rather than
        // created, because the loop above deletes and recreates these lines on every edit.
        //
        // NO MONEY MOVES. The line item stays the canonical dirham; PartSpendService excludes any
        // purchase that carries a maintenance_line_item_id, which every purchase written here does.
        //
        // NEVER BLOCKS THE BILL. A ledger problem must not cost us the invoice — the invoice is the
        // scarcer evidence, and the same reasoning RepairCaptureService applies to its own asset
        // writes. A failure is logged and swept up by `parts:backfill-garage-lines` afterwards.
        try {
            $this->partsLedger->syncInvoice($invoice, $actor);
        } catch (\Throwable $e) {
            logger()->warning('parts_ledger.invoice_sync_failed', [
                'maintenance_invoice_id' => $invoice->id,
                'error'                  => $e->getMessage(),
            ]);
        }
    }

    /**
     * WHOEVER SUPPLIED THE PART IS WHO BILLS FOR IT.
     *
     * The mirror of PartInvoiceService::assertAttachable, which has always refused a garage-bought part
     * on a supplier's parts invoice. The other half was missing: nothing stopped a supplier-bought part —
     * or one bought from a DIFFERENT garage — being keyed onto this garage's bill, which charges the
     * ticket for it twice, once here and once on the parts invoice. Four rules:
     *
     *  1. NAMED. A part line must point at a part this ticket actually recorded (bought, requested, or
     *     listed by the inspector). A price with no such record behind it is a number nobody can check.
     *  2. THIS TICKET'S. The record must belong to this ticket, not another car's.
     *  3. SUPPLIER PARTS BELONG TO THE SUPPLIER. A supplier-sourced purchase is billed on that supplier's
     *     parts invoice — never here.
     *  4. THE GARAGE THAT SUPPLIED IT. A part bought from garage X is billable on garage X's invoice and
     *     no one else's, and an in-house bill carries no garage-supplied part at all.
     *
     * Plus the rule the ledger already had: a purchase that was FITTED wrote its own cost line, so
     * billing it again would double it.
     *
     * A line already on this invoice is grandfathered — history keyed before parts were recorded stays
     * editable, since refusing it would make old invoices impossible to correct.
     *
     * @param array<string,bool> $grandfathered identity keys of the part lines already on this invoice
     */
    private function assertPartBillable(Maintenance $ticket, MaintenanceInvoice $invoice, array $row, array $grandfathered): void
    {
        $source = $this->cleanPartSource($row);
        $name   = $this->clean($row['description'] ?? null) ?: 'This part';

        if ($source === null) {
            // No origin given. Allowed only if the same part is already on this bill.
            if (isset($grandfathered[$this->partIdentityKey($row['component_catalog_id'] ?? null, $row['description'] ?? null)])) {
                return;
            }

            throw new WorkflowTransitionException(
                "“{$name}” is not one of the parts recorded on this ticket. Add the part to the ticket first "
                . '— buy it, request it, or list it as required — then bill it here, so the price on the '
                . 'invoice is the price we recorded paying.',
                ['field' => 'line_items', 'reason' => 'part_not_on_ticket'],
            );
        }

        if ($source !== MaintenanceLineItem::PART_SOURCE_PURCHASE) {
            // A request or a required line carries no supplier and no price — there is nothing to rule
            // on beyond it belonging to this ticket.
            $model = $source === MaintenanceLineItem::PART_SOURCE_REQUEST
                ? \App\Models\PartRequest::class
                : \App\Models\MaintenanceRequiredPart::class;

            if (! $model::where('id', (int) $row['part_source_id'])->where('maintenance_id', $ticket->id)->exists()) {
                throw new WorkflowTransitionException(
                    "“{$name}” is not recorded on this ticket.",
                    ['field' => 'line_items', 'reason' => 'part_not_on_ticket'],
                );
            }

            return;
        }

        $purchase = \App\Models\PartPurchase::find((int) $row['part_source_id']);

        if (! $purchase || (int) $purchase->maintenance_id !== (int) $ticket->id) {
            throw new WorkflowTransitionException(
                "“{$name}” is not a part bought for this ticket.",
                ['field' => 'line_items', 'reason' => 'part_not_on_ticket'],
            );
        }

        if ($purchase->purchase_source === \App\Models\PartPurchase::SOURCE_SUPPLIER) {
            $supplier = $purchase->source_name ?: $purchase->sourceVendor?->name ?: 'a supplier';
            throw new WorkflowTransitionException(
                "“{$name}” was bought from {$supplier}, not from the garage — it belongs on that supplier's "
                . 'parts invoice. Billing it here as well would charge the ticket for it twice.',
                ['field' => 'line_items', 'reason' => 'supplier_sourced', 'purchase_id' => $purchase->id],
            );
        }

        if ($purchase->source_vendor_id) {
            if ($invoice->is_internal) {
                $garage = $purchase->source_name ?: $purchase->sourceVendor?->name ?: 'a garage';
                throw new WorkflowTransitionException(
                    "“{$name}” was supplied by {$garage}, so it cannot go on an in-house bill — that garage "
                    . 'invoices for the parts it supplied.',
                    ['field' => 'line_items', 'reason' => 'other_garage', 'purchase_id' => $purchase->id],
                );
            }
            if ((int) $purchase->source_vendor_id !== (int) $invoice->vendor_id) {
                $garage = $purchase->source_name ?: $purchase->sourceVendor?->name ?: 'another garage';
                throw new WorkflowTransitionException(
                    "“{$name}” was supplied by {$garage}. It belongs on that garage's invoice, not this one.",
                    ['field' => 'line_items', 'reason' => 'other_garage', 'purchase_id' => $purchase->id],
                );
            }
        }

        // Fitting the part already wrote its cost line — unless that very line is one of THIS invoice's,
        // which is what an edit of this same bill looks like.
        if ($purchase->maintenance_line_item_id) {
            $ownLine = MaintenanceLineItem::where('id', $purchase->maintenance_line_item_id)
                ->where('maintenance_invoice_id', $invoice->id)->exists();

            if (! $ownLine) {
                throw new WorkflowTransitionException(
                    "“{$name}” was already billed when it was fitted. Billing it here would charge it twice.",
                    ['field' => 'line_items', 'reason' => 'already_billed', 'purchase_id' => $purchase->id],
                );
            }
        }
    }

    /** The submitted origin, or null when the row names none / names one we do not recognise. */
    private function cleanPartSource(array $row): ?string
    {
        $source = $row['part_source'] ?? null;
        $id     = $row['part_source_id'] ?? null;

        return in_array($source, MaintenanceLineItem::PART_SOURCES, true) && is_numeric($id) && (int) $id > 0
            ? $source : null;
    }

    /** "Same part?" for grandfathering — the catalog reference where there is one, else the wording. */
    private function partIdentityKey($catalogId, $description): string
    {
        return $catalogId ? 'c:' . (int) $catalogId : 'n:' . mb_strtolower(trim((string) $description));
    }

    /** @return array<string,bool> */
    private function partIdentityKeys($lines): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $keys[$this->partIdentityKey($line->component_catalog_id, $line->description)] = true;
        }

        return $keys;
    }

    /**
     * Write this invoice's VAT and discount as LEDGER LINES, not as header fields.
     *
     * A ticket has to separate parts, labour, VAT, discounts, refunds and the net total — and every one of
     * those bands must trace to a document. Keying VAT into a column on the invoice would give the ticket
     * a figure with no row behind it; writing it as a line (kind = vat, carrying this invoice's id) keeps
     * the ONE-ledger rule intact, so the ticket total is still the sum of its lines and the VAT is as
     * auditable as any part.
     *
     * Both are replace-wholesale, like the work lines: the editor always submits the current state. A zero
     * or absent value removes the line entirely rather than leaving a 0.00 row cluttering the bill.
     *
     * Neither carries a `finding_text`: VAT and a discount apply to the DOCUMENT, not to one fault, so
     * they are deliberately ticket-level and never distort a fault's parts/labour cost.
     */
    private function applyVatAndDiscount(Maintenance $ticket, MaintenanceInvoice $invoice, array $data, User $actor): void
    {
        $bands = [
            MaintenanceLineItem::KIND_VAT => [
                'key'   => 'vat_amount',
                'label' => 'VAT',
                // VAT adds to the bill.
                'sign'  => 1,
            ],
            MaintenanceLineItem::KIND_DISCOUNT => [
                'key'   => 'discount_amount',
                'label' => 'Discount',
                // A discount is entered as the positive number printed on the paper and STORED negative,
                // so every band on the ticket is a plain sum and no reader has to guess the direction.
                'sign'  => -1,
            ],
        ];

        $touched = false;

        foreach ($bands as $kind => $band) {
            if (! array_key_exists($band['key'], $data)) {
                continue; // caller isn't touching this band — leave whatever is there
            }
            $touched = true;

            $invoice->lineItems()->where('kind', $kind)->get()->each->delete();

            $amount = round(abs((float) ($data[$band['key']] ?? 0)), 2);
            if ($amount <= 0) {
                continue;
            }

            $invoice->lineItems()->create([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $ticket->vehicle_id,
                'kind'           => $kind,
                'description'    => $band['label'],
                'quantity'       => 1,
                'uom'            => 'unit',
                'unit_price'     => $band['sign'] * $amount,
                'created_by'     => $actor->id,
                'entry_source'   => 'manual',
                // Structured origin: VAT and discount belong to the garage's bill like any other line.
                'source_type'    => CostSourceResolver::SOURCE_GARAGE_INVOICE,
                'source_id'      => $invoice->id,
            ]);
        }

        if ($touched) {
            $invoice->load('lineItems');
            $invoice->recalcTotals();
        }
    }

    /**
     * Variance gate — validate the keyed lines against the printed receipt total. A gap over a cent must be
     * explained or the save is rejected. Sets receipt_total + variance_explanation on the invoice (in
     * memory; the caller saves). Keeps the explanation only while a real mismatch stands.
     */
    private function applyReceiptGate(MaintenanceInvoice $invoice, array $data): void
    {
        if (! array_key_exists('receipt_total', $data)) {
            return; // caller isn't touching the receipt — leave it as it is
        }

        $receiptTotal = $data['receipt_total'] === null || $data['receipt_total'] === ''
            ? null : (float) $data['receipt_total'];
        $explanation  = $this->clean($data['variance_explanation'] ?? null);
        $variance     = $receiptTotal !== null ? round((float) $invoice->amount - $receiptTotal, 2) : 0.0;

        if ($receiptTotal !== null && abs($variance) > self::VARIANCE_TOLERANCE && $explanation === null) {
            throw new WorkflowTransitionException(
                'The itemised total (AED ' . number_format((float) $invoice->amount, 2) . ') does not match the '
                . 'receipt total (AED ' . number_format($receiptTotal, 2) . '). Add a variance explanation to record it.',
                ['field' => 'variance_explanation', 'variance' => $variance],
            );
        }

        $invoice->receipt_total = $receiptTotal;
        $invoice->variance_explanation = ($receiptTotal !== null && abs($variance) > self::VARIANCE_TOLERANCE) ? $explanation : null;
    }

    /** Default the garage to the ticket's current vendor when the caller doesn't name one. */
    private function resolveVendorId(Maintenance $ticket, array $data): ?int
    {
        $given = $data['vendor_id'] ?? null;

        return $given ? (int) $given : $ticket->vendor_id;
    }

    /** Whether this bill is an in-house / internal cost (no third-party garage). Accepts JSON/form truthy. */
    private function isInternal(array $data): bool
    {
        return filter_var($data['is_internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function logAndNotify(Maintenance $ticket, MaintenanceInvoice $invoice, User $actor, string $verb): void
    {
        $variance = $invoice->variance();
        $hasVar   = $variance !== null && abs($variance) > self::VARIANCE_TOLERANCE;
        $lines    = $invoice->lineItems()->count();
        $faults   = $invoice->tasks()->count();
        $garage   = $invoice->is_internal
            ? 'In-House'
            : ($invoice->vendor?->name ?: $invoice->loadMissing('vendor')->vendor?->name);

        $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
            'description' => 'Invoice ' . $verb . ($invoice->invoice_no ? ' (' . $invoice->invoice_no . ')' : '')
                . ($garage ? ' · ' . $garage : '')
                . ' — ' . $lines . ' ' . ($lines === 1 ? 'line' : 'lines')
                . ' over ' . $faults . ' ' . ($faults === 1 ? 'fault' : 'faults')
                . ' · AED ' . number_format((float) $invoice->amount, 2)
                . ($hasVar ? ' · variance AED ' . number_format($variance, 2) . ' explained' : '')
                . ' (by ' . $actor->name . ')',
            'meta' => [
                'invoice_id'    => $invoice->id,
                'amount'        => (float) $invoice->amount,
                'receipt_total' => $invoice->receipt_total !== null ? (float) $invoice->receipt_total : null,
                'variance'      => $variance,
                'faults'        => $faults,
                'lines'         => $lines,
            ],
        ]);

        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        $plate   = $vehicle?->plate_no ?: ('Ticket #' . $ticket->id);
        $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
            'type'     => 'maint_invoice_' . $verb,
            'category' => 'maintenance',
            'severity' => $hasVar ? 'warning' : 'info',
            'title'    => 'Invoice ' . $verb . ' · ' . $plate,
            'body'     => trim($plate . ' — ' . $garage . ' invoice ' . $verb . ': AED '
                . number_format((float) $invoice->amount, 2) . ' over ' . $faults
                . ' ' . ($faults === 1 ? 'fault' : 'faults')
                . ($hasVar ? ' (variance AED ' . number_format($variance, 2) . ')' : '')
                . ' by ' . $actor->name . ' — pending reconciliation.'),
            'url'  => '/maintenance-workflow/' . $ticket->id,
            'key'  => 'maint_invoice:' . $invoice->id . ':' . $verb . ':' . Carbon::now()->timestamp,
            'icon' => 'invoice',
            'meta' => ['ticket_id' => $ticket->id, 'invoice_id' => $invoice->id, 'plate' => $vehicle?->plate_no],
        ], $actor->id);
    }

    /** Store the receipt photo on the same disk the workflow uses, returning [disk, key]. */
    private function storePhoto(MaintenanceInvoice $invoice, UploadedFile $file): array
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $key  = $file->storeAs("maintenance-invoices/ticket-{$invoice->maintenance_id}", (string) Str::uuid() . '.' . $ext, $disk);

        return $key ? [$disk, $key] : [null, null];
    }

    /**
     * Identify the part a submitted line bills.
     *
     * Two grades of evidence, kept apart on purpose. A `component_catalog_id` on the row means a
     * person picked the part from the list, and that is recorded as 'picked' — nothing here second-
     * guesses it. With no id, the wording goes through PartIdentityService, which is strict (exact
     * name, Arabic name, slug, curated other-name, whole-phrase) and returns nothing rather than
     * guessing; that is the path the public garage portal and any imported/OCR line take.
     *
     * @return array{0:?int, 1:?string, 2:?\App\Models\ComponentCatalog}
     */
    private function resolveCatalogPart(array $row, string $description): array
    {
        $picked = isset($row['component_catalog_id']) && is_numeric($row['component_catalog_id'])
            ? (int) $row['component_catalog_id']
            : null;

        if ($picked) {
            $part = \App\Models\ComponentCatalog::find($picked);

            // A stale id (the type was retired away and deleted between page load and submit) is not
            // silently kept: it would point the line at nothing. Fall through to the wording.
            if ($part) {
                return [$part->id, 'picked', $part];
            }
        }

        $hit = app(PartIdentityService::class)->identityFor(null, $description);

        if (! $hit['catalog_id']) {
            return [null, null, null];
        }

        return [$hit['catalog_id'], PartIdentityService::VIA_NAME, \App\Models\ComponentCatalog::find($hit['catalog_id'])];
    }

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
