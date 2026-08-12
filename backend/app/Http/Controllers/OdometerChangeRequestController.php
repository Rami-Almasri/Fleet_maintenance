<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\OdometerChangeRequest;
use App\Models\Vehicle;
use App\Services\VehicleLogService;
use Illuminate\Http\Request;

/**
 * Odometer Modification Approval queue — the admin side of the significant-odometer-change guard.
 *
 * Requests are RAISED by VehicleController::update (when an edit moves the odometer more than
 * OdometerChangeRequest::SIGNIFICANT_DELTA_KM). This controller only lists them and lets an admin
 * approve (write the reading onto the car) or reject (discard) each one. Every decision is stamped
 * with who / when so the queue doubles as an audit trail of manual odometer corrections.
 */
class OdometerChangeRequestController extends Controller
{
    /**
     * The review board: everything still pending (oldest first — first in, first reviewed), plus a
     * short tail of recently-decided requests so the reviewer can see what just happened.
     */
    public function index(Request $request)
    {
        try {
            $pending = OdometerChangeRequest::with('vehicle')
                ->where('status', OdometerChangeRequest::STATUS_PENDING)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($r) => $this->present($r));

            $recent = OdometerChangeRequest::with('vehicle')
                ->whereIn('status', [OdometerChangeRequest::STATUS_APPROVED, OdometerChangeRequest::STATUS_REJECTED])
                ->orderByDesc('reviewed_at')
                ->limit(50)
                ->get()
                ->map(fn ($r) => $this->present($r));

            return ResponseHelper::SuccessResponse([
                'pending'   => $pending,
                'recent'    => $recent,
                'threshold' => OdometerChangeRequest::SIGNIFICANT_DELTA_KM,
            ], 'Odometer change requests retrieved successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The per-stage odometer trail. For every maintenance ticket that captured at least one stage
     * reading (test drive, pickup, garage intake, return, re-inspection …), lay the readings out in
     * lifecycle order so a reviewer can watch the car's odometer move through each stage — and spot
     * where a reading jumped or ran backwards between stages.
     */
    public function stageReadings(Request $request)
    {
        try {
            $cols = array_keys(self::STAGE_LABELS);

            $tickets = \App\Models\Maintenance::with('vehicle')
                ->where(function ($q) use ($cols) {
                    foreach ($cols as $c) {
                        $q->orWhereNotNull($c);
                    }
                })
                ->orderByDesc('updated_at')
                ->limit(200)
                ->get()
                ->map(fn ($t) => $this->presentStages($t));

            return ResponseHelper::SuccessResponse(
                ['tickets' => $tickets],
                'Stage odometer readings retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Stage columns in the order the car physically passes through them, mapped to a human label.
     * Keep this ordered — the UI renders the trail left-to-right / top-to-bottom exactly like this.
     */
    private const STAGE_LABELS = [
        'test_odometer'      => 'Inspection Test Drive',  // inspector's start-of-drive reading (Decide step)
        'report_odometer'    => 'Breakdown Report',       // reading logged when a breakdown is reported
        'intake_odometer'    => 'Pickup Intake',          // reading when a pickup ticket is opened
        'dispatch_odometer'  => 'Dispatch to Garage',     // driver's pickup reading, car leaves base
        'receive_odometer'   => 'At Garage',              // garage check-in / arrival reading
        'return_odometer'    => 'Garage Return',          // car leaves the garage (return leg)
        'reinspect_odometer' => 'Re-Inspection',          // closing re-inspection reading
    ];

    /** Flatten one ticket into its ordered stage-odometer trail (only the stages that were captured). */
    private function presentStages(\App\Models\Maintenance $t): array
    {
        $v = $t->vehicle;

        // Emit EVERY workflow stage — even the ones this ticket never captured — so the trail always
        // shows the full lifecycle. Un-captured stages come back with reading=null (rendered as an empty
        // slot); the delta is measured against the last stage that DID have a reading, so gaps don't break
        // the chain.
        $stages = [];
        $prev   = null;
        foreach (self::STAGE_LABELS as $col => $label) {
            $raw     = $t->{$col};
            $reading = $raw === null ? null : (int) $raw;
            $stages[] = [
                'key'      => $col,
                'label'    => $label,
                'captured' => $reading !== null,
                'reading'  => $reading,
                // Movement since the previous CAPTURED stage (null when this stage is empty or is the first reading).
                'delta'    => ($reading === null || $prev === null) ? null : $reading - $prev,
            ];
            if ($reading !== null) {
                $prev = $reading;
            }
        }

        return [
            'id'                   => $t->id,
            'vehicle_id'           => $t->vehicle_id,
            'plate_no'             => $v?->plate_no,
            'vin'                  => $v?->vin,
            'make'                 => $v?->make,
            'model'                => $v?->model,
            'current_odometer'     => $v?->odometer,
            'workflow_status'      => $t->workflow_status,
            'issue'                => $t->trigger_reason ?? $t->maintenance_notes ?? null,
            'stages'               => $stages,
            'flags'                => $t->odometer_flags,
            'updated_at'           => optional($t->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Approve a pending request: write the requested reading straight onto the vehicle (an admin sign-off
     * deliberately overrides the usual forward-only heal — the whole point is to allow a corrected value,
     * up OR down) and stamp the decision.
     */
    public function approve(Request $request, OdometerChangeRequest $odometerRequest)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

            if ($odometerRequest->status !== OdometerChangeRequest::STATUS_PENDING) {
                return ResponseHelper::FailureResponse(null, 'This request has already been reviewed.', 409);
            }

            $vehicle = $odometerRequest->vehicle;
            $before  = $vehicle?->odometer;

            if ($vehicle) {
                $vehicle->update([
                    'odometer'           => $odometerRequest->requested_odometer,
                    'odometer_source'    => 'manual',
                    'odometer_source_at' => now(),
                ]);

                // Leave a trace on the car's own event trail so the correction shows up in its history,
                // not only in this queue. Best-effort — never blocks the approval.
                try {
                    app(VehicleLogService::class)->recordVehicle(
                        $vehicle,
                        \App\Models\VehicleLogEvent::EVENT_ODOMETER_CORRECTED,
                        $request->user(),
                        [
                            'description' => 'Odometer corrected ' . ($before ?? '—') . ' → ' . $odometerRequest->requested_odometer . ' km (approved)',
                            'meta'        => [
                                'from'    => $before,
                                'to'      => $odometerRequest->requested_odometer,
                                'reason'  => $odometerRequest->note,
                                'request' => $odometerRequest->id,
                            ],
                        ],
                    );
                } catch (\Throwable $e) {
                    // event-log is a nice-to-have; the correction itself already landed.
                }
            }

            $odometerRequest->update([
                'status'         => OdometerChangeRequest::STATUS_APPROVED,
                'reviewed_by_id' => optional($request->user())->id,
                'reviewed_by'    => optional($request->user())->name,
                'reviewed_at'    => now(),
                'review_note'    => $data['note'] ?? null,
            ]);

            return ResponseHelper::SuccessResponse(
                $this->present($odometerRequest->fresh('vehicle')),
                'Odometer change approved and applied',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Reject a pending request: the vehicle's odometer is left exactly as it was. */
    public function reject(Request $request, OdometerChangeRequest $odometerRequest)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

            if ($odometerRequest->status !== OdometerChangeRequest::STATUS_PENDING) {
                return ResponseHelper::FailureResponse(null, 'This request has already been reviewed.', 409);
            }

            $odometerRequest->update([
                'status'         => OdometerChangeRequest::STATUS_REJECTED,
                'reviewed_by_id' => optional($request->user())->id,
                'reviewed_by'    => optional($request->user())->name,
                'reviewed_at'    => now(),
                'review_note'    => $data['note'] ?? null,
            ]);

            return ResponseHelper::SuccessResponse(
                $this->present($odometerRequest->fresh('vehicle')),
                'Odometer change rejected',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Flatten one request into the shape the review board renders. */
    private function present(OdometerChangeRequest $r): array
    {
        $v = $r->vehicle;

        return [
            'id'                 => $r->id,
            'vehicle_id'         => $r->vehicle_id,
            'plate_no'           => $v?->plate_no,
            'vin'                => $v?->vin,
            'make'               => $v?->make,
            'model'              => $v?->model,
            'previous_odometer'  => $r->previous_odometer,
            'requested_odometer' => $r->requested_odometer,
            'delta'              => $r->delta,
            'note'               => $r->note,
            // The snapshot taken at request time, plus the car's stage RIGHT NOW for comparison.
            'workflow_stage'       => $r->workflow_stage,
            'workflow_stage_label' => Vehicle::OPERATIONAL_LABELS[$r->workflow_stage] ?? ($r->workflow_stage ? ucfirst($r->workflow_stage) : 'Available'),
            'current_stage'        => $v?->operational_status,
            'current_stage_label'  => Vehicle::OPERATIONAL_LABELS[$v?->operational_status] ?? 'Available',
            'status'             => $r->status,
            'requested_by'       => $r->requested_by,
            'reviewed_by'        => $r->reviewed_by,
            'reviewed_at'        => optional($r->reviewed_at)->toIso8601String(),
            'review_note'        => $r->review_note,
            'created_at'         => optional($r->created_at)->toIso8601String(),
        ];
    }
}
