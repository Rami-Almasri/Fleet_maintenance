<?php

namespace App\Services;

use App\Events\PartDelivered;
use App\Events\PartInstalled;
use App\Events\PartRequestApproved;
use App\Events\PartRequirementRaised;
use App\Models\Maintenance;
use App\Models\RfqLine;
use App\Models\SupplierQuote;
use App\Models\MaintenanceLineItem;
use App\Models\PartInvestigation;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the Part Request lifecycle and the purchase → install cost bridge. Mirrors the existing
 * workflow-service conventions: every mutating method takes a typed User $actor (the controller resolves
 * $request->user(); the client is never trusted for audit), stamps *_by/*_by_name/*_at, and appends a
 * best-effort vehicle_log_events row. Guards enforce the two hard rules — no purchase without a price,
 * no install without a purchase — and the duplicate engine ensures a repeat buy is flagged, never
 * silently accepted.
 */
class PartWorkflowService
{
    public function __construct(
        private PartIntelligenceService $intel,
        private VehicleLogService $log,
        private NotificationScanner $notifier,
        private ComponentService $components,
    ) {}

    // ───────────────────────────── request lifecycle ─────────────────────────────

    /** Open a part request (customer walk-in or garage diagnosis). Classifies the part up front. */
    public function createRequest(array $data, User $actor): PartRequest
    {
        $ticket = ! empty($data['maintenance_id']) ? Maintenance::find($data['maintenance_id']) : null;

        $req = new PartRequest();
        $req->fill([
            'source'              => $data['source'],
            'status'              => PartRequest::STATUS_REQUESTED,
            'vehicle_id'          => $data['vehicle_id'],
            'customer_id'         => $data['customer_id'] ?? null,
            'maintenance_id'      => $data['maintenance_id'] ?? null,
            'maintenance_task_id' => $data['maintenance_task_id'] ?? null,
            'part_name'           => $data['part_name'],
            'part_number'         => $data['part_number'] ?? null,
            'category_key'        => $data['category_key'] ?? null,
            'part_class'          => $this->intel->classify($data['part_name'], $data['part_number'] ?? null, $data['category_key'] ?? null),
            // Garage-source requests inherit the ticket's location (in_shop→garage / on_site→onsite);
            // otherwise take what the caller supplied.
            'repair_location'     => $data['repair_location'] ?? $this->locationFromTicket($ticket),
            'quantity'            => $data['quantity'] ?? 1,
            'reason'              => $data['reason'],
            'estimated_price'     => $data['estimated_price'] ?? null,
            'currency'            => $data['currency'] ?? 'AED',
            'notes'               => $data['notes'] ?? null,
            'requested_by'        => $actor->id,
            'requested_by_name'   => $actor->name ?: $actor->email,
            'requested_at'        => Carbon::now(),
        ]);
        $req->save();

        $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_REQUESTED, $actor, $req->maintenance_id, [
            'description' => "Part requested: {$req->part_name} ({$req->source})",
            'meta'        => ['part_request_id' => $req->id, 'part_class' => $req->part_class, 'source' => $req->source],
        ]);
        PartRequirementRaised::dispatch($req->id, $req->vehicle_id, $req->maintenance_id, $req->maintenance_task_id, $actor->id);

        // Real-time intelligence at REQUEST time: if this vehicle already received the same part recently,
        // alert the admins to review BEFORE approval. (The purchase-time gate + investigation still apply
        // later; this is the earlier, softer heads-up — it never blocks the request.)
        $this->flagRequestDuplicate($req, $actor);

