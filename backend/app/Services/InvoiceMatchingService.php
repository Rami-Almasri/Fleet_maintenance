<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Invoice Matching Desk — the read side of "the car is back, now key the paper against the work".
 *
 * When a car comes back from the garage two things have to line up: WHAT WE DID to it (the faults on the
 * ticket, each fixed by some garage) and WHAT WE WERE BILLED (one invoice per garage — a ticket worked in
 * two shops carries two bills, see [[one Ticket → many Invoices]]). This service answers one question per
 * ticket: *is the paper matched to the work yet?* — and never guesses. Everything it reports is read off
 * stored rows:
 *
 *   - a fault is BILLED when `maintenance_tasks.maintenance_invoice_id` points at an invoice. Nothing else.
 *   - an invoice's VARIANCE is its keyed lines minus its printed receipt total (MaintenanceInvoice::variance).
 *   - the "back at" moment is the ticket's own stamp — awaiting-invoice → workflow-closed → returned.
 *
 * The match state is therefore DERIVED, with no inference and no score:
 *   no_invoice — the car is back and not one bill has been keyed
 *   partial    — bills exist, but some repaired fault is on none of them
 *   variance   — every fault is billed, but a bill disagrees with its own printed receipt
 *   matched    — every repaired fault is billed and every receipt agrees
 *
 * Read-only: it opens nothing, closes nothing and writes nothing. Keying an invoice still goes through
 * MaintenanceInvoiceService, which owns the one write path.
 */
class InvoiceMatchingService
{
    /** Receipt vs itemised agree within a cent (mirrors MaintenanceInvoiceService). */
    private const VARIANCE_TOLERANCE = 0.01;

    /** How far back a settled ticket stays on the desk, so the queue is a work list and not an archive. */
    public const DEFAULT_WINDOW_DAYS = 120;

    /**
     * The tickets whose car is back from the shop — a bill is due, was keyed, or is still short of the work.
     * `awaiting_invoice` is always included regardless of age (it is by definition outstanding); a ticket
     * that has already been closed or parked stays on the desk for the window only.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function queue(int $windowDays = self::DEFAULT_WINDOW_DAYS, int $limit = 400): array
    {
        $since = Carbon::now()->subDays(max(1, $windowDays));

        $tickets = Maintenance::query()
            ->whereIn('workflow_status', Maintenance::WF_OPERATIONALLY_DONE)
            // The car went somewhere to be worked on — a ticket that never reached a garage and carries no
            // bill has no paper to match, and would only pad the queue.
            ->where(fn ($q) => $q->whereNotNull('vendor_id')->orWhereHas('invoices'))
            ->where(fn ($q) => $q
                ->where('workflow_status', Maintenance::WF_AWAITING_INVOICE)
                ->orWhere('awaiting_invoice_since', '>=', $since)
                ->orWhere('wf_closed_at', '>=', $since)
                ->orWhere('returned_at', '>=', $since))
            ->with([
                'vehicle:id,plate_no,make,model',
                'vendor:id,name',
                'tasks:id,maintenance_id,symptom,status,maintenance_invoice_id,current_vendor_id,marked_incorrect_at',
                'tasks.currentVendor:id,name',
                'invoices:id,maintenance_id,vendor_id,invoice_no,amount,receipt_total,reconciliation_status,receipt_photo_key',
                'invoices.vendor:id,name',
            ])
            ->limit($limit)
            ->get();

        $rows = $tickets->map(fn (Maintenance $t) => $this->row($t))
            // Oldest wait first — the desk is worked from the top, and a car that came back three weeks
            // ago with no paper is the one that gets forgotten.
            ->sortBy(fn (array $r) => [$r['match_state'] === 'matched' ? 1 : 0, -($r['days_back'] ?? 0)])
            ->values();

        return [
            'rows'    => $rows->all(),
            'summary' => $this->summary($rows),
            'window_days' => $windowDays,
            'sla_days'    => Maintenance::INVOICE_SLA_DAYS,
        ];
    }

    /** One ticket's line on the desk: the car, how long it has been back, the work, and the paper so far. */
    private function row(Maintenance $t): array
    {
        // Faults that COULD carry a cost. A cancelled / not-found / mis-diagnosed fault was never repaired,
        // so no garage can bill for it — counting it as unbilled would make a finished ticket look short.
        $billable = $t->tasks->reject(
            fn (MaintenanceTask $task) => in_array($task->status, MaintenanceTask::NON_REPAIR_TERMINAL, true)
                || $task->marked_incorrect_at !== null
        );
        $billed = $billable->whereNotNull('maintenance_invoice_id');

        $invoices     = $t->invoices;
        $invoiced     = round((float) $invoices->sum('amount'), 2);
        $receipts     = $invoices->whereNotNull('receipt_total');
        $variances    = $invoices->map(fn ($i) => $i->variance())->filter(fn ($v) => $v !== null);
        $offBy        = $variances->filter(fn ($v) => abs($v) > self::VARIANCE_TOLERANCE);
        $backAt       = $this->backAt($t);
        $daysBack     = $backAt ? (int) $backAt->startOfDay()->diffInDays(Carbon::today()) : null;
        $unbilled     = $billable->count() - $billed->count();

        return [
            'ticket_id'       => $t->id,
            'plate'           => $t->plate ?: $t->vehicle?->plate_no,
            'car'             => trim(($t->vehicle?->make ?? '').' '.($t->vehicle?->model ?? '')) ?: null,
            'vehicle_id'      => $t->vehicle_id,
            'workflow_status' => $t->workflow_status,

            // How long the paper has been outstanding, and whether that is past the fleet's SLA.
            'back_at'         => optional($backAt)->toIso8601String(),
            'days_back'       => $daysBack,
            'sla_overdue'     => $t->invoiceIsOverdue(),
            'invoice_requested_at' => optional($t->invoice_requested_at)->toIso8601String(),

            // The WORK side — every garage that touched the car, and how much of its work is on a bill.
            'garages'         => $this->garages($t),
            'faults_total'    => $billable->count(),
            'faults_billed'   => $billed->count(),
            'faults_unbilled' => $unbilled,
            'unbilled_faults' => $billable->whereNull('maintenance_invoice_id')
                ->take(4)->pluck('symptom')->values()->all(),

            // The PAPER side — what has been keyed against it so far.
            'invoice_count'   => $invoices->count(),
            'invoiced_amount' => $invoiced,
            'receipts_total'  => $receipts->isNotEmpty() ? round((float) $receipts->sum('receipt_total'), 2) : null,
            'variance_total'  => $variances->isNotEmpty() ? round((float) $variances->sum(), 2) : null,
            'invoices_off'    => $offBy->count(),
            'missing_receipt_photo' => $invoices->whereNull('receipt_photo_key')->count(),
            'ticket_cost'     => (float) $t->cost,

            'match_state'     => $this->matchState($invoices->count(), $unbilled, $offBy->count()),
        ];
    }

