<?php

namespace App\Services\Accident\Requirements;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\AccidentStageCompletion;
use App\Models\AccidentWorkflowStage;
use App\Models\VehicleDocument;

/**
 * One gate a stage can carry. Part of the fixed vocabulary an admin chooses from — never a rule they
 * write. @see StageRequirement and \App\Services\Accident\RequirementRegistry for the whole set
 * and why it is closed.
 */
/**
 * THE RENTER HAS BEEN BILLED — or was never the one who owed it.
 *
 * Satisfied automatically when the verdict cleared the customer, so a stage carrying this gate does
 * not strand a case that has nobody to charge. (It would normally also carry the `customer_liable`
 * condition, which skips it outright — this is the belt to that pair of braces.)
 */
class CustomerChargedRequirement implements StageRequirement
{
    public function key(): string { return 'customer_charged'; }
    public function label(): string { return 'Customer charged (or not liable)'; }
    public function description(): string
    {
        return 'The renter’s share is on their account. Satisfied automatically when the liability '
             . 'verdict did not put the money on them.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        $chargeable = in_array($case->liability_status, [
            AccidentCase::LIABILITY_CUSTOMER, AccidentCase::LIABILITY_SHARED,
        ], true);

        return ! $chargeable || $case->customerCharge()->exists();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return 'The customer has not been charged for their share yet.';
    }
}
