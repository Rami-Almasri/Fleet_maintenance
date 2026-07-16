<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreInspectionRequest;
use App\Http\Resources\InspectionRecordResource;
use App\Models\InspectionRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Vehicle Inspection Workflow API.
 *
 * Upload flow (local storage):
 *   1. client compresses the photo in-browser, then
 *   2. POST /Inspections  (multipart) → server stores the image on the local `public`
 *      disk and persists the metadata row (+ optional damage flag).
 *
 * Reads (GET) hand back records with a public `/storage/...` URL per photo.
 */
class InspectionController extends Controller
{
    /** List inspection records for a contract (or vehicle), newest first. */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'contract_id' => 'nullable|exists:contracts,id',
                'vehicle_id'  => 'nullable|exists:vehicles,id',
            ]);

            $records = InspectionRecord::query()
                ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
                ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(500)
                ->get();

            return ResponseHelper::SuccessResponse(
                InspectionRecordResource::collection($records),
                'Inspection records retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Persist one inspection record: an optional condition photo (uploaded multipart and
     * stored on the local `public` disk) and/or a manual damage finding.
     */
    public function store(StoreInspectionRequest $request)
    {
        try {
            $data = $request->validated();
            unset($data['photo']); // handled below, not a model attribute

            // Local direct upload: store the attached image on the `public` disk and
            // record the pointer in the (legacy-named) s3_disk / s3_key columns.
            if ($request->hasFile('photo')) {
                $file     = $request->file('photo');
                $contract = $data['contract_id'] ?? 'misc';
                $ext      = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg'));
                $key      = $file->storeAs(
                    sprintf('inspections/%s/%s/%s', $contract, $data['phase'], $data['body_part']),
                    (string) Str::uuid().'.'.$ext,
                    'public'
                );
                if (! $key) {
                    return ResponseHelper::FailureResponse(null, 'The photo could not be stored.', 500);
                }
                $data['s3_disk']   = 'public';
                $data['s3_key']    = $key;
                $data['mime_type'] = $data['mime_type'] ?? $file->getClientMimeType();
                $data['file_size'] = $data['file_size'] ?? $file->getSize();
            }

            $hasPhoto = ! empty($data['s3_key']);
            $flagged  = (bool) ($data['damage_flagged'] ?? false);

            if (! $hasPhoto && ! $flagged) {
                return ResponseHelper::FailureResponse(null, 'An inspection record needs either a photo or a damage flag.', 422);
            }
            if ($flagged && empty($data['damage_type'])) {
                return ResponseHelper::FailureResponse(null, 'A flagged damage needs a type (scratch, dent, glass_crack or other).', 422);
            }

            $user = $request->user();
            $record = InspectionRecord::create([
                ...$data,
                's3_disk'        => $data['s3_disk'] ?? 'public',
                'damage_flagged' => $flagged,
                'inspector_id'   => $user?->id,
                'inspector_name' => $user?->name,
                'captured_at'    => $data['captured_at'] ?? now(),
            ]);

            return ResponseHelper::SuccessResponse(
                InspectionRecordResource::make($record),
                'Inspection record saved successfully',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Delete a record and its stored image (if any). */
    public function destroy(InspectionRecord $inspection)
    {
        try {
            if ($inspection->s3_key) {
                try {
                    Storage::disk($inspection->s3_disk ?: 'public')->delete($inspection->s3_key);
                } catch (\Throwable $e) {
                    // best-effort: still remove the DB row even if the object is already gone
                }
            }
            $inspection->delete();

            return ResponseHelper::SuccessResponse(null, 'Inspection record deleted successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
