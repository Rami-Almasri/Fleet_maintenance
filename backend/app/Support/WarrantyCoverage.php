<?php

namespace App\Support;

/**
 * The vocabulary of the question "could somebody else be responsible for this?".
 *
 * THREE ANSWERS, AND THE THIRD ONE IS THE FEATURE. Most systems that model warranty model it as a
 * boolean and are therefore forced to answer every question with a guess. This one has a third
 * answer — UNKNOWN — and treats it as a work item rather than as a failure to decide. A warranty
 * booklet nobody has itemised genuinely does not say whether a wheel bearing is covered; pretending
 * otherwise in either direction costs money in both directions:
 *
 *   guess COVERED     → we wait three weeks for a dealer who was never going to pay, on a car that
 *                       could have been repaired and back on hire the same afternoon.
 *   guess NOT_COVERED → we buy a gearbox the manufacturer owed us, and nobody ever finds out.
 *
 * So UNKNOWN routes to a human, and the human's answer is written down against the (warranty, part
 * type) pair so the same question is never asked twice. The system learns by being TOLD.
 * [[treat-data-as-source-of-truth]] — there is no score here, no confidence, no inference.
 *
 * ── REASON CODES, NOT SENTENCES ────────────────────────────────────────────────────────────────
 * Every verdict carries a code, never English ([[reason-code-contract]]). The code is what the
 * filters group by, what Arabic renders from, and what a test can assert on. The sentence a user
 * reads is composed by the UI from the code plus its params; nothing in this engine ever authors
 * one. Adding a reason means adding a constant here and a phrase in the frontend labels — never a
 * string concatenation in a service.
 */
final class WarrantyCoverage
{
    // ── Verdicts ───────────────────────────────────────────────────────────────────────────────

    /** Somebody else is on the hook. Procurement stops; the warranty path opens. */
    public const COVERED = 'covered';

    /** Confirmed ours to pay. The existing procurement workflow proceeds untouched. */
    public const NOT_COVERED = 'not_covered';

    /**
     * Nobody has decided yet. NOT a soft "probably not" — procurement is held exactly as it is for
     * COVERED, because an unreviewed maybe and a confirmed yes cost the same if we spend on both.
     */
    public const UNKNOWN = 'unknown';

    public const VERDICTS = [self::COVERED, self::NOT_COVERED, self::UNKNOWN];

    /**
     * The verdicts that STOP a normal purchase request. Both of them, on purpose — see UNKNOWN.
     * This constant is the single definition of "the gate is closed"; the guard, the API and the
     * frontend all read it rather than each writing their own `!== not_covered`.
     */
    public const BLOCKING = [self::COVERED, self::UNKNOWN];

    // ── Reason codes: why the engine landed where it did ───────────────────────────────────────

    /** The car has no live warranty of any kind. The overwhelming majority of the fleet. */
    public const R_NO_LIVE_COVER = 'no_live_cover';

    /** Every warranty that could have applied has run out — on time, on distance, or was voided. */
    public const R_COVER_EXPIRED = 'cover_expired';

    /** A live warranty names this part type in its exclusions. Their document, their words. */
    public const R_EXPLICITLY_EXCLUDED = 'explicitly_excluded';

    /** A live warranty names this part type in what it covers. */
    public const R_EXPLICITLY_COVERED = 'explicitly_covered';

    /** The fitted component itself carries live cover — the battery's own warranty, not the car's. */
    public const R_COMPONENT_COVER_LIVE = 'component_cover_live';

    /** The garage still owes us this exact fault under a live repair warranty. A comeback. */
    public const R_REPAIR_COVER_LIVE = 'repair_cover_live';

    /**
     * A live warranty exists and says nothing either way about this part type. THE DEFAULT for an
     * un-itemised booklet, and the reason most reviews get opened.
     */
    public const R_COVER_NOT_ITEMISED = 'cover_not_itemised';

    /**
     * A distance-bounded warranty was judged with no odometer reading, so whether it is still live
     * cannot be answered at all. Reported as unknown rather than quietly assumed to hold — see
     * Warranty::evaluate(), which refuses the same assumption one layer down.
     */
    public const R_ODOMETER_UNKNOWN = 'odometer_unknown';

    /** A human already answered this exact question. Their decision wins over every rule above. */
    public const R_DECIDED_BY_REVIEW = 'decided_by_review';

    /** An open case already covers this subject — the question is asked, just not yet answered. */
    public const R_REVIEW_IN_PROGRESS = 'review_in_progress';

    /**
     * Every live warranty on this car HAS an itemised list of what it covers, and this part type is
     * on none of them.
     *
     * Distinct from R_EXPLICITLY_EXCLUDED (they wrote it down as excluded) and from
     * R_COVER_NOT_ITEMISED (nobody has read the booklet yet). It is a NOT_COVERED verdict and it is
     * only reachable once somebody has done the work of itemising — which is exactly the point:
     * the answer gets more certain as the data gets better, and never before.
     */
    public const R_NOT_IN_ITEMISED_COVER = 'not_in_itemised_cover';

    public const REASONS = [
        self::R_NO_LIVE_COVER, self::R_COVER_EXPIRED,
        self::R_EXPLICITLY_EXCLUDED, self::R_EXPLICITLY_COVERED,
        self::R_COMPONENT_COVER_LIVE, self::R_REPAIR_COVER_LIVE,
        self::R_COVER_NOT_ITEMISED, self::R_NOT_IN_ITEMISED_COVER, self::R_ODOMETER_UNKNOWN,
        self::R_DECIDED_BY_REVIEW, self::R_REVIEW_IN_PROGRESS,
    ];

    // ── The car's own warranty headline ────────────────────────────────────────────────────────
    //
    // The three states the vehicle list and the vehicle page show. They are a ROLL-UP across every
    // warranty on the car, not a column, and they are computed from the same evaluate() the register
    // uses — so a car cannot read "under warranty" on one page and "expired" on another.

    /** At least one warranty is live today, on both legs. */
    public const STATE_UNDER_WARRANTY = 'under_warranty';

    /**
     * Live, but close enough to the end — on months OR on kilometres — that acting later means not
     * acting at all. This is where the pre-expiry inspection is born.
     */
    public const STATE_EXPIRING_SOON = 'expiring_soon';

    /** The car had cover and it has run out. Different from never having had any. */
    public const STATE_EXPIRED = 'expired';

    /** No warranty was ever recorded for this car. Says nothing about whether one exists. */
    public const STATE_NONE = 'none';

    /** Display order, most → least protected. The vehicle list sorts and the tiles count by this. */
    public const STATES = [
        self::STATE_UNDER_WARRANTY, self::STATE_EXPIRING_SOON, self::STATE_EXPIRED, self::STATE_NONE,
    ];

    /**
     * Is this verdict one that must stop a purchase request?
     *
     * The single place the question is answered, so a future fourth verdict cannot accidentally
     * default to "let it through" in one of the four callers.
     */
    public static function blocks(?string $verdict): bool
    {
        return in_array($verdict, self::BLOCKING, true);
    }
}
