<?php

namespace App\Services\Accident;

use App\Services\Accident\Requirements\CustomerChargedRequirement;
use App\Services\Accident\Requirements\DamageAssessmentRequirement;
use App\Services\Accident\Requirements\DocumentUploadedRequirement;
use App\Services\Accident\Requirements\InsuranceDecisionRequirement;
use App\Services\Accident\Requirements\LiabilityDecisionRequirement;
use App\Services\Accident\Requirements\ManualConfirmationRequirement;
use App\Services\Accident\Requirements\NoRequirement;
use App\Services\Accident\Requirements\PoliceReportRequirement;
use App\Services\Accident\Requirements\RepairLinkedRequirement;
use App\Services\Accident\Requirements\SettlementRecordedRequirement;
use App\Services\Accident\Requirements\StageRequirement;

/**
 * THE LIST OF GATES AN ADMIN MAY CHOOSE FROM — and the boundary of what this system will evaluate.
 *
 * Everything the configuration screen offers comes from here, and nothing outside here can ever be
 * stored on a stage: `get()` on an unknown key returns the no-op rather than throwing, so a stage row
 * that somehow carries a stale key from a rolled-back deploy degrades to "no gate" instead of taking
 * the whole board down. Loud in validation, quiet at read time — an admin typing a bad key is caught
 * when they save; a case reading one is never left unopenable.
 */
class RequirementRegistry
{
    /** @var array<string, StageRequirement>|null */
    private ?array $map = null;

    /** @return array<string, StageRequirement> */
    public function all(): array
    {
        return $this->map ??= collect([
            new NoRequirement(),
            new ManualConfirmationRequirement(),
            new DocumentUploadedRequirement(),
            new PoliceReportRequirement(),
            new DamageAssessmentRequirement(),
            new LiabilityDecisionRequirement(),
            new CustomerChargedRequirement(),
            new InsuranceDecisionRequirement(),
            new RepairLinkedRequirement(),
            new SettlementRecordedRequirement(),
        ])->keyBy(fn (StageRequirement $r) => $r->key())->all();
    }

    public function get(string $key): StageRequirement
    {
        return $this->all()[$key] ?? new NoRequirement();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** The shape the configuration screen renders — what each gate is and whether it needs setting up. */
    public function options(): array
    {
        return array_values(array_map(fn (StageRequirement $r) => [
            'key'         => $r->key(),
            'label'       => $r->label(),
            'description' => $r->description(),
            'config'      => $r->configSchema(),
        ], $this->all()));
    }
}
