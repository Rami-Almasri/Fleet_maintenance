<?php

namespace Tests\Unit;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\User;
use App\Services\FindingApprovalService;
use Tests\TestCase;

/**
 * THE HOLD, tested at the only place it can be tested without a car: the rules.
 *
 * The three things that must be true, and were not before this existed:
 *   1. a routine the car's own status says is not due is HELD, not merely warned about;
 *   2. a held finding never becomes work, and neither does a rejected one;
 *   3. a ticket carrying a held finding cannot move — in ANY direction, including closed.
 *
 * Everything here works on findings JSON and an unsaved Maintenance row, so the suite stays free of the
 * database (see [[migrations-cannot-run-from-empty]]).
 */
class FindingApprovalTest extends TestCase
{
    private function svc(): FindingApprovalService
    {
        return app(FindingApprovalService::class);
    }

    private function actor(): User
    {
        return new User(['name' => 'Abu Maroof']);
    }

    /** A finding with nothing wrong with it is not questioned — the overwhelmingly common case. */
    public function test_an_ordinary_finding_is_not_held(): void
    {
        $ticket = new Maintenance(['findings' => []]);

        // No vehicle ⇒ the repeat lookup has nothing to look in; no status_check ⇒ nothing is due-checked.
        $this->assertNull($this->svc()->challenge('Engine noise', null, $ticket, null, $this->actor()));
    }

    /** "Change the oil" on a car whose own status says the oil is not due. */
    public function test_a_routine_the_car_says_is_not_due_is_held(): void
    {
        $ticket = new Maintenance(['findings' => []]);

        $approval = $this->svc()->challenge(
            'Oil Change',
            null,
            $ticket,
            ['status' => 'ok', 'summary' => '5,415 km left of the 7,000 km limit'],
            $this->actor(),
        );

        $this->assertNotNull($approval);
        $this->assertSame(FindingApprovalService::STATE_PENDING, $approval['state']);
        $this->assertSame(FindingApprovalService::REASON_NOT_NEEDED, $approval['reason']);
        // WHO TRIED is stamped at the moment of the attempt, not reconstructed later.
        $this->assertSame('Abu Maroof', $approval['requested_by']);
        // The measured figure rides along as a param — the sentence is composed on screen.
        $this->assertSame('5,415 km left of the 7,000 km limit', $approval['params']['summary']);
    }

    /**
     * A decision already taken on this ticket survives the report being re-filed. Without this an
     * inspector could launder a rejected finding by submitting the same form again.
     */
    public function test_a_decision_survives_the_report_being_refiled(): void
    {
        $prior = [[
            'text'     => 'Oil Change',
            'approval' => [
                'state'      => FindingApprovalService::STATE_REJECTED,
                'reason'     => FindingApprovalService::REASON_NOT_NEEDED,
                'decided_by' => 'Lin',
            ],
        ]];
        $ticket = new Maintenance(['findings' => $prior]);

        $approval = $this->svc()->challenge(
            'oil  change',   // same finding, sloppier typing
            null,
            $ticket,
            ['status' => 'ok', 'summary' => 'not due'],
            $this->actor(),
            $prior,
        );

        $this->assertSame(FindingApprovalService::STATE_REJECTED, $approval['state']);
        $this->assertSame('Lin', $approval['decided_by']);
    }

    /** A still-pending hold is re-derived, not carried: the car may have become due since. */
    public function test_a_pending_hold_is_re_derived_when_the_car_became_due(): void
    {
        $prior = [[
            'text'     => 'Oil Change',
            'approval' => ['state' => FindingApprovalService::STATE_PENDING, 'reason' => 'not_needed'],
        ]];
        $ticket = new Maintenance(['findings' => $prior]);

        // The rental put 900 km on the car; the status no longer says "ok", so there is nothing to hold.
        $this->assertNull(
            $this->svc()->challenge('Oil Change', null, $ticket, null, $this->actor(), $prior),
        );
    }

