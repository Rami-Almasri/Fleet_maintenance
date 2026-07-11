<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\GarageInvoiceSubmission;
use App\Models\Maintenance;
use App\Services\GarageInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Garage Invoice Portal — the endpoints behind the tokenised public page a garage uses to submit its own
 * itemised invoice, plus the team-side link issue + audit (accept/reject). The two public methods
 * (show/submit) are UNAUTHENTICATED and token-gated; everything they return is scoped to the one ticket
 * and carries only car + fault data — never money, customers or other tickets.
 */
class GarageInvoiceController extends Controller
{
    public function __construct(private GarageInvoiceService $service) {}

    // ── PUBLIC (token-gated, no login) ──────────────────────────────────────────────────────────────

    /**
     * The garage opens its link → this returns just enough to fill the invoice: the car, the fault list
     * to link lines to, and the findings catalog (categories) the editor needs. Expired/used links return
     * a clear state instead of the form, so the page can explain what happened.
     */
    public function show(string $token)
    {
        return $this->run(function () use ($token) {
            $link = GarageInvoiceSubmission::where('token', $token)->first();

            if (! $link) {
                return ResponseHelper::SuccessResponse(['state' => 'invalid'], 'Link not found', 200);
            }
            if ($link->status === GarageInvoiceSubmission::STATUS_SUBMITTED) {
                return ResponseHelper::SuccessResponse(['state' => 'submitted'], 'Already submitted', 200);
            }
            if (in_array($link->status, [GarageInvoiceSubmission::STATUS_ACCEPTED, GarageInvoiceSubmission::STATUS_REJECTED, GarageInvoiceSubmission::STATUS_CANCELLED], true)) {
                return ResponseHelper::SuccessResponse(['state' => 'closed'], 'Link no longer active', 200);
            }
            if ($link->isExpired()) {
                return ResponseHelper::SuccessResponse(['state' => 'expired'], 'Link expired', 200);
            }

            $ticket = $link->maintenance()->with('vehicle:id,plate_no,make,model', 'vendor:id,name')->first();

            return ResponseHelper::SuccessResponse([
                'state'   => 'open',
                // For a per-garage link, name the scoped garage — so it invoices for its own work only.
                'garage'  => $link->vendor?->name ?: $ticket->vendor?->name ?: $ticket->garage,
                'vehicle' => [
                    'plate' => $ticket->plate ?: $ticket->vehicle?->plate_no,
                    'car'   => $ticket->car_label ?: trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? '')) ?: null,
                ],
                // Only the faults this link may bill — the whole ticket's, or just the scoped garage's.
                'findings'   => collect($this->service->findingsForLink($link))
                    ->map(fn ($f) => ['text' => $f['text'] ?? null, 'source' => $f['source'] ?? null, 'severity' => $f['severity'] ?? null])
                    ->filter(fn ($f) => $f['text'])
                    ->values(),
                // The category menu (+ keyword lists) so the editor can auto-fill a line's category.
                'categories' => array_values(config('maintenance_findings.categories', [])),
                'expires_at' => optional($link->expires_at)->toIso8601String(),
            ], 'Invoice form ready', 200);
        });
    }

    /**
     * The garage submits its parts + labor + receipt total (+ optional photo/note). Validated, run
     * through the variance gate, and stored as a REVIEW-QUEUE record — the ticket cost is untouched
     * until the team accepts it.
     */
    public function submit(Request $request, string $token)
    {
        return $this->run(function () use ($request, $token) {
            $link = GarageInvoiceSubmission::where('token', $token)->first();
            if (! $link || ! $link->isOpenForSubmission()) {
                return ResponseHelper::FailureResponse(['state' => 'invalid'], 'This link is no longer valid — ask the fleet team for a new one.', 422);
            }

            $data = $request->validate([
                'line_items'                => ['required', 'array', 'min:1', 'max:100'],
                'line_items.*.kind'         => ['required', Rule::in(\App\Models\MaintenanceLineItem::KINDS)],
                'line_items.*.description'  => ['required', 'string', 'max:255'],
                'line_items.*.finding_text' => ['required', 'string', 'max:255'],
                'line_items.*.part_number'  => ['nullable', 'string', 'max:120'],
                'line_items.*.category_key' => ['nullable', 'string', 'max:40'],
                // Every line is a real charge: qty × unit price > 0.
                'line_items.*.quantity'     => ['required', 'numeric', 'gt:0'],
                'line_items.*.unit_price'   => ['required', 'numeric', 'gt:0'],
                'line_items.*.installed_on' => ['nullable', 'date'],
                'line_items.*.warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
                // The whole point of the portal — the printed receipt total to reconcile against.
                'receipt_total'             => ['required', 'numeric', 'gt:0'],
                'variance_explanation'      => ['nullable', 'string', 'max:1000'],
                'garage_note'               => ['nullable', 'string', 'max:1000'],
                'receipt_photo'             => ['nullable', 'image', 'max:8192'], // ≤ 8 MB
            ]);

            $lines = $this->normalizeLines($data['line_items']);

            // Diagnosis-First — reject up front (clear message to the garage) if a line isn't attributed to
            // one of the faults THIS link may bill (the scoped garage's own, for a per-garage link), rather
            // than letting it fail at the team's accept step.
            $this->assertLinesLinkedToFindings($this->service->findingsForLink($link), $lines);

            $this->service->submit(
                $link,
                $lines,
                [
                    'receipt_total'        => (float) $data['receipt_total'],
                    'variance_explanation' => $data['variance_explanation'] ?? null,
                    'garage_note'          => $data['garage_note'] ?? null,
                ],
                $request->file('receipt_photo'),
                $request->ip(),
            );

            // Deliberately minimal — the garage only needs confirmation, no ticket internals.
            return ResponseHelper::SuccessResponse(['state' => 'submitted'], 'Invoice submitted for review', 200);
        });
    }

    // ── AUTHED (team) ───────────────────────────────────────────────────────────────────────────────

    /**
     * The garages that worked on this ticket, each with the faults it may invoice and its current live link
     * (if any). The team uses this to hand each garage its own scoped link — no garage bills another's work.
     */
    public function garages(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            return ResponseHelper::SuccessResponse(
                ['garages' => $this->service->garagesForTicket($ticket)],
                'Garages loaded',
                200,
            );
        });
    }

    /**
     * Issue (or re-issue) a secure link for a ticket. Pass `vendor_id` to scope it to one garage (it then
     * presents only that garage's faults); omit it for a whole-ticket link. Returns the path the team sends.
     */
    public function issueLink(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate(['vendor_id' => ['nullable', 'integer', 'exists:vendors,id']]);

            $link = $this->service->issueLink($ticket, $request->user(), $data['vendor_id'] ?? null);

            return ResponseHelper::SuccessResponse([
                'token'      => $link->token,
                'vendor_id'  => $link->vendor_id,
                // Relative — the frontend prefixes its own origin to build the shareable URL.
                'path'       => '/garage-invoice/' . $link->token,
                'expires_at' => optional($link->expires_at)->toIso8601String(),
            ], 'Garage link created', 201);
        });
    }

    /** Team audit — accept the garage's invoice (applies it to the ticket). */
    public function accept(GarageInvoiceSubmission $submission)
    {
        return $this->run(function () use ($submission) {
            $this->service->accept($submission, request()->user());

            return ResponseHelper::SuccessResponse(['status' => $submission->fresh()->status], 'Garage invoice accepted', 200);
        });
    }

    /** Team audit — reject the garage's invoice (nothing applied to the ticket). */
    public function reject(Request $request, GarageInvoiceSubmission $submission)
    {
        return $this->run(function () use ($request, $submission) {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
            $this->service->reject($submission, $request->user(), $data['note'] ?? null);

            return ResponseHelper::SuccessResponse(['status' => $submission->fresh()->status], 'Garage invoice rejected', 200);
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    /** Keep only the keys the line pipeline consumes, dropping anything stray a client might send. */
    private function normalizeLines(array $lines): array
    {
        $allowed = ['kind', 'description', 'finding_text', 'part_number', 'category_key',
                    'quantity', 'unit_price', 'installed_on', 'warranty_months'];

        return array_values(array_map(function ($row) use ($allowed) {
            return array_intersect_key(is_array($row) ? $row : [], array_flip($allowed));
        }, $lines));
    }

    /** Every line must be attributed to one of the faults this link may bill (case-insensitive on text). */
    private function assertLinesLinkedToFindings(array $findings, array $lines): void
    {
        $allowed = collect($findings)
            ->pluck('text')->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->flip();

        foreach ($lines as $row) {
            $finding = mb_strtolower(trim((string) ($row['finding_text'] ?? '')));
            if ($finding === '' || ! $allowed->has($finding)) {
                abort(422, 'Each line must be linked to one of the listed faults.');
            }
        }
    }

    /** Unified try/catch → JSON, mirroring the workflow controller (ValidationException → 422, etc.). */
    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
