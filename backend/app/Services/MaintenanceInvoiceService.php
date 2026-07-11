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
    ) {}

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
            $this->applyReceiptGate($invoice, $data);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($invoice, $photo);
                $invoice->receipt_photo_disk = $disk;
                $invoice->receipt_photo_key  = $key;
            }
            $invoice->save();

            $this->logAndNotify($ticket, $invoice, $actor, 'created');

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
            $this->applyReceiptGate($invoice, $data);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($invoice, $photo);
                $invoice->receipt_photo_disk = $disk;
                $invoice->receipt_photo_key  = $key;
            }
            $invoice->save();

            $this->logAndNotify($ticket, $invoice, $actor, 'updated');

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
        }

        // Map a finding text → the covered fault it names, so a line also feeds per-fault cost (best-effort).
        $taskBySymptom = $invoice->tasks()->get()
            ->keyBy(fn (MaintenanceTask $t) => mb_strtolower(trim((string) $t->symptom)));

        $invoice->lineItems()->delete();

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

            $invoice->lineItems()->create([
                'maintenance_id'     => $ticket->id,
                'maintenance_task_id'=> optional($taskBySymptom->get(mb_strtolower((string) $finding)))->id,
                'vehicle_id'         => $ticket->vehicle_id,
                'kind'               => $kind,
                'finding_text'       => $finding,
                'category_key'       => $this->clean($row['category_key'] ?? null),
                'description'        => $description,
                'part_number'        => $isPart ? $this->clean($row['part_number'] ?? null) : null,
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
            ]);
        }

        // Re-derive this invoice's own total from the freshly written lines (bubbles up to the ticket).
        $invoice->load('lineItems');
        $invoice->recalcTotals();
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

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
