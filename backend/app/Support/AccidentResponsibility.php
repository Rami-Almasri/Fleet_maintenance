<?php

namespace App\Support;

/**
 * WHO may do what on an accident case, and who gets told — expressed as capabilities, never as
 * people. The same rule, and the same reasoning, as {@see WarrantyResponsibility}: a user id in a
 * service is a bug with a delay fuse.
 *
 * ── WHY NINE PERMISSIONS AND NOT `accidents.view` / `accidents.manage` ─────────────────────────
 *
 * Because an accident case contains four decisions of genuinely different weight, and a view/manage
 * pair would make three of the guardrails decorative:
 *
 *   REPORTING one     is something anybody who touches a car should be able to do at the roadside.
 *                     A crash that goes unreported because the reporter lacked a permission is the
 *                     worst outcome this feature can produce, so the bar here is deliberately low.
 *   VERIFYING the     police report is asserting that somebody read the document against the file.
 *                     If the person who uploads it is the person who verifies it, the verification
 *                     step means nothing.
 *   DECIDING          liability decides who pays. It is the single most contested field on the row.
 *   THE MONEY         approved amounts and settlements are finance's, not the workshop's.
 *
 * And one more, which is the one most systems leave out:
 *
 *   OVERRIDING        waiving the police report, or reopening a closed case. Not a rule being
 *                     broken — a rule being applied and then consciously set aside, which is the
 *                     only version of this that is safe to allow. Always with a name and a reason.
 */
final class AccidentResponsibility
{
    /** See the accident board, a case, and the dashboard tiles. */
    public const VIEW = 'accidents.view';

    /** Report an accident — open the case. The lowest bar in the feature, on purpose. */
    public const REPORT = 'accidents.report';

    /** Edit the narrative: what happened, damage items, the assessment, documents, repair links. */
    public const MANAGE = 'accidents.manage';

    /** Assert that the police report has been read against the file. Never the uploader's own act. */
    public const VERIFY_POLICE = 'accidents.police.verify';

    /** Decide whose fault it was. The most contested field on the case. */
    public const LIABILITY = 'accidents.liability';

    /** Run the insurance claim: submit it, record what the insurer said. */
    public const INSURANCE = 'accidents.insurance';

    /** Record and read the money — estimates, approvals, actuals, settlements. */
    public const FINANCIALS = 'accidents.financials';

    /** Close a case. Everything above must have an answer, or a recorded reason for not having one. */
    public const CLOSE = 'accidents.close';

    /** Waive a required document; reopen a closed case. Audited by name, reason mandatory. */
    public const OVERRIDE = 'accidents.override';

    public const ALL = [
        self::VIEW, self::REPORT, self::MANAGE, self::VERIFY_POLICE, self::LIABILITY,
        self::INSURANCE, self::FINANCIALS, self::CLOSE, self::OVERRIDE,
    ];

    // ── The audiences ──────────────────────────────────────────────────────────────────────────

    /** The desk that works accident cases: chases the police report, drives the case forward. */
    public static function accidentDesk(): array
    {
        return [self::MANAGE];
    }

    /** Whoever decides fault. Told when a case is waiting on that decision. */
    public static function liabilityAuthority(): array
    {
        return [self::LIABILITY];
    }

    /** Finance. Told when an insurer answers and when a settlement is outstanding. */
    public static function financeAuthority(): array
    {
        return [self::FINANCIALS];
    }

    /**
     * Told when a car crashes while it was on hire — because that is simultaneously an operational
     * problem (a customer is stranded, a replacement is needed) and a commercial one (the contract
     * keeps running and somebody has to decide what to do about it). The rental desk finds out from
     * here, not from a phone call three days later.
     */
    public static function rentalDesk(): array
    {
        return ['contracts.manage'];
    }
}
