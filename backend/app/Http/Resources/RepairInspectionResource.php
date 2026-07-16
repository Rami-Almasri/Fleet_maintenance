<?php

namespace App\Http\Resources;

use App\Models\RepairInspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post-repair inspection verdict, shaped for the ticket drawer's Repair Quality Check panel and
 * the vehicle repair-quality history. Carries the human labels so the frontend renders the "REPAIR
 * FAILED" card (fault, previous repair garage + date, reason, note) without re-deriving anything.
 */
class RepairInspectionResource extends JsonResource
{
    private const RESULT_LABELS = [
        RepairInspection::RESULT_FIXED        => 'Fixed successfully',
        RepairInspection::RESULT_STILL_EXISTS => 'Problem still exists',
        RepairInspection::RESULT_NEW_ISSUE    => 'New problem found',
    ];

    private const REASON_LABELS = [
        RepairInspection::REASON_WRONG_DIAGNOSIS   => 'Wrong diagnosis',
        RepairInspection::REASON_PART_FAILED       => 'Part failed',
        RepairInspection::REASON_REPAIR_INCOMPLETE => 'Repair incomplete',
        RepairInspection::REASON_WRONG_PART        => 'Wrong part',
        RepairInspection::REASON_CUSTOMER_COMPLAINT => 'Customer complaint',
        RepairInspection::REASON_UNKNOWN           => 'Unknown',
    ];

    public function toArray(Request $request): array
    {
        /** @var RepairInspection $i */
        $i = $this->resource;

        return [
            'id'             => $i->id,
            'maintenance_id' => $i->maintenance_id,
            'vehicle_id'     => $i->vehicle_id,

            'result'        => $i->result,
            'result_label'  => self::RESULT_LABELS[$i->result] ?? $i->result,
            'is_fixed'      => $i->result === RepairInspection::RESULT_FIXED,
            'is_failure'    => $i->result === RepairInspection::RESULT_STILL_EXISTS,
            'is_new_issue'  => $i->result === RepairInspection::RESULT_NEW_ISSUE,

            'failure_reason'       => $i->failure_reason,
            'failure_reason_label' => $i->failure_reason ? (self::REASON_LABELS[$i->failure_reason] ?? $i->failure_reason) : null,
            'notes'                => $i->notes,

            // The fault this verdict is about (+ the new fault, for a new_issue).
            'fault_id'        => $i->fault_id,
            'fault_symptom'   => $i->relationLoaded('fault') ? $i->fault?->symptom : null,
            'new_fault_id'    => $i->new_fault_id,
            'new_fault_symptom' => $i->relationLoaded('newFault') ? $i->newFault?->symptom : null,

            // The repair being judged — drives the "REPAIR FAILED" card.
            'previous_garage'      => $i->relationLoaded('previousVendor') ? $i->previousVendor?->name : null,
            'previous_vendor_id'   => $i->previous_vendor_id,
            'previous_repaired_at' => optional($i->previous_repaired_at)->toDateString(),
            'days_since_repair'    => $i->days_since_repair,
            'is_recurrence'        => (bool) $i->is_recurrence,

            'inspector_name'  => $i->relationLoaded('inspector') ? $i->inspector?->name : null,
            'inspection_date' => optional($i->inspection_date)->toIso8601String(),
        ];
    }
}