        return $req->fresh();
    }

    /** Best-effort duplicate heads-up when a part is REQUESTED (pre-approval). Never throws, never blocks. */
    private function flagRequestDuplicate(PartRequest $req, User $actor): void
    {
        try {
            $fault   = $req->task;
            $verdict = $this->intel->detectDuplicate(
                $req->vehicle_id, $req->part_name, $req->part_number, $req->category_key, $req->part_class,
                null, false, $fault?->category_key, $fault?->symptom
            );
            if (empty($verdict['duplicate'])) {
                return;
            }

            $prev      = $this->intel->duplicateContext($verdict)['previous'] ?? null;
            $sameFault = ! empty($verdict['same_fault']);

            $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_DUPLICATE_FLAGGED, $actor, $req->maintenance_id, [
                'description' => ($sameFault ? 'Same part re-requested for the SAME fault: ' : 'Possible duplicate part requested: ') . $req->part_name
                                 . ($verdict['days_between'] !== null ? " (last bought {$verdict['days_between']}d ago)" : ''),
                'meta'        => ['part_request_id' => $req->id, 'priority' => $verdict['priority'], 'same_fault' => $sameFault, 'stage' => 'request'],
            ]);

            $this->notifier->notifyByAnyPermission(['parts.investigate'], [
                'type'     => 'part_duplicate_request',
                'category' => 'maintenance',
                'severity' => $verdict['priority'] === PartInvestigation::PRIORITY_HIGH ? 'critical' : 'warning',
                'title'    => $sameFault ? 'Same part bought again for the same fault' : 'Possible duplicate parts request',
                'body'     => $sameFault
                    ? "{$req->requested_by_name} requested {$req->part_name} AGAIN for the same fault"
                      . ($fault?->symptom ? " (“{$fault->symptom}”)" : '')
                      . ($verdict['days_between'] !== null ? " — the previous one was bought {$verdict['days_between']} day(s) ago" : '')
                      . '. Likely a failed repair — review before approval.'
                    : "{$req->requested_by_name} requested {$req->part_name} for a vehicle that already received it "
                      . ($verdict['days_between'] !== null ? "{$verdict['days_between']} day(s) ago" : 'recently')
                      . '. Please review before approval.',
                'url'      => '/parts?vehicle_id=' . $req->vehicle_id,
                'key'      => 'part_dup_req:' . $req->id,
                'icon'     => 'alert',
                'meta'     => [
                    'vehicle_id'           => $req->vehicle_id,
                    'part_request_id'      => $req->id,
                    'previous_purchase_id' => $prev['purchase_id'] ?? null,
                ],
            ], $actor->id);
        } catch (\Throwable $e) {
            report($e); // intelligence is advisory — a failure here must never fail the request
        }
    }

    /**
     * Approve a request. A duplicate heads-up is surfaced to the approver in the UI BEFORE this call
     * (the same signal the purchase step uses); when they approve a flagged repeat anyway, the frontend
     * passes their acknowledgment note through so the "warned & approved" decision is on the record.
     */
    public function approve(PartRequest $req, User $actor, ?string $note = null): PartRequest
    {
        $this->guardStatus($req, [PartRequest::STATUS_REQUESTED, PartRequest::STATUS_UNDER_REVIEW], 'approve');
        $ackDuplicate = $note !== null && trim($note) !== '';

        $fill = [
            'status'           => PartRequest::STATUS_APPROVED,
            'approved_by'      => $actor->id,
            'approved_by_name' => $actor->name ?: $actor->email,
            'approved_at'      => Carbon::now(),
        ];
        if ($ackDuplicate) {
            // Keep the acknowledgment on the request itself (review_notes) so the audit trail shows the
            // approver was warned of the earlier buy and chose to proceed.
            $prefix = $req->review_notes ? $req->review_notes . "\n" : '';
            $fill['review_notes'] = trim($prefix . 'Approved despite duplicate: ' . trim($note));
        }
        $req->forceFill($fill)->save();

        $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_APPROVED, $actor, $req->maintenance_id, [
            'description' => "Part request approved: {$req->part_name}" . ($ackDuplicate ? ' (duplicate acknowledged)' : ''),
            'meta'        => ['part_request_id' => $req->id, 'duplicate_ack' => $ackDuplicate],
        ]);
        PartRequestApproved::dispatch($req->id, $req->vehicle_id, $req->maintenance_id, $actor->id);

        return $req->fresh();
    }

    public function reject(PartRequest $req, User $actor, string $reason): PartRequest
    {
        $this->guardStatus($req, [PartRequest::STATUS_REQUESTED, PartRequest::STATUS_UNDER_REVIEW, PartRequest::STATUS_APPROVED], 'reject');
        $req->forceFill([
            'status'           => PartRequest::STATUS_REJECTED,
            'rejected_by'      => $actor->id,
            'rejected_by_name' => $actor->name ?: $actor->email,
            'rejected_at'      => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_REJECTED, $actor, $req->maintenance_id, [
            'description' => "Part request rejected: {$req->part_name}",
            'meta'        => ['part_request_id' => $req->id, 'reason' => $reason],
        ]);

        return $req->fresh();
    }

    // ───────────────────────────── purchase (+ duplicate detection) ─────────────────────────────

    /**
     * Record a purchase against an approved request. Runs inside a transaction with the prior purchases
     * LOCKED so two concurrent buys can't both miss the other (edge case 6). If the intelligence layer
     * flags a duplicate, the purchase is still recorded but marked requires_review, linked to the earlier
     * one, an investigation is opened (with the reason if the caller supplied one), and the admins are
     * alerted — it is never silently accepted.
     *
     * @param array $data validated: purchase_source, source_vendor_id?, source_name?, purchase_price,
     *                    currency?, quantity?, repair_location?, notes?, duplicate_reason_code?, duplicate_reason_note?
     */
    public function purchase(PartRequest $req, array $data, User $actor): array
    {
        $this->guardStatus($req, [PartRequest::STATUS_APPROVED, PartRequest::STATUS_PURCHASED], 'purchase');

        return DB::transaction(function () use ($req, $data, $actor) {
            $partClass = $req->part_class ?: $this->intel->classify($req->part_name, $req->part_number, $req->category_key);

            // Look for a prior buy of the same part on this vehicle, WITH A LOCK, before we insert. The
            // fault context (from the request's task) escalates a same-part-for-the-same-fault repeat.
            $fault   = $req->task;
            $verdict = $this->intel->detectDuplicate(
                $req->vehicle_id, $req->part_name, $req->part_number, $req->category_key, $partClass, null, true,
                $fault?->category_key, $fault?->symptom
            );

            $purchase = new PartPurchase();
            $purchase->fill([
                'part_request_id'     => $req->id,
                'vehicle_id'          => $req->vehicle_id,
                'maintenance_id'      => $req->maintenance_id,
                'maintenance_task_id' => $req->maintenance_task_id,
                'part_name'           => $req->part_name,
                'part_number'         => $req->part_number,
                'category_key'        => $req->category_key,
                'part_class'          => $partClass,
                'purchase_source'     => $data['purchase_source'],
                'source_vendor_id'    => $data['source_vendor_id'] ?? null,
                'source_name'         => $data['source_name'] ?? null,
                'repair_location'     => $data['repair_location'] ?? $req->repair_location,
                'purchase_price'      => $data['purchase_price'],
                'currency'            => $data['currency'] ?? $req->currency ?? 'AED',
                'quantity'            => $data['quantity'] ?? $req->quantity ?? 1,
                'purchased_by'        => $actor->id,
                'purchased_by_name'   => $actor->name ?: $actor->email,
                'purchased_at'        => Carbon::now(),
                'result'              => PartPurchase::RESULT_PENDING,
                'requires_review'     => $verdict['duplicate'],
                'duplicate_of_purchase_id' => $verdict['duplicate'] ? optional($verdict['previous'])->id : null,
                'notes'               => $data['notes'] ?? null,
                // Phase 2 (P2-3): RFQ linkage + the awarded quote's promised delivery date, when the PO
                // is issued from an RFQ award. Null for a direct single-supplier buy — behaviour unchanged.
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'rfq_line_id'            => $data['rfq_line_id'] ?? null,
                'supplier_quote_id'      => $data['supplier_quote_id'] ?? null,
                'po_number'              => $data['po_number'] ?? null,
            ]);
            $purchase->save();

            $req->forceFill(['status' => PartRequest::STATUS_PURCHASED])->save();

            $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_PURCHASED, $actor, $req->maintenance_id, [
                'description' => "Part purchased ({$purchase->purchase_source}): {$purchase->part_name} — {$purchase->purchase_price} {$purchase->currency}",
                'meta'        => ['part_purchase_id' => $purchase->id, 'source' => $purchase->purchase_source, 'duplicate' => $verdict['duplicate']],
            ]);

            $investigation = null;
            if ($verdict['duplicate']) {
                $investigation = $this->openDuplicateInvestigation($purchase, $verdict, $actor, $data);
            }

            return ['purchase' => $purchase->fresh(), 'verdict' => $verdict, 'investigation' => $investigation];
        });
    }

    /** Raise the admin duplicate-purchase investigation + HIGH/MEDIUM alert. Best-effort, never blocks. */
    private function openDuplicateInvestigation(PartPurchase $purchase, array $verdict, User $actor, array $data): ?PartInvestigation
    {
        $hasReason = ! empty($data['duplicate_reason_code']);
        $inv = PartInvestigation::create([
            'type'                 => PartInvestigation::TYPE_DUPLICATE_PURCHASE,
            'priority'             => $verdict['priority'],
            'status'               => $hasReason ? PartInvestigation::STATUS_REASON_PROVIDED : PartInvestigation::STATUS_OPEN,
            'vehicle_id'           => $purchase->vehicle_id,
            'part_purchase_id'     => $purchase->id,
            'previous_purchase_id' => optional($verdict['previous'])->id,
            'reason_code'          => $data['duplicate_reason_code'] ?? null,
            'reason_note'          => $data['duplicate_reason_note'] ?? null,
            'context'              => $this->intel->duplicateContext($verdict),
            'opened_by'            => null, // system-raised
            'opened_by_name'       => 'System',
            'opened_at'            => Carbon::now(),
            'reason_by'            => $hasReason ? $actor->id : null,
            'reason_by_name'       => $hasReason ? ($actor->name ?: $actor->email) : null,
            'reason_at'            => $hasReason ? Carbon::now() : null,
        ]);

        $this->logVehicle($purchase->vehicle_id, VehicleLogEvent::EVENT_PART_DUPLICATE_FLAGGED, $actor, $purchase->maintenance_id, [
            'description' => "Duplicate purchase flagged: {$purchase->part_name} bought again after {$verdict['days_between']}d",
            'meta'        => ['part_investigation_id' => $inv->id, 'priority' => $verdict['priority']],
        ]);

        $prev = $verdict['previous'];
        $this->notifier->notifyByAnyPermission(['parts.investigate'], [
            'type'     => 'part_duplicate',
            'category' => 'maintenance',
            'severity' => $verdict['priority'] === PartInvestigation::PRIORITY_HIGH ? 'critical' : 'warning',
            'title'    => 'Duplicate part purchase',
            'body'     => "This vehicle already received {$purchase->part_name} "
                          . ($verdict['days_between'] !== null ? "{$verdict['days_between']} day(s) ago" : 'recently')
                          . ($prev ? " (prev cost {$prev->purchase_price} {$prev->currency})." : '.'),
            'url'      => "/part-investigations?id={$inv->id}",
            'key'      => 'part_dup:' . $inv->id,
            'icon'     => 'alert',
            'meta'     => ['vehicle_id' => $purchase->vehicle_id, 'part_investigation_id' => $inv->id],
        ], $actor->id);

        return $inv;
    }

    // ───────────────────────────── install (cost bridge) ─────────────────────────────

    /**
     * Fit a purchased part. Enforces "no install without a purchase" (this row is the purchase). When the
     * part is tied to a fault/ticket it generates a maintenance_line_items row (kind=part) so the cost
     * rolls up the existing line_item → task → ticket chain — no parallel ledger. Only base-currency buys
     * feed the cost field; a non-AED buy still records the install + warranty but with a 0 cost line + note.
     *
     * @param array $data validated: installed_odometer?, result?, warranty_months?, notes?
     */
    public function installPurchase(PartPurchase $purchase, array $data, User $actor): PartPurchase
    {
        if ($purchase->isInstalled()) {
            abort(409, 'This part is already installed.');
        }

        return DB::transaction(function () use ($purchase, $data, $actor) {
            $lineItemId = $purchase->maintenance_line_item_id;

            // Cost bridge — only when the part is attached to a ticket (a pure customer buy has no ticket).
            if ($purchase->maintenance_id) {
                $base      = config('parts_intelligence.base_currency', 'AED');
                $isBase    = strtoupper((string) $purchase->currency) === strtoupper($base);
                $task      = $purchase->task;

                $line = MaintenanceLineItem::create([
                    'maintenance_id'      => $purchase->maintenance_id,
                    'maintenance_task_id' => $purchase->maintenance_task_id,
                    'vehicle_id'          => $purchase->vehicle_id,
                    'kind'                => MaintenanceLineItem::KIND_PART,
                    'finding_text'        => $task?->symptom, // Diagnosis-First: tie the part to its fault
                    'category_key'        => $purchase->category_key,
                    'description'         => $purchase->part_name,
                    'part_number'         => $purchase->part_number,
                    'quantity'            => $purchase->quantity ?: 1,
                    'unit_price'          => $isBase ? $purchase->purchase_price : 0,
                    'installed_on'        => Carbon::now()->toDateString(),
                    'installed_odometer'  => $data['installed_odometer'] ?? null,
                    'warranty_months'     => $data['warranty_months'] ?? null,
                    'created_by'          => $actor->id,
                    'entry_source'        => 'purchase', // origin tag (entry_source is varchar(12)); marks a Parts-Purchase-flow line
                ]);
                $lineItemId = $line->id;

                if (! $isBase) {
                    $line->update(['description' => $purchase->part_name . " (paid {$purchase->purchase_price} {$purchase->currency}, not converted)"]);
                }
            }

            $purchase->forceFill([
                'installed_by'             => $actor->id,
                'installed_by_name'        => $actor->name ?: $actor->email,
                'installed_at'             => Carbon::now(),
                'installed_odometer'       => $data['installed_odometer'] ?? $purchase->installed_odometer,
                'result'                   => $data['result'] ?? PartPurchase::RESULT_SUCCESS,
                'maintenance_line_item_id' => $lineItemId,
                'notes'                    => $data['notes'] ?? $purchase->notes,
            ])->save();

            if ($req = $purchase->request) {
                if (! in_array($req->status, PartRequest::TERMINAL, true)) {
                    $req->forceFill(['status' => PartRequest::STATUS_INSTALLED])->save();
                }
            }

            // ── Asset Layer (flag contract, docs/Asset-Layer-Phase2-Workflow-Design.md §7) ──
            //   off      → byte-identical behavior, zero asset writes.
            //   shadow   → best-effort component write; ANY failure is reported and swallowed so it
            //              can never block or roll back the billing write (measuring, not gating).
            //   enforced → same transaction: a component failure rolls back the whole install.
            $assetMode = config('features.asset_layer', 'off');
            if ($assetMode === 'enforced') {
                $this->components->installFromPurchase($purchase->fresh(), $data, $actor);
            } elseif ($assetMode === 'shadow') {
                try {
                    $this->components->installFromPurchase($purchase->fresh(), $data, $actor);
                } catch (\Throwable $e) {
                    // report() ALONE IS NOT ENOUGH HERE. Every guard in ComponentService rejects with
                    // abort(4xx), i.e. an HttpException — which sits in the framework's internalDontReport
                    // list and is therefore dropped without a line. That made the most common failure
                    // (no component type picked, or a serialized part installed with no serial) totally
                    // invisible: the part billed, the timeline logged it, and the vehicle's configuration
                    // silently never updated. Shadow mode exists to MEASURE, so the measurement is
                    // written explicitly, with the context needed to fix the row by hand.
                    logger()->warning('asset_layer.install_failed', [
                        'part_purchase_id' => $purchase->id,
                        'vehicle_id'       => $purchase->vehicle_id,
                        'maintenance_id'   => $purchase->maintenance_id,
                        'part_name'        => $purchase->part_name,
                        'catalog_id'       => data_get($data, 'component.component_catalog_id'),
                        'reason'           => $e->getMessage(),
                        'exception'        => get_class($e),
                    ]);
                    report($e);
                }
            }

            $this->logVehicle($purchase->vehicle_id, VehicleLogEvent::EVENT_PART_INSTALLED, $actor, $purchase->maintenance_id, [
                'description' => "Part installed: {$purchase->part_name} ({$purchase->result})",
                'meta'        => ['part_purchase_id' => $purchase->id, 'line_item_id' => $lineItemId, 'result' => $purchase->result],
            ]);
            PartInstalled::dispatch($purchase->id, $purchase->part_request_id, $purchase->vehicle_id, $purchase->maintenance_id, $actor->id);

            return $purchase->fresh();
        });
    }

    /**
     * Mark a purchased part as delivered to the workshop (Phase 1, Step 6): stamps delivered_at, records a
     * rich timeline entry, and emits {@see PartDelivered} so derived boards recompute. A delivered part is
     * what unblocks the repair in the resolver — the wait was for DELIVERY, not the fitting. Guards: no
     * re-delivery, and a part already installed is past this step.
     */
    public function markDelivered(PartPurchase $purchase, User $actor): PartPurchase
    {
        if ($purchase->delivered_at !== null) {
            abort(409, 'This part is already marked delivered.');
        }
        if ($purchase->isInstalled()) {
            abort(409, 'This part is already installed.');
        }

        $purchase->forceFill(['delivered_at' => Carbon::now()])->save();

        $this->logVehicle($purchase->vehicle_id, VehicleLogEvent::EVENT_PART_DELIVERED, $actor, $purchase->maintenance_id, [
            'description' => "Part delivered: {$purchase->part_name}",
            'meta'        => ['part_purchase_id' => $purchase->id],
        ]);
        PartDelivered::dispatch($purchase->id, $purchase->part_request_id, $purchase->vehicle_id, $purchase->maintenance_id, $actor->id);

        return $purchase->fresh();
    }

    /**
     * Issue a purchase order from an AWARDED RFQ line (Phase 2, P2-3). PartWorkflowService stays the SOLE
     * writer of part_purchases — ProcurementService delegates here rather than writing a PO itself. Reuses
     * purchase() wholesale (duplicate detection, cost bridge on install, request → purchased), fed the
     * awarded quote's price/supplier/ETA plus the RFQ linkage.
     */
    public function issuePurchaseOrderFromQuote(RfqLine $line, SupplierQuote $quote, User $actor): array
    {
        $request = $line->request;
        if ($request === null) {
            abort(422, 'This RFQ line is not linked to a part request.');
        }

        return $this->purchase($request, [
            'purchase_source'        => PartPurchase::SOURCE_SUPPLIER,
            'source_vendor_id'       => $quote->vendor_id,
            'purchase_price'         => $quote->unit_price,
            'currency'               => $quote->currency,
            'quantity'               => $quote->quantity,
            'expected_delivery_date' => optional($quote->expected_delivery_date)->toDateString(),
            'rfq_line_id'            => $line->id,
            'supplier_quote_id'      => $quote->id,
            'po_number'              => 'PO-' . $line->part_rfq_id . '-' . $line->id,
        ], $actor);
    }

    public function complete(PartRequest $req, User $actor): PartRequest
    {
        $this->guardStatus($req, [PartRequest::STATUS_INSTALLED, PartRequest::STATUS_PURCHASED], 'complete');
        $req->forceFill(['status' => PartRequest::STATUS_COMPLETED])->save();

        $this->logVehicle($req->vehicle_id, VehicleLogEvent::EVENT_PART_COMPLETED, $actor, $req->maintenance_id, [
            'description' => "Part request completed: {$req->part_name}",
            'meta'        => ['part_request_id' => $req->id],
        ]);

        return $req->fresh();
    }

    // ───────────────────────────── investigations ─────────────────────────────

    public function reviewInvestigation(PartInvestigation $inv, User $actor): PartInvestigation
    {
        $inv->forceFill(['status' => PartInvestigation::STATUS_UNDER_REVIEW])->save();

        return $inv->fresh();
    }

    public function provideReason(PartInvestigation $inv, User $actor, string $reasonCode, ?string $note = null): PartInvestigation
    {
        $inv->forceFill([
            'status'         => PartInvestigation::STATUS_REASON_PROVIDED,
            'reason_code'    => $reasonCode,
            'reason_note'    => $note,
            'reason_by'      => $actor->id,
            'reason_by_name' => $actor->name ?: $actor->email,
            'reason_at'      => Carbon::now(),
        ])->save();

        return $inv->fresh();
    }

    public function resolveInvestigation(PartInvestigation $inv, User $actor, bool $approved, ?string $note = null): PartInvestigation
    {
        $inv->forceFill([
            'status'           => PartInvestigation::STATUS_CLOSED,
            'resolution'       => $approved ? PartInvestigation::STATUS_APPROVED : PartInvestigation::STATUS_REJECTED,
            'resolved_by'      => $actor->id,
            'resolved_by_name' => $actor->name ?: $actor->email,
            'resolved_at'      => Carbon::now(),
            'reason_note'      => $note ?? $inv->reason_note,
        ])->save();

        // Clear the review flag on the flagged purchase once an admin has adjudicated it.
        if ($inv->part_purchase_id) {
            PartPurchase::where('id', $inv->part_purchase_id)->update(['requires_review' => false]);
        }

        return $inv->fresh();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function locationFromTicket(?Maintenance $ticket): ?string
    {
        if (! $ticket || ! $ticket->repair_location) {
            return null;
        }

        return $ticket->repair_location === 'on_site' ? PartRequest::LOCATION_ONSITE : PartRequest::LOCATION_GARAGE;
    }

    /** @param array<int,string> $allowed */
    private function guardStatus(PartRequest $req, array $allowed, string $action): void
    {
        if (! in_array($req->status, $allowed, true)) {
            abort(409, "Cannot {$action} a part request that is '{$req->status}'.");
        }
    }

    private function logVehicle(int $vehicleId, string $event, User $actor, ?int $maintenanceId, array $opts): void
    {
        // Anchor to the ticket when there is one (keeps the ticket link), else to the vehicle trail.
        if ($maintenanceId && ($ticket = Maintenance::find($maintenanceId))) {
            $this->log->record($ticket, $event, $actor, ['source_tag' => 'parts'] + $opts);

            return;
        }
        if ($vehicle = \App\Models\Vehicle::find($vehicleId)) {
            $this->log->recordVehicle($vehicle, $event, $actor, ['source_tag' => 'parts'] + $opts);
        }
    }
}
