<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\InspectionRecord;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\VehicleLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle Cleaning — the "clean the car before handover" prep flow that hangs off the Booking
 * Readiness checklist's Cleaning point. Lets the prep crew capture BEFORE and AFTER photos of a
 * dirty car, then mark it clean so the readiness blocker clears.
 *
 * Storage reuses the existing `inspection_records` table (the same vehicle-anchored photo trail the
 * inspection + odometer flows use) tagged with body_part = 'cleaning' and phase = 'before' | 'after',
 * so there is NO new table to migrate. Bytes go to S3 when configured, else the local `public` disk,
 * so it works on a demo machine with no AWS env — mirroring LogisticsDispatchController::storeOdometerPhoto.
 *
 * The clean / dirty flag itself lives on vehicles.cleaning_status; setStatus() flips it and audits the
 * change on the same VehicleLogEvent trail the readiness gate writes to.
 */
class CleaningController extends Controller
{
    /** The two cleaning phases. Kept distinct from inspection's pre/post so the two flows never mix. */
    public const PHASES = ['before', 'after'];

    /** How this flow tags its rows in the shared inspection_records table. */
    private const BODY_PART = 'cleaning';

    public function __construct(private VehicleLogService $log)
    {
    }

    /** Everything the Cleaning page needs for one car: its before/after photos + current status. */
    public function show(Vehicle $vehicle)
    {
        try {
            return ResponseHelper::SuccessResponse($this->payload($vehicle), 'Cleaning record retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Store one cleaning photo (multipart) for a car, tagged before/after. The browser compresses the
     * image first, so this receives a small JPEG; we still cap at 8 MB as a guard.
     */
    public function store(Vehicle $vehicle, Request $request)
    {
        try {
            $data = $request->validate([
                'phase' => 'required|in:before,after',
                'photo' => 'required|image|max:8192',
                'note'  => 'nullable|string|max:500',
            ]);

            $file = $request->file('photo');
            $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $dir  = "cleaning/vehicle-{$vehicle->id}/{$data['phase']}";

            // Snapshot metadata BEFORE storeAs moves the temp file.
            $mime = $file->getMimeType();
            $size = $file->getSize();
            [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

            $key = $file->storeAs($dir, (string) Str::uuid() . '.' . $ext, $disk);
            if (! $key) {
                return ResponseHelper::FailureResponse(null, 'Could not store the photo. Check the storage disk is writable.', 500);
            }

            $user = $request->user();
            InspectionRecord::create([
                'vehicle_id'      => $vehicle->id,
                'phase'           => $data['phase'],
                'body_part'       => self::BODY_PART,
                'checkpoint_type' => self::BODY_PART,
                's3_disk'         => $disk,
                's3_key'          => $key,
                'mime_type'       => $mime,
                'file_size'       => $size,
                'width'           => $width ?: null,
                'height'          => $height ?: null,
                'note'            => $data['note'] ?? null,
                'inspector_id'    => $user?->id,
                'inspector_name'  => $user?->name,
                'captured_at'     => now(),
            ]);

            return ResponseHelper::SuccessResponse($this->payload($vehicle), 'Cleaning photo saved', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Delete one cleaning photo (and its stored object). Guarded so only cleaning rows are removable here. */
    public function destroy(Vehicle $vehicle, InspectionRecord $photo)
    {
        try {
            if ($photo->vehicle_id !== $vehicle->id || $photo->body_part !== self::BODY_PART) {
                return ResponseHelper::FailureResponse(null, 'That photo does not belong to this vehicle’s cleaning record.', 404);
            }

            if ($photo->s3_key) {
                try {
                    Storage::disk($photo->s3_disk ?: 's3')->delete($photo->s3_key);
                } catch (\Throwable $e) {
                    // best-effort: still drop the row even if the object is already gone
                }
            }
            $photo->delete();

            return ResponseHelper::SuccessResponse($this->payload($vehicle), 'Cleaning photo deleted', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Flip the car's cleaning_status (clean / dirty / pending) and audit it on the vehicle log trail —
     * the same signal ContractEligibilityService::cleaningCheck reads, so a "clean" here clears the
     * Booking Readiness Cleaning blocker. Mirrors ReadinessController::setChecklistField.
     */
    public function setStatus(Vehicle $vehicle, Request $request)
    {
        try {
            $data = $request->validate([
                'status' => 'required|in:clean,dirty,pending',
            ]);

            $previous = $vehicle->cleaning_status;
            $vehicle->update(['cleaning_status' => $data['status']]);

            if ($previous !== $data['status']) {
                $this->log->recordVehicle(
                    $vehicle,
                    VehicleLogEvent::EVENT_CLEANING_UPDATED,
                    $request->user(),
                    [
                        'description' => 'Cleaning ' . ($previous ?: 'unset') . ' → ' . $data['status'],
                        'meta'        => ['field' => 'cleaning_status', 'from' => $previous, 'to' => $data['status']],
                    ],
                );
            }

            return ResponseHelper::SuccessResponse($this->payload($vehicle), 'Cleaning status updated', 200);
        } catch (ValidationException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The car's cleaning state + its before/after photo lists, viewable-URL enriched. */
    private function payload(Vehicle $vehicle): array
    {
        $records = InspectionRecord::where('vehicle_id', $vehicle->id)
            ->where('body_part', self::BODY_PART)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        $shape = fn (InspectionRecord $r) => [
            'id'          => $r->id,
            'phase'       => $r->phase,
            'url'         => $r->viewUrl(),
            'note'        => $r->note,
            'file_size'   => $r->file_size,
            'captured_at' => optional($r->captured_at)->toIso8601String(),
            'captured_by' => $r->inspector_name,
        ];

        return [
            'vehicle' => [
                'id'              => $vehicle->id,
                'plate_no'        => $vehicle->plate_no,
                'label'           => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ($vehicle->plate_no ?: 'Vehicle'),
                'cleaning_status' => $vehicle->cleaning_status,
            ],
            'before' => $records->where('phase', 'before')->map($shape)->values(),
            'after'  => $records->where('phase', 'after')->map($shape)->values(),
        ];
    }
}
