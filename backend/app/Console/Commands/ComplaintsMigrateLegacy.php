<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Maintenance;
use App\Models\VehicleLogEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: every legacy `customer_reported` Maintenance ticket → a first-class Complaint row,
 * with its vehicle_log_events replayed into complaint_events so the timeline is preserved. Idempotent via
 * complaints.legacy_ticket_id (re-running skips already-migrated tickets).
 *
 * Ticket status → complaint status:
 *   • complaint_triage                         → contacted / notified (by whether a customer call was logged)
 *   • complaint_resolved                       → resolved (handled on-site, no repair) — NOT linked
 *   • any real maintenance / review state      → in_maintenance, LINKED (maintenance_id = the ticket)
 *   • closed / terminal-after-funnel           → closed, LINKED
 *   • blank / unknown                          → closed, not linked
 *
 * DRY-RUN by default (prints the plan). Pass --commit to write. --retire-shells additionally closes the
 * pure complaint_triage SHELL tickets (which are duplicated by the new complaint) so they leave the live
 * queue — a reversible, audited status flip, opt-in because it mutates existing tickets.
 */
class ComplaintsMigrateLegacy extends Command
{
    protected $signature = 'complaints:migrate-legacy {--commit : Write the changes (otherwise dry-run)} {--retire-shells : Also close leftover complaint_triage shell tickets}';

    protected $description = 'Backfill legacy customer_reported maintenance tickets into the first-class Complaint entity';

    // Ticket states that mean the complaint entered the real maintenance funnel (link the ticket).
    private const FUNNEL_STATES = [
        Maintenance::WF_PENDING_REVIEW, Maintenance::WF_INSPECTION_REQUESTED,
        Maintenance::WF_INSPECTION_DIAGNOSTIC, Maintenance::WF_INSPECTION_PENDING,
        Maintenance::WF_ON_SITE_PENDING, Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_IN_TRANSIT,
        Maintenance::WF_UNDER_REPAIR, Maintenance::WF_REPAIR_REVIEW, Maintenance::WF_READY_REINSPECTION,
        Maintenance::WF_REINSPECTION_FAILED, Maintenance::WF_READY_FOR_PICKUP, Maintenance::WF_IN_OUR_PARK,
        Maintenance::WF_AWAITING_INVOICE, Maintenance::WF_AWAITING_PARTS,
        Maintenance::WF_RECOMMENDATION_PENDING, Maintenance::WF_TRIAGE_APPROVAL_PENDING,
        Maintenance::WF_PAUSED_RETURNED_TO_SERVICE,
    ];

    // vehicle_log_event type → complaint_event type for the timeline replay.
    private const EVENT_MAP = [
        VehicleLogEvent::EVENT_REPORT_FILED         => ComplaintEvent::TYPE_CREATED,
        VehicleLogEvent::EVENT_INSPECTION_REQUESTED => ComplaintEvent::TYPE_INSPECTION_REQUESTED,
        VehicleLogEvent::EVENT_STATUS_UPDATE        => ComplaintEvent::TYPE_CONTACTED,
        VehicleLogEvent::EVENT_DISPATCHED           => ComplaintEvent::TYPE_MAINTENANCE_OPENED,
        VehicleLogEvent::EVENT_UNDER_REPAIR         => ComplaintEvent::TYPE_MAINTENANCE_OPENED,
        VehicleLogEvent::EVENT_CLOSED               => ComplaintEvent::TYPE_CLOSED,
    ];

    public function handle(): int
    {
        $commit  = (bool) $this->option('commit');
        $retire  = (bool) $this->option('retire-shells');

        $tickets = Maintenance::where('trigger_reason', Maintenance::TRIGGER_CUSTOMER)
            ->orderBy('id')
            ->get();

        $this->info(($commit ? '[COMMIT] ' : '[DRY-RUN] ') . 'Found ' . $tickets->count() . ' customer_reported tickets.');

        $created = 0; $skipped = 0; $retired = 0;

        foreach ($tickets as $ticket) {
            if (Complaint::where('legacy_ticket_id', $ticket->id)->exists()) {
                $skipped++;
                $this->line("  #{$ticket->id}  already migrated — skip");
                continue;
            }

            [$status, $link] = $this->mapStatus($ticket);
            $isShell = $ticket->workflow_status === Maintenance::WF_COMPLAINT_TRIAGE;

            $this->line("  #{$ticket->id}  {$ticket->workflow_status} → {$status}"
                . ($link ? " (linked)" : "") . ($isShell ? " [shell]" : ""));

            if (! $commit) { $created++; continue; }

            DB::transaction(function () use ($ticket, $status, $link, $isShell, $retire, &$retired) {
                $meta = $this->filedMeta($ticket);

                $complaint = Complaint::create([
                    'vehicle_id'       => $ticket->vehicle_id,
                    'customer_id'      => $meta['customer_id'] ?? null,
                    'contract_id'      => $meta['contract_id'] ?? null,
                    'source'           => Complaint::SOURCE_OPS,
                    'status'           => $status,
                    'severity'         => $ticket->fault_severity ?: $ticket->severity,
                    'description'      => $ticket->customer_complaint ?: 'Customer complaint (migrated)',
                    'customer_name'    => $meta['customer'] ?? null,
                    'contract_no'      => $meta['contract_no'] ?? null,
                    'maintenance_id'   => $link ? $ticket->id : null,
                    'legacy_ticket_id' => $ticket->id,
                    'created_by'       => $ticket->requested_by,
                    'resolved_at'      => $status === Complaint::STATUS_RESOLVED ? ($ticket->updated_at ?? $ticket->created_at) : null,
                    'closed_at'        => $status === Complaint::STATUS_CLOSED ? ($ticket->updated_at ?? $ticket->created_at) : null,
                ]);

                $this->replayTimeline($complaint, $ticket);

                // Retire the duplicated triage shell so it leaves the live queue (reversible, audited).
                if ($isShell && $retire) {
                    $ticket->workflow_status = Maintenance::WF_COMPLAINT_RESOLVED;
                    $ticket->save();
                    $retired++;
                }
            });

            $created++;
        }

        $this->newLine();
        $this->info("Migrated: {$created}   Skipped(existing): {$skipped}   Shells retired: {$retired}");

        $activeShells = $tickets->where('workflow_status', Maintenance::WF_COMPLAINT_TRIAGE)->count();
        if ($activeShells && ! $retire) {
            $this->warn("{$activeShells} complaint_triage shell ticket(s) remain in the live queue. Re-run with --retire-shells to close them.");
        }
        if (! $commit) {
            $this->warn('Dry-run only — nothing written. Re-run with --commit to apply.');
        }

        return self::SUCCESS;
    }

