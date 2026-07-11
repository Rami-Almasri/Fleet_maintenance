<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\PresignInspectionRequest;
use App\Http\Requests\StoreInspectionRequest;
use App\Http\Resources\InspectionRecordResource;
use App\Models\InspectionRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Vehicle Inspection Workflow API.
 *
 * Upload flow keeps image bytes off the app server entirely:
 *   1. client compresses the photo in-browser, then
 *   2. POST /Inspections/presign  → server returns a short-lived signed S3 PUT URL,
 *   3. client PUTs the blob straight to S3, then
 *   4. POST /Inspections          → server persists the metadata row (+ damage flag).
 *
 * Reads (GET) hand back records with a short-lived signed GET URL per photo, so the
 * bucket itself can stay private.
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
     * Hand back a presigned S3 PUT URL so the browser can upload one compressed
     * photo directly. The object key namespaces by contract/phase/zone for tidy
     * buckets and easy lifecycle rules.
     */
    public function presign(PresignInspectionRequest $request)
    {
        try {
            $data = $request->validated();

            $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $data['extension'] ?? 'jpg')) ?: 'jpg';
            $contract = $data['contract_id'] ?? 'misc';
            $key = sprintf(
                'inspections/%s/%s/%s/%s.%s',
                $contract,
                $data['phase'],
                $data['body_part'],
                (string) Str::uuid(),
                $ext
            );

            $disk = 's3';
            // temporaryUploadUrl needs the flysystem S3 adapter (league/flysystem-aws-s3-v3).
            $signed = Storage::disk($disk)->temporaryUploadUrl(
                $key,
                now()->addMinutes(10),
                ['ContentType' => $data['content_type']]
            );

            return ResponseHelper::SuccessResponse([
                'disk'       => $disk,
                'key'        => $key,
                'upload_url' => $signed['url'],
                'headers'    => $signed['headers'] ?? ['Content-Type' => $data['content_type']],
                'expires_in' => 600,
            ], 'Presigned upload URL generated', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(
                null,
                'Could not generate an upload URL. Check the S3 disk is configured (AWS_* env) and the flysystem-aws-s3-v3 package is installed. ['.$e->getMessage().']',
                400
            );
        }
    }

    /** Persist one inspection record (photo metadata and/or a manual damage finding). */
    public function store(StoreInspectionRequest $request)
    {
        try {
            $data = $request->validated();

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
                's3_disk'        => $data['s3_disk'] ?? 's3',
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

    /** Delete a record and its S3 object (if any). */
    public function destroy(InspectionRecord $inspection)
    {
        try {
            if ($inspection->s3_key) {
                try {
                    Storage::disk($inspection->s3_disk ?: 's3')->delete($inspection->s3_key);
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
