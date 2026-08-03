<?php

namespace App\Services;

use App\Models\Maintenance;
use Illuminate\Support\Collection;

/**
 * Left-the-Garage Invoice Queue — the single definition of "this car physically left the garage and the
 * bill still hasn't landed".
 *
 * Evidence class: D (Derived) — it derives nothing new, it JOINS two facts already on the ticket:
 *   • F  `picked_up_from_garage_at` — the car was collected (the garage's work is over);
 *   • F  `cost`                     — the money side is closed (an invoice was entered).
 * Produces: the chase list consumed by the /oversight/left-garage page AND by the Action Center's
 * Checkpoint lane (NotificationScanner::leftGarageInvoiceMissing). Both surfaces read THIS method, so
 * the count on the page and the number of alerts in the lane can never disagree.
 *
 * Nothing is scored or guessed: a ticket is on the list when it left and has no cost, and it drops off
 * the moment a cost is entered.
 */
class LeftGarageInvoiceService
{
    /** How far back the chase list looks — the newest 400 collections. */
    private const LIMIT = 400;

    /**
     * Every ticket still owing an invoice, worst first (never-requested before already-requested, then
     * longest-waiting). Each row carries who worked on it, when it left and how long ago.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function rows(): Collection
    {
        $tickets = Maintenance::query()
            ->whereNotNull('picked_up_from_garage_at')
            ->with(['vehicle:id,plate_no,make,model', 'vendor:id,name'])
            ->withCount(['tasks'])
            ->orderByDesc('picked_up_from_garage_at')
            ->limit(self::LIMIT)
            ->get();

        $now = now();

        return $tickets->map(function (Maintenance $t) use ($now) {
            $leftAt    = $t->picked_up_from_garage_at;
            $hasCost   = $t->cost !== null && (float) $t->cost > 0;
            $requested = $t->invoice_requested_at !== null;

            return [
                'ticket_id'            => $t->id,
                'vehicle_id'           => $t->vehicle_id,
                'plate_no'             => $t->vehicle?->plate_no,
                'car'                  => trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')) ?: null,
                'garage'               => $t->vendor?->name,
                'workflow_status'      => $t->workflow_status,
                'faults'               => $t->tasks_count,
                'left_at'              => optional($leftAt)->toIso8601String(),
                'days_since'           => $leftAt ? $leftAt->diffInDays($now) : null,
                'invoice_requested'    => $requested,
                'invoice_requested_at' => optional($t->invoice_requested_at)->toIso8601String(),
                // Received = we've closed the money side (a cost is in).
                'invoice_received'     => $hasCost,
                'needs_request'        => ! $requested && ! $hasCost,
            ];
        })
        // Only the ones still owing an invoice — a finished (cost-in) ticket drops off the chase list.
        ->filter(fn ($r) => ! $r['invoice_received'])
        ->sortBy([
            ['needs_request', 'desc'],
            ['days_since', 'desc'],
        ])
        ->values();
    }
}
