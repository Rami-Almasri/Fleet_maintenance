<?php

namespace App\Evidence\Capture;

use App\Evidence\EventRecorder;
use App\Evidence\Provenance;
use App\Models\DomainEvent;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The seam between a task-oriented product and an event-oriented platform.
 *
 * Technicians complete a maintenance workflow. They never see MeasurementRecorded or DiagnosisMade,
 * never choose an event type, and never enter the same thing twice because two events need it. One
 * workflow step arrives here as the payload the user already submitted, and leaves as however many
 * immutable events that step actually produced.
 *
 * ONE ENTRY, MANY EVENTS. "Submit report" is a single button. It produces an inspection, an odometer
 * reading, one observation per finding, and a diagnosis per finding that has a root cause — because
 * those are genuinely different statements with different layers and different lifetimes. Asking the
 * technician to file them separately would be making the user pay for the platform's internal shape.
 *
 * TRANSLATION MUST NEVER BREAK THE WORKFLOW. Every method is wrapped so that a failure here is logged
 * and swallowed. The events are a derived consequence of work that has already been saved; if the log
 * cannot be written, the correct outcome is a technician whose repair was recorded and an engineer
 * with an alert — never a technician who is told their work failed because an analytics side-effect
 * threw. This is the one place in the platform where losing data is preferable to losing the user's
 * work, and it is deliberate.
 *
 * READS ONLY WHAT WAS ALREADY CAPTURED. Nothing here asks for new input. That is what makes the
 * first version of this free: the workflow already collects findings, root causes, odometer readings
 * and verification results, and none of it was ever being written anywhere a model could learn from.
 */
class CaptureTranslator
{
    public function __construct(private readonly EventRecorder $recorder)
    {
    }

    /**
     * "Submit report" — the inspector's diagnostic step.
     *
     * @param  array<string,mixed>  $report  the payload the inspector submitted, unchanged
     */
    public function inspectionSubmitted(Maintenance $ticket, array $report, User $actor, bool|string $decision): void
    {
        // THREE ANSWERS, TWO QUESTIONS. `requires_maintenance` has always answered "does this car need
        // work?" and a DEFERRED report answers yes — the fault is real and recorded; only the trip was
        // postponed. Recording it as false would teach every model reading this stream that a deferred
        // car was found healthy, which is the exact opposite of what the inspector said. `decision`
        // carries the second question ("and are we doing it now?") alongside it, so the two never
        // collapse into one another.
        $decision = is_string($decision)
            ? $decision
            : ($decision ? Maintenance::DECIDE_REQUIRES : Maintenance::DECIDE_NONE);
        $requiresMaintenance = $decision !== Maintenance::DECIDE_NONE;

        $this->safely('inspectionSubmitted', $ticket, function () use ($ticket, $report, $actor, $requiresMaintenance, $decision) {
            $this->recorder->session(function () use ($ticket, $report, $actor, $requiresMaintenance, $decision) {
                $subject = $this->subject($ticket);
                $who = Provenance::staff($actor);

                // FACT — the inspection happened.
                $this->recorder->record(DomainEvent::INSPECTION_PERFORMED, [
                    'requires_maintenance' => $requiresMaintenance,
                    'decision'             => $decision,
                    'deferral_trigger'     => $ticket->deferral_trigger,
                    'maintenance_type'     => $ticket->maintenance_type,
                    'fault_severity'       => $ticket->fault_severity,
                    'repair_location'      => $ticket->repair_location,
                    'trigger_reason'       => $ticket->trigger_reason,
                    'request_origin'       => $ticket->request_origin,
                ], $who, $subject);

                // FACT — an instrument reading, so it carries a higher trust than anything observed.
                if (is_numeric($ticket->report_odometer) && (int) $ticket->report_odometer > 0) {
                    $this->recorder->record(DomainEvent::ODOMETER_READ, [
                        'odometer' => (int) $ticket->report_odometer,
                        'stage'    => 'report',
                    ], Provenance::staff($actor, Provenance::METHOD_MEASURED), $subject);
                }

                // The customer's own words, when there are any — the only statement in the workflow
                // that nobody but the renter can provide, and the input the complaint interpreter is
                // built to read.
                if (filled($ticket->customer_complaint)) {
                    $this->recorder->record(DomainEvent::COMPLAINT_REPORTED, [
                        'text'   => $ticket->customer_complaint,
                        'source' => 'ticket',
                    ], Provenance::customer(), $subject);
                }

                $this->recordFindings($ticket, $actor, $subject, $who);
            });
        });
    }

