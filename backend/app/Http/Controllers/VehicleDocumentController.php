<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The car's paperwork — the Mulkiya (UAE Vehicle Licence) scan, one per car, changeable.
 *
 * REPLACE, NEVER OVERWRITE. `store()` supersedes the outgoing card and inserts a new current one,
 * so "change the photo" keeps the previous licence as history (a renewal produces a genuinely new
 * card, and the old one is what the car was operated under until then). `destroy()` is the escape
 * hatch for a wrong upload — it removes that version's row and object, and if the deleted row was
 * the current card the most recent superseded version is restored as current, so a car is never
 * left showing nothing while an older scan still exists.
 *
 * Bytes go to S3 when configured, else the local `public` disk — the same fallback the cleaning /
 * inspection photo trail uses, so this works on a demo machine with no AWS env.
 *
 * This endpoint owns the SCAN only. The registration and insurance DATES stay on
 * `vehicle_registrations` (synced from OfficeManager) — nothing here writes them.
 */
class VehicleDocumentController extends Controller
{
    /** Upload cap. Scans are compressed in-browser first; this is the guard, not the target. */
    private const MAX_KB = 12288; // 12 MB

    /** Every document filed for one car, newest first, with the current card called out. */
    public function index(Vehicle $vehicle, Request $request)
    {
        try {
            $kind = $request->query('kind', VehicleDocument::KIND_MULKIYA);

            return ResponseHelper::SuccessResponse($this->payload($vehicle, $kind), 'Vehicle documents retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Add (or change) the car's Mulkiya. The previous current card is superseded, not deleted —
     * the new upload becomes the one in force.
     */
    public function store(Vehicle $vehicle, Request $request)
    {
        try {
            $data = $request->validate([
                // A scan is usually a photo, but a PDF straight from the RTA portal is just as valid.
                'file' => 'required|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:' . self::MAX_KB,
                'kind' => 'nullable|in:' . implode(',', array_keys(VehicleDocument::KINDS)),
                'note' => 'nullable|string|max:500',
            ]);

            $kind = $data['kind'] ?? VehicleDocument::KIND_MULKIYA;
            $file = $request->file('file');
            $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');

            // Snapshot metadata BEFORE storeAs moves the temp file off disk.
            $originalName = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $size = $file->getSize();
            [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

            $path = $file->storeAs(
                "vehicle-documents/vehicle-{$vehicle->id}/{$kind}",
                (string) Str::uuid() . '.' . $ext,
                $disk,
            );
            if (! $path) {
                return ResponseHelper::FailureResponse(null, 'Could not store the document. Check the storage disk is writable.', 500);
            }

            $user = $request->user();

            // Supersede + insert together: there must never be a moment with two current cards,
            // nor one with none.
            DB::transaction(function () use ($vehicle, $kind, $disk, $path, $originalName, $mime, $size, $width, $height, $data, $user) {
                VehicleDocument::where('vehicle_id', $vehicle->id)
                    ->where('kind', $kind)
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => now()]);

                VehicleDocument::create([
                    'vehicle_id'       => $vehicle->id,
                    'kind'             => $kind,
                    'disk'             => $disk,
                    'file_path'        => $path,
                    'original_name'    => $originalName,
                    'mime_type'        => $mime,
                    'file_size'        => $size,
                    'width'            => $width ?: null,
                    'height'           => $height ?: null,
                    'note'             => $data['note'] ?? null,
                    'uploaded_by'      => $user?->id,
                    'uploaded_by_name' => $user?->name,
                    'uploaded_at'      => now(),
                ]);
            });

            return ResponseHelper::SuccessResponse($this->payload($vehicle, $kind), 'Mulkiya saved', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Remove one version — for a wrong upload, not for a renewal (a renewal is a `store()`).
     * If the removed version was the current card, the newest remaining version is promoted back
     * to current so the car keeps showing the best scan we still hold.
     */
    public function destroy(Vehicle $vehicle, VehicleDocument $document)
    {
        try {
            if ($document->vehicle_id !== $vehicle->id) {
                return ResponseHelper::FailureResponse(null, 'That document does not belong to this vehicle.', 404);
            }

            $kind    = $document->kind;
            $wasCurrent = $document->superseded_at === null;
            $disk    = $document->disk ?: 'public';
            $path    = $document->file_path;

            DB::transaction(function () use ($document, $vehicle, $kind, $wasCurrent) {
                $document->delete();

                if ($wasCurrent) {
                    $previous = VehicleDocument::where('vehicle_id', $vehicle->id)
                        ->where('kind', $kind)
                        ->orderByDesc('id')
                        ->first();
                    $previous?->update(['superseded_at' => null]);
                }
            });

            // Best-effort: the row is already gone, so a missing object must not fail the request.
            if ($path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    // object already gone / disk unreachable — nothing left to do
                }
            }

            return ResponseHelper::SuccessResponse($this->payload($vehicle, $kind), 'Document deleted', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One car's document trail for a kind: the card in force + every superseded version. */
    private function payload(Vehicle $vehicle, string $kind): array
    {
        $documents = VehicleDocument::where('vehicle_id', $vehicle->id)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->get();

        $shape = fn (VehicleDocument $d) => [
            'id'            => $d->id,
            'kind'          => $d->kind,
            'kind_label'    => VehicleDocument::KINDS[$d->kind] ?? $d->kind,
            'url'           => $d->viewUrl(),
            'is_pdf'        => $d->mime_type === 'application/pdf',
            'is_current'    => $d->is_current,
            'original_name' => $d->original_name,
            'file_size'     => $d->file_size,
            'note'          => $d->note,
            'uploaded_at'   => optional($d->uploaded_at)->toIso8601String(),
            'uploaded_by'   => $d->uploaded_by_name,
            'superseded_at' => optional($d->superseded_at)->toIso8601String(),
        ];

        $current = $documents->firstWhere('superseded_at', null);

        return [
            'vehicle' => [
                'id'       => $vehicle->id,
                'plate_no' => $vehicle->plate_no,
                'label'    => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ($vehicle->plate_no ?: 'Vehicle'),
            ],
            'kind'     => $kind,
            'current'  => $current ? $shape($current) : null,
            // Older cards, newest first — the renewal trail.
            'history'  => $documents->filter(fn ($d) => $d->superseded_at !== null)->map($shape)->values(),
        ];
    }
}