    /** @return array{0:string,1:bool} [complaint status, link the ticket?] */
    private function mapStatus(Maintenance $ticket): array
    {
        $s = $ticket->workflow_status;
        return match (true) {
            $s === Maintenance::WF_COMPLAINT_RESOLVED => [Complaint::STATUS_RESOLVED, false],
            $s === Maintenance::WF_COMPLAINT_TRIAGE   => [$this->hasCall($ticket) ? Complaint::STATUS_CONTACTED : Complaint::STATUS_NOTIFIED, false],
            $s === Maintenance::WF_CLOSED             => [Complaint::STATUS_CLOSED, true],
            in_array($s, [Maintenance::WF_DIAGNOSTIC_CLEARED, Maintenance::WF_REVIEW_REJECTED, Maintenance::WF_RECOMMENDATION_DISMISSED], true)
                                                      => [Complaint::STATUS_CLOSED, true],
            in_array($s, self::FUNNEL_STATES, true)   => [Complaint::STATUS_IN_MAINTENANCE, true],
            default                                   => [Complaint::STATUS_CLOSED, false],
        };
    }

    private function hasCall(Maintenance $ticket): bool
    {
        foreach ($ticket->follow_ups ?? [] as $f) {
            if (is_array($f) && ($f['type'] ?? null) === 'customer_call') {
                return true;
            }
        }
        return false;
    }

    /** Customer/contract context captured durably in the report_filed log event when the complaint was logged. */
    private function filedMeta(Maintenance $ticket): array
    {
        $filed = VehicleLogEvent::where('maintenance_id', $ticket->id)
            ->where('event_type', VehicleLogEvent::EVENT_REPORT_FILED)
            ->orderBy('occurred_at')
            ->first();
        return is_array($filed?->meta) ? $filed->meta : [];
    }

    /** Replay the ticket's vehicle_log_events into complaint_events, preserving actor + timestamp. */
    private function replayTimeline(Complaint $complaint, Maintenance $ticket): void
    {
        $events = VehicleLogEvent::where('maintenance_id', $ticket->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $sawCreated = false;
        foreach ($events as $e) {
            $type = self::EVENT_MAP[$e->event_type] ?? ComplaintEvent::TYPE_NOTE;
            // Only the first event becomes the canonical "created" entry.
            if ($type === ComplaintEvent::TYPE_CREATED) {
                if ($sawCreated) { $type = ComplaintEvent::TYPE_NOTE; }
                $sawCreated = true;
            }

            $row = new ComplaintEvent([
                'complaint_id'    => $complaint->id,
                'event_type'      => $type,
                'notes'           => $e->description,
                'meta'            => ['migrated_from' => $e->event_type, 'log_event_id' => $e->id],
                'created_by'      => $e->actor_id,
                'created_by_name' => $e->actor?->name,
            ]);
            // Preserve the historical time rather than "now".
            $row->created_at = $e->occurred_at;
            $row->updated_at = $e->occurred_at;
            $row->save();
        }

        // Guarantee at least a created entry even for tickets with no log history.
        if ($events->isEmpty()) {
            $row = new ComplaintEvent([
                'complaint_id' => $complaint->id,
                'event_type'   => ComplaintEvent::TYPE_CREATED,
                'notes'        => $complaint->description,
                'meta'         => ['migrated_from' => 'ticket', 'ticket_id' => $ticket->id],
                'created_by'   => $ticket->requested_by,
            ]);
            $row->created_at = $ticket->requested_at ?? $ticket->created_at;
            $row->updated_at = $row->created_at;
            $row->save();
        }
    }
}