    /**
     * Findings become two DIFFERENT kinds of statement, and separating them is the whole point of the
     * layer model: what the inspector SAW is a fact, and what they concluded caused it is a judgement.
     * Collapsing them would file an opinion in the layer future models trust most.
     *
     * @param  array<string,mixed>  $subject
     */
    private function recordFindings(Maintenance $ticket, User $actor, array $subject, Provenance $who): void
    {
        foreach ((array) $ticket->findings as $finding) {
            if (! is_array($finding) || blank($finding['text'] ?? null)) {
                continue;
            }

            // FACT — this symptom was present on this vehicle.
            $this->recorder->record(DomainEvent::MEASUREMENT_RECORDED, [
                'kind'     => 'finding',
                'text'     => $finding['text'],
                'severity' => $finding['severity'] ?? null,
                'source'   => $finding['source'] ?? 'inspector',
            ], $who, $subject);

            // JUDGEMENT — and only when the inspector actually reached one. An absent root cause is
            // recorded as absent rather than guessed, because a fabricated diagnosis is worse for
            // learning than a missing one.
            if (filled($finding['root_cause'] ?? null)) {
                $this->recorder->record(DomainEvent::DIAGNOSIS_MADE, [
                    'symptom'       => $finding['text'],
                    'root_cause'    => $finding['root_cause'],
                    'root_cause_id' => $finding['root_cause_id'] ?? null,
                    'severity'      => $finding['severity'] ?? null,
                ], $who, $subject);
            }
        }
    }

    /**
     * A repair verdict from re-inspection — our own QA, deliberately not the party that did the work.
     */
    public function repairVerified(Maintenance $ticket, User $actor, string $result, ?string $reason = null): void
    {
        $this->safely('repairVerified', $ticket, function () use ($ticket, $actor, $result, $reason) {
            $this->recorder->record(DomainEvent::VERIFICATION_COMPLETED, [
                'result'         => $result,          // fixed | still_exists
                'failure_reason' => $reason,
                'vendor_id'      => $ticket->vendor_id,
            ], Provenance::staff($actor, Provenance::METHOD_VISUAL), $this->subject($ticket));
        });
    }

    /**
     * The ticket closed. `VehicleReleased` is a fact; the garage's implicit claim that the work is
     * done is recorded separately as a judgement, and attributed to the garage rather than to us —
     * so that when the observed outcome is computed in ninety days, there is something to compare it
     * against and someone to attribute the difference to.
     */
    public function ticketClosed(Maintenance $ticket, User $actor): void
    {
        $this->safely('ticketClosed', $ticket, function () use ($ticket, $actor) {
            $this->recorder->session(function () use ($ticket, $actor) {
                $subject = $this->subject($ticket);

                $this->recorder->record(DomainEvent::VEHICLE_RELEASED, [
                    'closed_at'  => optional($ticket->wf_closed_at)->toIso8601String(),
                    'vendor_id'  => $ticket->vendor_id,
                    'odometer'   => is_numeric($ticket->return_odometer) ? (int) $ticket->return_odometer : null,
                ], Provenance::staff($actor), $subject);

                if ($ticket->vendor_id) {
                    $this->recorder->record(DomainEvent::OUTCOME_CLAIMED, [
                        'claim'  => 'work_completed',
                        'source' => 'ticket_close',
                        // Explicitly null until the capture step exists. Recorded as unknown rather
                        // than assumed complete: "closed" is a workflow state, not a repair outcome,
                        // and treating one as the other is how a dataset learns that every repair
                        // works.
                        'claimed_outcome' => null,
                    ], Provenance::garage((int) $ticket->vendor_id), $subject);
                }
            });
        });
    }

    /** @return array<string,int|null> */
    private function subject(Maintenance $ticket): array
    {
        return [
            'vehicle_id'     => $ticket->vehicle_id,
            'maintenance_id' => $ticket->id,
        ];
    }

    /** See the class doc: the user's work must never fail because the log could not be written. */
    private function safely(string $step, Maintenance $ticket, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::error("Capture translation failed at '{$step}'", [
                'maintenance_id' => $ticket->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
