<?php

namespace App\Services;

use App\Evidence\EventRecorder;
use App\Evidence\Provenance;
use App\Models\DomainEvent;
use App\Models\MaintenanceTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Verify repair" — the inspector's independent confirmation, and a separate act from the repair.
 *
 * The workshop says *we repaired it*. The inspector says *we verified it*. Only then can the system
 * later ask *was it actually successful?* and mean anything by the answer. Collapsing those into one
 * form — which is what the first version did — produced verifications that were self-reported by
 * construction, and a supplier-quality metric built on a garage confirming its own work measures
 * nothing but that garage's optimism.
 *
 * SEPARATION IS ENFORCED BY IDENTITY, NOT BY PERMISSION. `inspections.manage` is necessary but not
 * sufficient: the `maintenance` role already holds it, so a single user could clear both gates and
 * verify their own repair without a permission check ever objecting. The rule that actually holds is
 * the comparison below — the claimant cannot be the verifier. A guarantee that depends on nobody
 * being granted two roles is not a guarantee.
 *
 * DISAGREEMENT IS THE POINT. An inspector recording `still_faulty` against a workshop's `complete`
 * is not an error to be reconciled; it is the most valuable row in the dataset, and the reason both
 * verdicts are stored rather than one overwriting the other.
 */
class RepairVerificationService
{
    public const RESULT_VERIFIED     = 'verified';       // the repair holds
    public const RESULT_STILL_FAULTY = 'still_faulty';   // the fault is still there
    public const RESULT_PARTIAL      = 'partial';        // improved, not resolved
    public const RESULT_INCONCLUSIVE = 'inconclusive';   // could not tell — honest, and allowed

    public const RESULTS = [
        self::RESULT_VERIFIED, self::RESULT_STILL_FAULTY,
        self::RESULT_PARTIAL, self::RESULT_INCONCLUSIVE,
    ];

    public function __construct(private readonly EventRecorder $recorder)
    {
    }

    /**
     * Record an independent verification.
     *
     * @param  array{result:string,method:string,note?:string|null}  $data
     */
    public function verify(MaintenanceTask $task, array $data, User $inspector): MaintenanceTask
    {
        $result = $data['result'] ?? null;
        $method = $data['method'] ?? null;

        $errors = [];

        if (! in_array($result, self::RESULTS, true)) {
            $errors['result'] = ['Record what you found: verified, still faulty, partial, or inconclusive.'];
        }

        if (! in_array($method, RepairCaptureService::VERIFICATION_METHODS, true)) {
            $errors['method'] = ['Choose how you checked it.'];
        }

        // There must be a repair to verify. Verifying a fault nobody has recorded work on would
        // create a confirmation with nothing underneath it.
        if ($task->claimed_outcome === null && ! $task->no_fault_found) {
            $errors['result'] = ['This repair has not been recorded yet — the workshop records what was done first.'];
        }

        // THE SEPARATION RULE. Blocked in the service rather than the controller so it holds for
        // every caller, including any future import or portal path.
        if ($task->claimed_outcome_by !== null && (int) $task->claimed_outcome_by === (int) $inspector->id) {
            $errors['result'] = ['You recorded this repair — someone else has to verify it.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($task, $result, $method, $data, $inspector) {
            $task->forceFill([
                'verification_result' => $result,
                'verification_method' => $method,
                'verification_note'   => $data['note'] ?? null,
                'verified_by'         => $inspector->id,
                'verified_at'         => now(),
            ])->save();
        });

        $this->recordEvent($task, $result, $method, $inspector);

        return $task->fresh();
    }

    /** The independent verification event — full trust, because it genuinely is independent. */
    private function recordEvent(MaintenanceTask $task, string $result, string $method, User $inspector): void
    {
        try {
            $this->recorder->record(DomainEvent::VERIFICATION_COMPLETED, [
                'method'      => $method,
                'result'      => $result,
                'vendor_id'   => $task->current_vendor_id,
                'independent' => true,
                // Stored alongside so the disagreement is legible in the event itself, without a
                // consumer having to join back to the task to notice it.
                'claimed_outcome' => $task->claimed_outcome,
                'contradicts_claim' => $task->claimed_outcome === RepairCaptureService::OUTCOME_COMPLETE
                    && $result !== self::RESULT_VERIFIED,
            ], Provenance::staff($inspector, Provenance::METHOD_VISUAL), [
                'vehicle_id'          => $task->vehicle_id,
                'maintenance_id'      => $task->maintenance_id,
                'maintenance_task_id' => $task->id,
            ]);
        } catch (Throwable $e) {
            // The verification is saved; the log is a consequence. Never fail the inspector's work
            // because an analytics write threw.
            Log::error('Repair verification: event recording failed', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Whether this user may verify this fault right now, and if not, why. */
    public function eligibility(MaintenanceTask $task, User $user): array
    {
        if ($task->claimed_outcome === null && ! $task->no_fault_found) {
            return ['can_verify' => false, 'reason' => 'The workshop has not recorded this repair yet.'];
        }

        if ($task->claimed_outcome_by !== null && (int) $task->claimed_outcome_by === (int) $user->id) {
            return ['can_verify' => false, 'reason' => 'You recorded this repair — someone else has to verify it.'];
        }

        return ['can_verify' => true, 'reason' => null];
    }
}
