<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use Illuminate\Support\Collection;

/**
 * A fault ruled INCORRECT must not generate NEW repair cost — and must never have its OLD cost hidden.
 *
 * Those two rules pull in opposite directions, which is why they live in one place. Marking a fault
 * incorrect is a statement that the car did not actually have this problem, so from that moment nothing
 * further may be billed to it: no invoice may cover it, no part or labour line may name it, no part may be
 * requested, bought or fitted against it. This guard is what refuses those writes.
 *
 * What it deliberately does NOT do is subtract money already spent. If the wrong diagnosis was made after
 * a 400 AED part was already bought and fitted, that 400 AED left the company. Deleting it would leave the
 * ticket unable to reconcile against the garage's paper, and would erase precisely the number worth
 * measuring. It stays in the ticket total and is reported separately as the cost of a wrong diagnosis —
 * see TicketCostJourneyService, which surfaces it under its own heading rather than blending it in.
 *
 * Evidence class: J (judgement — "this fault was mis-diagnosed" is a human ruling, not a fact) consumed
 * to gate F (facts — invoices, purchases, line items).
 *
 * @see MaintenanceTask::isIncorrect()
 */
class IncorrectFaultCostGuard
{
    /**
     * Refuse a set of fault ids if any of them was ruled incorrect.
     *
     * @param array<int> $taskIds
     * @throws WorkflowTransitionException
     */
    public function assertNoneIncorrect(array $taskIds, string $action = 'billed'): void
    {
        $ids = array_values(array_filter(array_map('intval', $taskIds)));
        if (! $ids) {
            return;
        }

        $incorrect = MaintenanceTask::whereIn('id', $ids)
            ->whereNotNull('marked_incorrect_at')
            ->get(['id', 'symptom']);

        if ($incorrect->isNotEmpty()) {
            throw new WorkflowTransitionException(
                $this->message($incorrect->pluck('symptom')->all(), $action),
                ['field' => 'task_ids', 'incorrect_task_ids' => $incorrect->pluck('id')->all()],
            );
        }
    }

    /**
     * Refuse a single fault if it was ruled incorrect. Used on the parts path, where the caller holds the
     * task (or nothing at all — an ad-hoc buy with no fault is always allowed through).
     *
     * @throws WorkflowTransitionException
     */
    public function assertNotIncorrect(?MaintenanceTask $task, string $action = 'billed'): void
    {
        if ($task && $task->isIncorrect()) {
            throw new WorkflowTransitionException(
                $this->message([$task->symptom], $action),
                ['field' => 'maintenance_task_id', 'incorrect_task_ids' => [$task->id]],
            );
        }
    }

    /**
     * The incorrect faults on a ticket, keyed by their normalised symptom — the form line items are matched
     * on (mirrors MaintenanceInvoiceService's Diagnosis-First lookup, which compares lowercased finding
     * text). Returned as a lookup so a caller validating many lines does one query, not one per line.
     *
     * @return Collection<string, MaintenanceTask> symptom (lowercased, trimmed) => task
     */
    public function incorrectSymptoms(Maintenance $ticket): Collection
    {
        return $ticket->tasks()
            ->whereNotNull('marked_incorrect_at')
            ->get(['id', 'symptom'])
            ->keyBy(fn (MaintenanceTask $t) => mb_strtolower(trim((string) $t->symptom)));
    }

    /**
     * Refuse a cost line whose finding names an incorrect fault.
     *
     * @param Collection<string, MaintenanceTask> $incorrect from {@see incorrectSymptoms()}
     * @throws WorkflowTransitionException
     */
    public function assertLineNotOnIncorrectFault(?string $findingText, Collection $incorrect, string $action = 'charged to'): void
    {
        if ($findingText === null || $incorrect->isEmpty()) {
            return;
        }

        $task = $incorrect->get(mb_strtolower(trim($findingText)));
        if ($task) {
            throw new WorkflowTransitionException(
                $this->message([$task->symptom], $action),
                ['field' => 'finding_text', 'incorrect_task_ids' => [$task->id]],
            );
        }
    }

    /** @param array<int, string|null> $symptoms */
    private function message(array $symptoms, string $action): string
    {
        $named = collect($symptoms)->filter()->map(fn ($s) => '“' . $s . '”')->implode(', ');
        $subject = $named !== '' ? $named : 'That fault';
        $plural  = count(array_filter($symptoms)) > 1;

        return $subject . ($plural ? ' were' : ' was') . ' ruled an incorrect diagnosis, so ' . ($plural ? 'they' : 'it')
            . ' cannot be ' . $action . '. Money already spent on ' . ($plural ? 'them' : 'it')
            . ' stays on the ticket and is reported as wrong-diagnosis cost.';
    }
}