    /** Held and refused findings are both kept out of the workable fault list; approved ones promote. */
    public function test_only_a_settled_approval_lets_a_finding_become_work(): void
    {
        $this->assertTrue(FindingApprovalService::blocksPromotion(['approval' => ['state' => 'pending']]));
        $this->assertTrue(FindingApprovalService::blocksPromotion(['approval' => ['state' => 'rejected']]));
        $this->assertFalse(FindingApprovalService::blocksPromotion(['approval' => ['state' => 'approved']]));
        // A finding nobody questioned carries no approval block at all.
        $this->assertFalse(FindingApprovalService::blocksPromotion(['text' => 'Engine noise']));
    }

    /**
     * The hold itself. Blocking EVERY destination — closing included — is the point: a ticket closed
     * with an unapproved oil change on it is the outcome this exists to prevent.
     */
    public function test_a_held_finding_stops_the_ticket_moving(): void
    {
        $ticket = new Maintenance([
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'findings'        => [[
                'text'     => 'Oil Change',
                'approval' => [
                    'state'  => FindingApprovalService::STATE_PENDING,
                    'reason' => FindingApprovalService::REASON_NOT_NEEDED,
                    'params' => ['summary' => '5,415 km left of the 7,000 km limit'],
                ],
            ]],
        ]);

        foreach ([Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_CLOSED] as $target) {
            try {
                $this->svc()->assertNothingPending($ticket, $target);
                $this->fail("Moving to {$target} should have been refused while a finding is held.");
            } catch (WorkflowTransitionException $e) {
                $this->assertStringContainsString('Oil Change', $e->getMessage());
                // The refusal SAYS why, with the measured figure — not "blocked by a rule".
                $this->assertStringContainsString('5,415 km', $e->getMessage());
            }
        }
    }

    /**
     * Both alert types must be claimed by a real inbox tab. A type nobody claims falls through to the
     * catch-all `other` bucket — the alert is still delivered, but it lands in the drawer nobody opens,
     * and an unanswered hold freezes the ticket indefinitely.
     */
    public function test_both_alerts_are_filed_under_a_real_inbox_tab(): void
    {
        foreach (['maint_finding_approval', 'maint_finding_approval_decided'] as $type) {
            $this->assertSame(
                'progress',
                \App\Support\NotificationCategories::categoryOf($type),
                "{$type} must not fall through to the catch-all tab"
            );
        }
    }

    /**
     * The ASK is the approvers' business; the OUTCOME must reach the person who logged the finding,
     * who holds `maintenance.view` and not `maintenance.manage`. An unmapped type falls through to
     * "open to everyone" (see NotificationScanner::userMayReceive), so both must be stated.
     */
    public function test_each_alert_is_gated_to_the_people_who_need_it(): void
    {
        $map = (new \ReflectionClass(\App\Services\NotificationScanner::class))
            ->getConstant('ALERT_PERMISSIONS');

        $this->assertSame('maintenance.manage', $map['maint_finding_approval'] ?? null);
        // NOT the approvers' permission — that would hide the answer from whoever is waiting on it.
        $this->assertSame('maintenance.view', $map['maint_finding_approval_decided'] ?? null);
    }

    /** Rejecting is always the way out, so a ticket can never be stranded waiting on nobody. */
    public function test_a_decided_finding_stops_blocking(): void
    {
        $ticket = new Maintenance([
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'findings'        => [
                ['text' => 'Oil Change',   'approval' => ['state' => FindingApprovalService::STATE_REJECTED]],
                ['text' => 'Engine noise', 'approval' => ['state' => FindingApprovalService::STATE_APPROVED]],
            ],
        ]);

        $this->svc()->assertNothingPending($ticket, Maintenance::WF_AWAITING_DISPATCH);
        $this->assertSame([], $this->svc()->pending($ticket));
    }
}
