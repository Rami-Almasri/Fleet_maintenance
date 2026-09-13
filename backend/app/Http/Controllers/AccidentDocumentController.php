<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\AccidentCase;
use App\Models\VehicleDocument;
use App\Services\Accident\AccidentCaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The accident dossier — the police report, the photographs, the insurer's letter, the estimates and
 * the invoices.
 *
 * IT REUSES `vehicle_documents`. There is no second media system here, and there must never be one:
 * the Mulkiya and the warranty dossier already live in that table, it already owns disk selection,
 * URL resolution and the uploader's identity, and two upload paths is how one of them quietly loses
 * a file. The only additions this feature made were a nullable `accident_case_id` and nine kinds.
 *
 * A DOSSIER, NOT A SLOT. Nothing here is ever stamped `superseded_at` — eleven photographs of a
 * crumpled wing coexist, and none of them replaces another. That is the same behaviour the warranty
 * kinds have, and the opposite of the Mulkiya's.
 *
 * THE KIND IS DECLARED, NEVER INFERRED. Filing a police report as "other" breaks the one query the
 * whole documentation gate depends on, so the upload names its kind and the form makes that the
 * first choice, not a detail.
 */
class AccidentDocumentController extends Controller
{
    /** Upload cap — 25 MB, larger than the Mulkiya's because accident evidence includes video. */
    private const MAX_KB = 25600;

    public function __construct(private AccidentCaseService $cases) {}

    /** Everything filed on this case, newest first. */
    public function index(AccidentCase $case)
    {
        return ResponseHelper::SuccessResponse($this->payload($case), 'Accident documents retrieved');
    }

    public function store(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf,mp4,mov,avi,webm', 'max:' . self::MAX_KB],
            'kind' => ['required', Rule::in(VehicleDocument::ACCIDENT_KINDS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');

        // Snapshot the metadata BEFORE storeAs moves the temp file off disk.
        $originalName = $file->getClientOriginalName();
        $mime = $file->getMimeType();
        $size = $file->getSize();
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $path = $file->storeAs(
            "accident-documents/case-{$case->id}/{$data['kind']}",
            (string) Str::uuid() . '.' . $ext,
            $disk,
        );
        if (! $path) {
            return ResponseHelper::FailureResponse(null, 'Could not store the document. Check the storage disk is writable.', 500);
        }

        $user = $request->user();

        $document = VehicleDocument::create([
            'vehicle_id'       => $case->vehicle_id,
            'accident_case_id' => $case->id,
            'kind'             => $data['kind'],
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

        // The timeline row, and — for a police report on a case that had nothing — the status nudge
        // to `recorded`. NOT to `verified`: uploading a scan is evidence one exists, which is a
        // different claim from somebody having read it. @see AccidentCaseService::documentAttached()
        $this->cases->documentAttached($case, $document, $user);

        return ResponseHelper::SuccessResponse($this->payload($case->fresh()), 'Document uploaded', 201);
    }

    /**
     * Remove one file — for a wrong upload, not for a replacement (a replacement is another upload;
     * this is a dossier and both would legitimately coexist).
     */
    public function destroy(Request $request, AccidentCase $case, VehicleDocument $document)
    {
        if ($document->accident_case_id !== $case->id) {
            return ResponseHelper::FailureResponse(null, 'That document does not belong to this accident case.', 404);
        }

        $disk = $document->disk ?: 'public';
        $path = $document->file_path;
        $document->delete();

        // Best-effort: the row is already gone, so a missing object must not fail the request.
        if ($path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $e) {
                // object already gone / disk unreachable — nothing left to do
            }
        }

        return ResponseHelper::SuccessResponse($this->payload($case), 'Document deleted');
    }

    /** The dossier, grouped by kind so the page can render the police section apart from the photos. */
    private function payload(AccidentCase $case): array
    {
        $documents = $case->documents()->orderByDesc('id')->get();

        $shape = fn (VehicleDocument $d) => [
            'id'            => $d->id,
            'kind'          => $d->kind,
            'kind_label'    => VehicleDocument::KINDS[$d->kind] ?? $d->kind,
            'url'           => $d->viewUrl(),
            'is_pdf'        => $d->mime_type === 'application/pdf',
            'is_video'      => Str::startsWith((string) $d->mime_type, 'video/'),
            'original_name' => $d->original_name,
            'file_size'     => $d->file_size,
            'note'          => $d->note,
            'uploaded_at'   => optional($d->uploaded_at)->toIso8601String(),
            'uploaded_by'   => $d->uploaded_by_name,
        ];

        return [
            'accident_case_id' => $case->id,
            'reference'        => $case->reference,
            'documents'        => $documents->map($shape)->values(),
            'by_kind'          => $documents->groupBy('kind')->map(fn ($g) => $g->map($shape)->values())->all(),
            // The one question the page leads with, answered from the CASE and not from the file
            // list: a missing row and a document nobody has looked at are different states.
            'police_status'    => $case->police_status,
        ];
    }
}