    /**
     * Which of the four states this ticket is in. Deliberately ordered: an unbilled fault outranks a
     * receipt disagreement, because a missing line is a bigger hole in the paper than a wrong total.
     */
    private function matchState(int $invoiceCount, int $unbilled, int $offBy): string
    {
        if ($invoiceCount === 0) {
            return 'no_invoice';
        }
        if ($unbilled > 0) {
            return 'partial';
        }
        if ($offBy > 0) {
            return 'variance';
        }

        // Every repaired fault is on a bill and every receipt agrees. (A ticket whose faults were all
        // cancelled lands here too, once a bill exists — there is nothing left for the paper to cover.)
        return 'matched';
    }

    /** Every garage on the ticket — the dispatched one plus any that a fault was moved to. */
    private function garages(Maintenance $t): array
    {
        return collect([$t->vendor?->name])
            ->merge($t->tasks->map(fn ($task) => $task->currentVendor?->name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** When the car came back, read off whichever stamp the ticket actually carries. */
    private function backAt(Maintenance $t): ?Carbon
    {
        foreach ([$t->awaiting_invoice_since, $t->wf_closed_at, $t->returned_at, $t->updated_at] as $stamp) {
            if ($stamp) {
                return $stamp->copy();
            }
        }

        return null;
    }

    /** Desk-level counters — the lanes the page filters by, and the money already keyed. */
    private function summary(Collection $rows): array
    {
        return [
            'total'           => $rows->count(),
            'no_invoice'      => $rows->where('match_state', 'no_invoice')->count(),
            'partial'         => $rows->where('match_state', 'partial')->count(),
            'variance'        => $rows->where('match_state', 'variance')->count(),
            'matched'         => $rows->where('match_state', 'matched')->count(),
            'overdue'         => $rows->where('sla_overdue', true)->count(),
            'faults_unbilled' => (int) $rows->sum('faults_unbilled'),
            'invoiced_amount' => round((float) $rows->sum('invoiced_amount'), 2),
        ];
    }
}
