<?php

namespace App\Http\Resources;

use App\Models\FinancialEvent;
use App\Support\ExpenseType;
use App\Support\FinancialSyncStatus as Status;
use App\Support\OdooDocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A financial event as the interface needs it — including WHY it is where it is.
 *
 * The shape is driven by what §30 asks the screen to render inside the existing workflow: a checklist
 * of requirements with each one ticked or not, the blocking reasons in a form the UI can translate, and
 * only the actions that are actually available to this user right now.
 *
 * ── THE ACTIONS ARE COMPUTED HERE, NOT IN THE CLIENT ───────────────────────────────────────────────
 *
 * Same discipline as FinancialDocumentStatus::actionsFor(): a screen that decides for itself which
 * buttons to show will drift from what the routes permit, and the drift shows up as a button that 403s.
 * The server intersects "legal in this state" with "this user holds the permission" and sends the
 * result, so every screen offers the same thing to the same person.
 *
 * ── REASONS GO OUT AS CODE + PARAMS ────────────────────────────────────────────────────────────────
 *
 * The frozen English rides along for the audit trail but the UI renders from `code` and `params`, which
 * is what lets the Arabic interface say the same thing. See FinancialBlockReason.
 *
 * @mixin FinancialEvent
 */
class FinancialEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $can  = fn (string $perm) => (bool) $user?->can($perm);

        return [
            'id'           => $this->id,
            'source_type'  => $this->source_type,
            'source_id'    => $this->source_id,

            'expense_type'       => $this->expense_type,
            'expense_type_label' => ExpenseType::label($this->expense_type),

            'amount'   => (float) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,

            'status'       => $this->status,
            'status_label' => Status::label($this->status),
            'status_tone'  => Status::tone($this->status),

            // code + params + frozen text — see the class docblock.
            'block_reasons' => $this->blockReasons(),
            'validated_at'  => optional($this->validated_at)->toIso8601String(),

            // ── WHO AND WHAT, with the Odoo counterpart of each shown beside it ──────────────────────
            //
            // The mapping is reported LIVE (resolved now) rather than from the event's snapshot, because
            // an unsent event's question is "is this mapped yet?". Once sent, `odoo` below carries the
            // snapshot of what was actually used, and the two are allowed to differ — that difference is
            // exactly what a re-pointed mapping looks like, and hiding it would be the bug.
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'       => $this->vehicle->id,
                'plate_no' => $this->vehicle->plate_no,
                'vin'      => $this->vehicle->vin,
                'analytic_account' => $this->mappingSummary($this->vehicle, \App\Models\OdooMapping::MODEL_ANALYTIC),
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id'      => $this->vendor->id,
                'name'    => $this->vendor->name,
                'partner' => $this->mappingSummary($this->vendor, \App\Models\OdooMapping::MODEL_PARTNER),
            ] : null),
            'maintenance_id' => $this->maintenance_id,

            // The account this cost is charged to — display name plus the id that actually identifies it.
            'expense_account' => $this->expenseAccountSummary(),

            // One word for "are the mappings this event needs all in place?" — what the list view sorts
            // and filters on without having to read every blocking reason.
            'mapping_status'    => $this->mappingStatus(),
            'validation_status' => $this->validationStatus(),

            // What kind of Odoo document this becomes, and what that document demands. The checklist
            // the panel renders is this map plus the block reasons above.
            'document_type'       => $this->odoo_document_type,
            'document_type_label' => OdooDocumentType::label($this->odoo_document_type),
            'requirements'        => $this->documentRequirements(),

            'invoice_number' => $this->resolvedInvoiceNumber(),
            'invoice_date'   => $this->resolvedInvoiceDate(),
            'has_attachment' => $this->resolvedAttachment() !== null,

            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id'          => $line->id,
                'kind'        => $line->kind,
                'description' => $line->description,
                'quantity'    => (float) $line->quantity,
                'uom'         => $line->uom,
                'unit_price'  => (float) $line->unit_price,
                'line_total'  => (float) $line->line_total,
                // Whether THIS line is the one holding the event up, and what it resolves to.
                'needs_product_mapping' => $line->requiresProductMapping(),
                // The SNAPSHOT — what was posted, once it was. Null on an unsent line.
                'odoo_product_id'       => $line->odoo_product_id,
                // The LIVE mapping, so an unsent line can say whether its part is mapped yet.
                'product' => $line->requiresProductMapping()
                    ? $this->mappingSummary($line->catalogPart, \App\Models\OdooMapping::MODEL_PRODUCT)
                    : null,
            ])->values()),

            // ── What Odoo knows ───────────────────────────────────────────────────────────────────
            'odoo' => [
                'document_id'        => $this->odoo_document_id,
                'document_model'     => $this->odoo_document_model,
                'document_reference' => $this->odoo_document_reference,
                'synced_at'          => optional($this->synced_at)->toIso8601String(),
                // Null unless a URL template is configured — §41: never invent a link.
                'url'                => $this->odooDocumentUrl(),
            ],

            'failure' => $this->failure_code ? [
                'code'    => $this->failure_code,
                'message' => $this->failure_reason,
            ] : null,
            'attempts'        => (int) $this->attempts,
            'last_attempt_at' => optional($this->last_attempt_at)->toIso8601String(),

            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'cancelled_at'        => optional($this->cancelled_at)->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'actions' => $this->actionsFor($can),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /**
     * One record's Odoo counterpart, as the screen needs to see it.
     *
     * `state` is the six-way answer from OdooMapping::displayState() — mapped / changed / suggested /
     * stale / none / unmapped — and `usable` is the only one the validator cares about. Both are sent
     * because they answer different questions: `usable` says whether this can post, `state` says why
     * not, or (for `changed`) that it can post but has been re-pointed since documents were made.
     *
     * @return array{state:string, usable:bool, odoo_id:?int, odoo_name:?string, odoo_ref:?string,
     *               matched_by:?string, confirmed_at:?string, changed_at:?string, change_count:int}
     */
    private function mappingSummary(?\Illuminate\Database\Eloquent\Model $record, string $odooModel): array
    {
        $absent = [
            'state' => 'unmapped', 'usable' => false, 'odoo_id' => null, 'odoo_name' => null,
            'odoo_ref' => null, 'matched_by' => null, 'confirmed_at' => null,
            'changed_at' => null, 'change_count' => 0,
        ];

        if (! $record || ! method_exists($record, 'odooMappingFor')) {
            return $absent;
        }

        $mapping = $record->odooMappingFor($odooModel);

        if (! $mapping) {
            return $absent;
        }

        return [
            'state'        => $mapping->displayState(),
            'usable'       => $mapping->isUsable(),
            'odoo_id'      => $mapping->odoo_id,
            'odoo_name'    => $mapping->odoo_name,
            'odoo_ref'     => $mapping->odoo_ref,
            'matched_by'   => $mapping->matched_by,
            'confirmed_at' => optional($mapping->mapped_at)->toIso8601String(),
            'changed_at'   => optional($mapping->changed_at)->toIso8601String(),
            'change_count' => (int) $mapping->change_count,
        ];
    }

    /**
     * The expense account, named and identified.
     *
     * The NAME is display only and the id is the identifier (§2) — both are sent so the panel can show
     * a human-readable account without the client ever being tempted to match on the string.
     */
    private function expenseAccountSummary(): array
    {
        $mapping = \App\Models\ExpenseTypeMapping::where('expense_type', $this->expense_type)->first();

        return [
            // Once sent, the SNAPSHOT of what was actually used; before that, what is configured now.
            'odoo_account_id' => $this->odoo_account_id ?: $mapping?->odoo_account_id,
            'code'            => $mapping?->odoo_account_code,
            'name'            => $mapping?->odoo_account_name,
            'resolved'        => (bool) ($this->odoo_account_id ?: $mapping?->hasAccount()),
            'journal_id'      => $this->odoo_journal_id ?: $mapping?->odoo_journal_id,
        ];
    }

    /**
     * "Are the mappings this event needs all in place?" — derived from the blocking reasons rather than
     * re-resolved, so it can never disagree with what the validator just said.
     */
    private function mappingStatus(): string
    {
        $codes = array_map(fn ($r) => $r['code'] ?? null, $this->blockReasons());

        $mappingCodes = array_intersect($codes, [
            'vehicle_analytic_account_missing', 'product_not_mapped', 'supplier_not_mapped',
            'expense_account_unresolved', 'document_type_unresolved',
        ]);

        if ($mappingCodes !== []) {
            return 'incomplete';
        }

        // A never-validated event has not been checked, which is not the same as being complete.
        return $this->validated_at ? 'complete' : 'unchecked';
    }

    /** Whether validation has run, and what it concluded. */
    private function validationStatus(): string
    {
        if (! $this->validated_at) {
            return 'unchecked';
        }

        return $this->blockReasons() === [] ? 'passed' : 'failed';
    }

    /**
     * The actions this user may take on this event RIGHT NOW.
     *
     * @param  callable(string):bool  $can
     * @return list<array{key:string, label:string, permission:string, primary:bool, danger:bool}>
     */
    private function actionsFor(callable $can): array
    {
        $out = [];

        // Re-checking is always safe and always useful — it is how somebody confirms a mapping they
        // just created has unblocked this event.
        if (! $this->isTerminal() && $can('financial.view')) {
            $out[] = ['key' => 'validate', 'label' => 'Validate financial data',
                'permission' => 'financial.view', 'primary' => false, 'danger' => false];
        }

        if ($this->status === Status::READY && $can('financial.sync')) {
            $out[] = ['key' => 'sync', 'label' => 'Send to Odoo',
                'permission' => 'financial.sync', 'primary' => true, 'danger' => false];
        }

        if ($this->status === Status::FAILED && $can('financial.retry')) {
            $out[] = ['key' => 'retry', 'label' => 'Retry sync',
                'permission' => 'financial.retry', 'primary' => true, 'danger' => false];
        }

        // A stranded in-flight event is resolved by ASKING Odoo, never by sending again — which is why
        // this is offered as its own action with its own words rather than as a second Send button.
        if ($this->status === Status::SENDING && $can('financial.retry')) {
            $out[] = ['key' => 'reconcile', 'label' => 'Check with Odoo',
                'permission' => 'financial.retry', 'primary' => true, 'danger' => false];
        }

        if (! $this->approved_at && ! $this->isTerminal() && $can('financial.approve')) {
            $out[] = ['key' => 'approve', 'label' => 'Approve',
                'permission' => 'financial.approve', 'primary' => false, 'danger' => false];
        }

        if (Status::canMove($this->status, Status::CANCELLED) && $can('financial.approve')) {
            $out[] = ['key' => 'cancel', 'label' => 'Cancel',
                'permission' => 'financial.approve', 'primary' => false, 'danger' => true];
        }

        return $out;
    }
}
