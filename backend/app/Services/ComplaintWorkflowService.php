<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Exceptions\WorkflowTransitionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Complaint Workflow — the MUTATION side of the customer-complaint entity. Every triage step runs through
 * here: it moves the complaint's `status`, stamps its `decision`, appends the matching complaint_event, and
 * fires the customer-support notifications. The lifecycle is entirely the complaint's own — it only touches
 * the workshop when a decision needs real work, at which point spawnInspection() opens a maintenance ticket
 * (reusing the existing inspection-request path) and stores the `maintenance_id` link.
 *
 *     new → notified → contacted → [decision] → in_maintenance (spawned) | resolved → closed
 *
 * This is the customer-complaint counterpart to the lightweight DriverObservationService. See
 * [[complaint-entity]] and the read layer in ComplaintService.
 */
class ComplaintWorkflowService
{
    /** Inspector (Abu Maroof) — owns complaint triage. Mirrors MaintenanceWorkflowService::NOTIFY_INSPECTOR. */
    private const NOTIFY_INSPECTOR = 'maintenance.initiate';

    public function __construct(
        private NotificationScanner $notifier,
        private MaintenanceWorkflowService $workflow,
    ) {}

    // ── Intake ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Log a new customer complaint. Born `new`, immediately notified to the Inspector, then parked at
     * `notified` awaiting his first contact. Denormalises the renter's contact so triage can call without
     * a join. `source` distinguishes an ops-logged call from a driver-relayed one.
     *
     * @param array{vehicle_id:int, description:string, severity?:?string, source?:?string} $data
     */
    public function open(array $data, User $actor): Complaint
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to log a complaint.', ['field' => 'vehicle_id']);
        }

        $description = $this->clean($data['description'] ?? null);
        if ($description === null) {
            throw new WorkflowTransitionException('Describe the customer\'s complaint before submitting.', ['field' => 'description']);
        }

        $severity = in_array($data['severity'] ?? null, Maintenance::FAULT_SEVERITIES, true) ? $data['severity'] : null;
        $source   = in_array($data['source'] ?? null, Complaint::SOURCES, true) ? $data['source'] : Complaint::SOURCE_OPS;

        // Resolve who currently holds the car (open type-'C' rental) so the inspector can call the renter.
        $rental   = $vehicle->contracts()->where('contract_type', 'C')->currentlyOpen()->with('customer')->latest('id')->first();
        $renter   = $rental?->customer;

        return DB::transaction(function () use ($vehicleId, $description, $severity, $source, $actor, $rental, $renter) {
            $complaint = Complaint::create([
                'vehicle_id'     => $vehicleId,
                'customer_id'    => $renter?->id,
                'contract_id'    => $rental?->id,
                'source'         => $source,
                'status'         => Complaint::STATUS_NEW,
                'severity'       => $severity,
                'description'    => $description,
                'customer_name'  => $renter?->name_en ?: ($renter?->name_ar ?: null),
                'customer_phone' => $renter?->mobile1 ?: ($renter?->whatsapp ?: null),
                'contract_no'    => $rental?->contract_no,
                'created_by'     => $actor->id,
            ]);

            $this->event($complaint, ComplaintEvent::TYPE_CREATED, $description, [
                'source'   => $source,
                'severity' => $severity,
                'customer' => $complaint->customer_name,
            ], $actor);

            // Single hand-off — the Inspector (Abu Maroof) OWNS the triage from here.
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_complaint_new',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => trim('📣 Customer complaint · ' . $this->label($complaint->vehicle)),
                'body'     => trim(($complaint->customer_name ? $complaint->customer_name . ' reported' : $actor->name . ' logged a complaint')
                                . ' on ' . $this->label($complaint->vehicle) . ' — “' . $description . '”. Contact the customer to triage it.'),
                'url'      => '/complaints/' . $complaint->id,
                'key'      => 'complaint:' . $complaint->id . ':new',
                'icon'     => 'wrench',
                'meta'     => [
                    'complaint_id'  => $complaint->id,
                    'plate'         => $complaint->vehicle?->plate_no,
                    'customer'      => $complaint->customer_name,
                    'customer_phone'=> $complaint->customer_phone,
                    'contract_no'   => $complaint->contract_no,
                ],
            ], $actor->id);

            $complaint->status = Complaint::STATUS_NOTIFIED;
            $complaint->save();
            $this->event($complaint, ComplaintEvent::TYPE_NOTIFIED, 'Inspector notified to triage the complaint', [], $actor);

            return $complaint->fresh();
        });
    }

    // ── Triage actions ──────────────────────────────────────────────────────────────────────────────

    /**
     * "Spoke with the customer" — a logged conversation. Repeatable (a complaint may need several calls).
     * Records what was said and moves the complaint to `contacted`. The actor becomes the owner.
     */
    public function logContact(Complaint $complaint, ?string $note, User $actor): Complaint
    {
        $this->assertOpen($complaint);

        return DB::transaction(function () use ($complaint, $note, $actor) {
            $this->event($complaint, ComplaintEvent::TYPE_CONTACTED, $this->clean($note) ?? 'Spoke with the customer', [], $actor);

            // Only advance forward — a call on an in_maintenance complaint stays in_maintenance.
            if (in_array($complaint->status, [Complaint::STATUS_NEW, Complaint::STATUS_NOTIFIED], true)) {
                $complaint->status = Complaint::STATUS_CONTACTED;
            }
            $complaint->assigned_to = $actor->id;
            $complaint->save();

            return $complaint->fresh();
        });
    }

    /**
     * Record the triage decision. `bring_for_inspection` spawns a maintenance ticket immediately (→
     * in_maintenance). The other decisions (keep driving / replace / roadside) are captured as the plan;
     * the operator then resolves or closes the complaint once acted on.
     */
    public function recordDecision(Complaint $complaint, string $decision, ?string $note, User $actor): Complaint
    {
        $this->assertOpen($complaint);
        if (! in_array($decision, Complaint::DECISIONS, true)) {
            throw new WorkflowTransitionException('Unknown decision.', ['field' => 'decision']);
        }

        return DB::transaction(function () use ($complaint, $decision, $note, $actor) {
            $complaint->decision    = $decision;
            $complaint->assigned_to = $actor->id;
            // Recording a decision implies contact happened — never regress a further-along status.
            if (in_array($complaint->status, [Complaint::STATUS_NEW, Complaint::STATUS_NOTIFIED], true)) {
                $complaint->status = Complaint::STATUS_CONTACTED;
            }
            $complaint->save();

            $this->event($complaint, ComplaintEvent::TYPE_DECISION, $this->clean($note), ['decision' => $decision], $actor);

            if ($decision === Complaint::DECISION_INSPECTION) {
                return $this->spawnInspection($complaint->fresh(), $actor);
            }

            return $complaint->fresh();
        });
    }

    /**
     * Send the car in — the complaint needs real work. Reuses the existing inspection-request path
     * (born in the Inspector's diagnostic queue, Abu Maroof notified), then links the ticket back and
     * flips the complaint to `in_maintenance`. Idempotent: a complaint already linked won't double-spawn.
     */
    public function spawnInspection(Complaint $complaint, User $actor): Complaint
    {
        if ($complaint->maintenance_id) {
            return $complaint->fresh();
        }

        $ticket = $this->workflow->requestInspectionByController([
            'vehicle_id'         => $complaint->vehicle_id,
            'trigger_reason'     => Maintenance::TRIGGER_CUSTOMER,
            'customer_complaint' => $complaint->description,
        ], $actor);

        // The Controller pressed "send in", but the issue came from the renter — record the true source.
        $ticket->request_origin = Maintenance::SOURCE_CUSTOMER;
        $ticket->save();

        $complaint->maintenance_id = $ticket->id;
        $complaint->status         = Complaint::STATUS_IN_MAINTENANCE;
        $complaint->assigned_to    = $actor->id;
        $complaint->save();

        $this->event($complaint, ComplaintEvent::TYPE_INSPECTION_REQUESTED, 'Sent in for inspection', ['maintenance_id' => $ticket->id], $actor);
        $this->event($complaint, ComplaintEvent::TYPE_MAINTENANCE_OPENED, 'Maintenance ticket #' . $ticket->id . ' opened', ['maintenance_id' => $ticket->id], $actor);

        return $complaint->fresh();
    }

    /**
     * Resolve — handled as customer support, no (further) repair needed (a misunderstanding, a usage
     * explanation, a temporary glitch). Terminal-ish: the complaint is done but stays queryable.
     */
    public function resolve(Complaint $complaint, ?string $note, User $actor): Complaint
    {
        return DB::transaction(function () use ($complaint, $note, $actor) {
            $complaint->status      = Complaint::STATUS_RESOLVED;
            $complaint->resolved_at = Carbon::now();
            $complaint->assigned_to = $actor->id;
            $complaint->save();

            $this->event($complaint, ComplaintEvent::TYPE_RESOLVED, $this->clean($note) ?? 'Complaint resolved — no repair needed', [], $actor);

            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_complaint_resolved',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '✅ Complaint resolved · ' . $this->label($complaint->vehicle),
                'body'     => trim($actor->name . ' resolved the complaint on ' . $this->label($complaint->vehicle)
                                . ($this->clean($note) ? ' — “' . $this->clean($note) . '”' : '')),
                'url'      => '/complaints/' . $complaint->id,
                'key'      => 'complaint:' . $complaint->id . ':resolved',
                'icon'     => 'check',
                'meta'     => ['complaint_id' => $complaint->id, 'plate' => $complaint->vehicle?->plate_no],
            ], $actor->id);

            return $complaint->fresh();
        });
    }

    /** Close — the terminal state (post-maintenance, or after a resolution is acknowledged). */
    public function close(Complaint $complaint, ?string $note, User $actor): Complaint
    {
        return DB::transaction(function () use ($complaint, $note, $actor) {
            $complaint->status      = Complaint::STATUS_CLOSED;
            $complaint->closed_at   = Carbon::now();
            $complaint->assigned_to = $actor->id;
            $complaint->save();

            $this->event($complaint, ComplaintEvent::TYPE_CLOSED, $this->clean($note) ?? 'Complaint closed', [], $actor);

            return $complaint->fresh();
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function event(Complaint $complaint, string $type, ?string $notes, array $meta, User $actor): ComplaintEvent
    {
        return ComplaintEvent::create([
            'complaint_id'    => $complaint->id,
            'event_type'      => $type,
            'notes'           => $notes,
            'meta'            => $meta ?: null,
            'created_by'      => $actor->id,
            'created_by_name' => $actor->name,
        ]);
    }

    private function assertOpen(Complaint $complaint): void
    {
        if (! $complaint->isOpen()) {
            throw new WorkflowTransitionException('This complaint is already ' . $complaint->status . '.', ['status' => $complaint->status]);
        }
    }

    private function label(?Vehicle $vehicle): string
    {
        if (! $vehicle) {
            return 'the vehicle';
        }
        return trim(($vehicle->plate_no ?: '') . ' ' . trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''))) ?: 'the vehicle';
    }

    private function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        return ($value === null || $value === '') ? null : $value;
    }
}
