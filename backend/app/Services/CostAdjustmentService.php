<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recording a correction to a ticket's cost as a DOCUMENT rather than as an edit.
 *
 * Every other way money reaches a ticket comes with paper. This is the path for money that doesn't — and
 * it deliberately makes that path narrow: a reason code, a written explanation and a named approver are
 * all mandatory, and the result is a permanent row that can be read back years later, not a silently
 * changed number.
 *
 * It writes to the same single ledger as everything else (a maintenance_line_items row), so the ticket
 * total, the fault's cost, the vehicle's TCO and every spend report absorb it with no special-casing.
 *
 * The band matters: `applies_to` decides which kind of line is written, and therefore which of the
 * ticket's totals moves. A part return may only ever credit PARTS; the sole way labour comes down is an
 * adjustment recorded here with applies_to = labour, by someone willing to sign for it.
 */
class CostAdjustmentService
{
    public function __construct(
        private VehicleLogService $log,
        private IncorrectFaultCostGuard $incorrect,
    ) {}

    /**
     * Record an adjustment and put its money on the ticket.
     *
     * @param array{applies_to:string, direction:string, amount:float, reason_code:string,
     *              reason_note:string, maintenance_task_id?:?int, vendor_id?:?int, reference?:?string} $data
     */
    public function create(Maintenance $ticket, array $data, User $actor, ?UploadedFile $photo = null): CostAdjustment
    {
        $amount = round(abs((float) $data['amount']), 2);
        if ($amount <= 0) {
            throw new WorkflowTransitionException('An adjustment needs an amount.', ['field' => 'amount']);
        }

        $task = ! empty($data['maintenance_task_id']) ? MaintenanceTask::find($data['maintenance_task_id']) : null;
        if ($task && $task->maintenance_id !== $ticket->id) {
            throw new WorkflowTransitionException(
                'That fault does not belong to this ticket.',
                ['field' => 'maintenance_task_id'],
            );
        }
        // A DEBIT adds cost, so it obeys the incorrect-fault gate exactly like an invoice line would.
        // A CREDIT takes cost away and is always allowed — correcting money spent on a wrong diagnosis is
        // precisely the sort of thing that must stay possible.
        if (($data['direction'] ?? null) === CostAdjustment::DIRECTION_DEBIT) {
            $this->incorrect->assertNotIncorrect($task, 'charged more cost by an adjustment');
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $photo, $amount, $task) {
            $adjustment = CostAdjustment::create([
                'maintenance_id'      => $ticket->id,
                'maintenance_task_id' => $task?->id,
                'vehicle_id'          => $ticket->vehicle_id,
                'applies_to'          => $data['applies_to'],
                'direction'           => $data['direction'],
                'amount'              => $amount,
                'currency'            => 'AED',
                'reason_code'         => $data['reason_code'],
                'reason_note'         => trim((string) $data['reason_note']),
                'reference'           => $this->clean($data['reference'] ?? null),
                'vendor_id'           => $data['vendor_id'] ?? null,
                // The approver IS the actor: the API gates this on maintenance.manage, so whoever can
                // record an adjustment is by definition someone entitled to approve one.
                'approved_by'         => $actor->id,
                'approved_by_name'    => $actor->name ?: $actor->email,
                'approved_at'         => Carbon::now(),
                'created_by'          => $actor->id,
            ]);

            if ($photo) {
                [$disk, $key] = $this->storePhoto($adjustment, $photo);
                $adjustment->forceFill(['photo_disk' => $disk, 'photo_key' => $key])->save();
            }

            $line = $this->writeLine($ticket, $adjustment, $actor);
            $adjustment->forceFill(['line_item_id' => $line->id])->save();

            $this->logTimeline($ticket, $adjustment, $actor);

            return $adjustment->fresh(['lineItem', 'vendor']);
        });
    }

    /**
     * Reverse an adjustment. The record stays — it happened, and an audit trail that can be emptied is not
     * one — but its money is removed from the ticket by deleting the line it wrote.
     */
    public function reverse(CostAdjustment $adjustment, User $actor, string $reason): CostAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $reason) {
            if ($adjustment->line_item_id) {
                // Delete the MODEL so the `deleted` hook rolls the cost back up the chain; a
                // query-builder delete fires no events and would leave the ticket total stale.
                optional(MaintenanceLineItem::find($adjustment->line_item_id))->delete();
            }

            $adjustment->forceFill([
                'line_item_id' => null,
                'reason_note'  => $adjustment->reason_note . "\n\nREVERSED by {$actor->name}: " . trim($reason),
            ])->save();

            $ticket = $adjustment->maintenance;
            if ($ticket) {
                $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
                    'source_tag'  => 'parts',
                    'description' => 'Cost adjustment reversed (' . $adjustment->reasonLabel() . ', AED '
                        . number_format((float) $adjustment->amount, 2) . ") — {$reason} (by {$actor->name})",
                    'meta' => ['cost_adjustment_id' => $adjustment->id, 'reversed' => true],
                ]);
            }

            return $adjustment->fresh();
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Put the adjustment's money on the ledger.
     *
     * The band decides the line's KIND, which is what makes the ticket's parts / labour split come out
     * right: a labour refund writes a negative LABOR line (so labour falls), a parts correction writes a
     * PART line, and anything else writes an ADJUSTMENT line that sits outside both bands and moves only
     * the net total.
     */
    private function writeLine(Maintenance $ticket, CostAdjustment $adjustment, User $actor): MaintenanceLineItem
    {
        $kind = match ($adjustment->applies_to) {
            CostAdjustment::APPLIES_PARTS  => MaintenanceLineItem::KIND_PART,
            CostAdjustment::APPLIES_LABOUR => MaintenanceLineItem::KIND_LABOR,
            default                        => MaintenanceLineItem::KIND_ADJUSTMENT,
        };

        $signed = $adjustment->signedAmount();

        return MaintenanceLineItem::create([
            'maintenance_id'      => $ticket->id,
            'maintenance_task_id' => $adjustment->maintenance_task_id,
            'vehicle_id'          => $ticket->vehicle_id,
            'kind'                => $kind,
            // Diagnosis-First when the adjustment names a fault; a ticket-wide correction names none.
            'finding_text'        => $adjustment->task?->symptom,
            'description'         => ($signed < 0 ? 'Credit: ' : 'Charge: ') . $adjustment->reasonLabel(),
            'quantity'            => 1,
            'uom'                 => 'unit',
            'unit_price'          => $signed,   // line_total is derived on save
            'created_by'          => $actor->id,
            'entry_source'        => 'adjust',  // origin tag; entry_source is varchar(12)
            // Structured origin: the adjustment record itself is the document, and it carries the
            // reason, the explanation and the approver that make this amount auditable.
            'source_type'         => CostSourceResolver::SOURCE_ADJUSTMENT,
            'source_id'           => $adjustment->id,
        ]);
    }

    private function logTimeline(Maintenance $ticket, CostAdjustment $adjustment, User $actor): void
    {
        $signed = $adjustment->signedAmount();

        $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
            'source_tag'  => 'parts',
            'description' => 'Cost adjustment · ' . $adjustment->reasonLabel() . ' · '
                . ($signed < 0 ? '−' : '+') . ' AED ' . number_format(abs($signed), 2)
                . ' on ' . $adjustment->applies_to
                . ' — ' . $adjustment->reason_note
                . ' (approved by ' . $adjustment->approved_by_name . ')',
            'meta' => [
                'cost_adjustment_id' => $adjustment->id,
                'applies_to'         => $adjustment->applies_to,
                'direction'          => $adjustment->direction,
                'amount'             => (float) $adjustment->amount,
                'reason_code'        => $adjustment->reason_code,
            ],
        ]);
    }

    private function storePhoto(CostAdjustment $adjustment, UploadedFile $file): array
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $key  = $file->storeAs("cost-adjustments/ticket-{$adjustment->maintenance_id}", (string) Str::uuid() . '.' . $ext, $disk);

        return $key ? [$disk, $key] : [null, null];
    }

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
