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
 * A DOCUMENT OF A NAMED KIND IS ON FILE. Covers the insurer's inspection (they photograph the car),
 * a signed settlement, a legal letter — anything whose proof is a file rather than a decision.
 */
class DocumentUploadedRequirement implements StageRequirement
{
    public function key(): string { return 'document_uploaded'; }
    public function label(): string { return 'A document must be uploaded'; }
    public function description(): string
    {
        return 'The stage stays open until a file of the chosen kind is on the case — the insurer’s '
             . 'photographs, a settlement letter, a third-party report.';
    }

    public function configSchema(): array
    {
        return [
            'kinds' => ['type' => 'multi_select', 'label' => 'Which kinds count?',
                        'options' => VehicleDocument::ACCIDENT_KINDS],
            'min'   => ['type' => 'number', 'label' => 'How many are needed?', 'default' => 1],
        ];
    }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        $kinds = $stage->requirement_config['kinds'] ?? VehicleDocument::ACCIDENT_KINDS;
        $min   = max(1, (int) ($stage->requirement_config['min'] ?? 1));

        return $case->documents()->whereIn('kind', $kinds)->count() >= $min;
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        $kinds = $stage->requirement_config['kinds'] ?? VehicleDocument::ACCIDENT_KINDS;
        $names = array_map(fn ($k) => VehicleDocument::KINDS[$k] ?? $k, $kinds);

        return 'No document has been uploaded yet. This stage needs one of: ' . implode(', ', $names) . '.';
    }
}
