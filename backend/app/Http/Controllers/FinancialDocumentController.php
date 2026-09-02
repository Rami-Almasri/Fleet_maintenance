<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\PartInvoice;
use App\Services\FinancialDocumentService;
use App\Services\ProcurementLifecycleService;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The lifecycle actions shared by every financial document — submit, approve, unapprove, pay, cancel —
 * plus the read side: a ticket's whole procurement chain.
 *
 * One controller for both invoice kinds, routed as `/financial-documents/{type}/{id}/…`, because the
 * decisions are identical and duplicating them per document type is how two code paths drift apart. The
 * type segment resolves to a model here and nowhere else. Money actions → maintenance.manage.
 */
class FinancialDocumentController extends Controller
{
    public function __construct(
        private FinancialDocumentService $documents,
        private ProcurementLifecycleService $lifecycle,
    ) {}

    /** The eight-stage procurement chain for every part on a ticket. Read-only. */
    public function lifecycle(Maintenance $ticket)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->lifecycle->forTicket($ticket),
            'Procurement lifecycle retrieved',
        ));
    }

    /** The status vocabulary itself, so the UI never hard-codes labels or tones. */
    public function vocabulary()
    {
        return ResponseHelper::SuccessResponse([
            'statuses' => collect(Status::ALL)->map(fn ($s) => [
                'value'     => $s,
                'label'     => Status::label($s),
                'tone'      => Status::tone($s),
                'derived'   => in_array($s, Status::DERIVED, true),
                'committed' => Status::isCommitted($s),
            ])->all(),
            'stages' => collect(ProcurementLifecycleService::STAGES)
                ->map(fn ($k) => ['key' => $k, 'label' => ProcurementLifecycleService::STAGE_LABELS[$k]])->all(),
        ], 'Vocabulary retrieved');
    }

    public function submit(string $type, int $id)
    {
        return $this->act($type, $id, fn (Model $d) => $this->documents->submit($d, request()->user()), 'Submitted for approval');
    }

    public function approve(string $type, int $id)
    {
        return $this->act($type, $id, fn (Model $d) => $this->documents->approve($d, request()->user()), 'Approved');
    }

    /** Refuse a submitted document and hand it back to be corrected. The reason is what makes it actionable. */
    public function returnForCorrection(Request $request, string $type, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->act(
            $type,
            $id,
            fn (Model $d) => $this->documents->returnToDraft($d, $request->user(), $data['reason']),
            'Returned for correction',
        );
    }

    public function unapprove(string $type, int $id)
    {
        return $this->act($type, $id, fn (Model $d) => $this->documents->unapprove($d, request()->user()), 'Approval withdrawn');
    }

    /** Record a payment. Omit `amount` to settle whatever is still outstanding. */
    public function pay(Request $request, string $type, int $id)
    {
        $data = $request->validate([
            'amount'    => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at'   => ['nullable', 'date'],
        ]);

        return $this->act($type, $id, fn (Model $d) => $this->documents->pay($d, $data, $request->user()), 'Payment recorded');
    }

    public function cancel(Request $request, string $type, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->act($type, $id, fn (Model $d) => $this->documents->cancel($d, $request->user(), $data['reason']), 'Cancelled');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /** The only place a `type` segment becomes a model. */
    private function resolve(string $type, int $id): Model
    {
        return match ($type) {
            'supplier-invoice', 'part-invoice' => PartInvoice::findOrFail($id),
            'garage-invoice', 'maintenance-invoice' => MaintenanceInvoice::findOrFail($id),
            default => abort(404, "Unknown financial document type '{$type}'."),
        };
    }

    private function act(string $type, int $id, callable $fn, string $message)
    {
        return $this->run(function () use ($type, $id, $fn, $message) {
            $doc = $fn($this->resolve($type, $id));

            return ResponseHelper::SuccessResponse(
                ['id' => $doc->id, 'type' => $type] + $doc->statusPayload(),
                $message,
            );
        });
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
