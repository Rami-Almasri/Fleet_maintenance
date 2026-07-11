<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;

/**
 * Missing-Invoice SLA scan (runs daily) — every ticket parked in `awaiting_invoice` whose invoice has
 * been outstanding longer than INVOICE_SLA_DAYS gets a 'Missing Invoice' alert pushed to the Supervisor
 * (who chases the garage) and the controllers who own invoices. The car is already back in service; this
 * only enforces that the paperwork lands within the window. Idempotent per day (the key carries the date),
 * so the reminder re-fires each day it stays overdue and stops the moment the invoice is recorded.
 */
class InvoicesScanOverdue extends Command
{
    protected $signature = 'invoices:scan-overdue';

    protected $description = 'Flag maintenance tickets whose invoice is overdue (awaiting_invoice > SLA days)';

    // Supervisors chase the garage; controllers own the invoice — both get the missing-invoice alert.
    private const SUPERVISOR = 'maintenance.delegate';
    private const CONTROLLERS = 'maintenance.manage';

    public function handle(NotificationScanner $notifier): int
    {
        $today = today()->toDateString();

        $overdue = Maintenance::awaitingInvoice()
            ->whereNotNull('awaiting_invoice_since')
            ->where('awaiting_invoice_since', '<', now()->subDays(Maintenance::INVOICE_SLA_DAYS))
            ->with('vehicle:id,plate_no,make,model', 'vendor:id,name')
            ->orderBy('awaiting_invoice_since')
            ->get();

        $pushed = 0;
        foreach ($overdue as $ticket) {
            $days   = $ticket->invoiceDaysWaiting() ?? 0;
            $plate  = $ticket->vehicle?->plate_no
                ?: trim(($ticket->vehicle?->make ?? '') . ' ' . ($ticket->vehicle?->model ?? ''))
                ?: ('Ticket #' . $ticket->id);
            $garage = $ticket->vendor?->name ?: $ticket->garage;

            $pushed += $notifier->notifyByAnyPermission([self::SUPERVISOR, self::CONTROLLERS], [
                'type'     => 'maint_invoice_overdue',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '⚠️ Missing invoice · ' . $plate,
                'body'     => trim($plate . ' has been back in service for ' . $days . ' days with no invoice'
                    . ($garage ? ' from ' . $garage : '') . '. Chase it — the ' . Maintenance::INVOICE_SLA_DAYS
                    . '-day window is blown.'),
                'url'  => '/invoices/pending-submission',
                // Re-fires once per day it stays overdue (date in the key); clears when the invoice is recorded.
                'key'  => 'maint_invoice_overdue:' . $ticket->id . ':' . $today,
                'icon' => 'invoice',
                'meta' => ['ticket_id' => $ticket->id, 'plate' => $ticket->vehicle?->plate_no, 'days_waiting' => $days],
            ]);
        }

        $this->info(sprintf('Invoice SLA scan — %d overdue ticket(s), %d alert(s) pushed.', $overdue->count(), $pushed));

        return self::SUCCESS;
    }
}
