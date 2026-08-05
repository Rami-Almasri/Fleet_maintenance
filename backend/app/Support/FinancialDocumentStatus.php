<?php

namespace App\Support;

/**
 * One status vocabulary for every financial document in the platform.
 *
 * A supplier invoice, a garage invoice, a credit note and an adjustment are different things, but the
 * questions asked of them are identical: has it been approved, has it been paid, was it cancelled, has
 * some of it come back? Giving each document its own private words for those states is how a procurement
 * system becomes unreadable — so they all answer in these terms, and each model maps its own internal
 * fields onto them ({@see \App\Models\Concerns\IsFinancialDocument}).
 *
 * Two of the statuses are DERIVED and never stored, because they are statements about money rather than
 * about a decision someone took:
 *
 *     PARTIALLY_PAID     — paid_amount is above zero but below the total
 *     PARTIALLY_REFUNDED — some of the document has come back as credit
 *
 * Deriving them means the status can never disagree with the figures underneath it, which is the same
 * discipline the rest of the cost chain follows: totals are summed from rows, never typed alongside them.
 */
final class FinancialDocumentStatus
{
    /** Being written. Not yet submitted to anyone, and not yet a claim on the business. */
    public const DRAFT = 'draft';
    /** Submitted and waiting for someone to approve it. */
    public const PENDING = 'pending';
    /** Approved — accepted as a real, payable obligation. */
    public const APPROVED = 'approved';
    /** Some money has been paid against it, but not all. DERIVED from paid_amount. */
    public const PARTIALLY_PAID = 'partially_paid';
    /** Settled in full. */
    public const PAID = 'paid';
    /** Part of it has come back as a credit note. DERIVED from the returns against it. */
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    /** All of it has come back. DERIVED. */
    public const REFUNDED = 'refunded';
    /** Withdrawn. It is not, and never will be, an obligation — but the record stays. */
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::DRAFT,
        self::PENDING,
        self::APPROVED,
        self::PARTIALLY_PAID,
        self::PAID,
        self::PARTIALLY_REFUNDED,
        self::REFUNDED,
        self::CANCELLED,
    ];

    /** The statuses a document can be SET to. The rest are derived from money and cannot be assigned. */
    public const ASSIGNABLE = [self::DRAFT, self::PENDING, self::APPROVED, self::PAID, self::CANCELLED];

    /** Derived from figures, never written by a user. */
    public const DERIVED = [self::PARTIALLY_PAID, self::PARTIALLY_REFUNDED, self::REFUNDED];

    /** Nothing further happens to a document in one of these. */
    public const TERMINAL = [self::PAID, self::REFUNDED, self::CANCELLED];

    /**
     * A document only counts as a real obligation from APPROVED onward. Draft and pending documents are
     * proposals, and cancelled ones are withdrawn — none of them should be added into what we owe.
     */
    public const COMMITTED = [
        self::APPROVED,
        self::PARTIALLY_PAID,
        self::PAID,
        self::PARTIALLY_REFUNDED,
        self::REFUNDED,
    ];

    /** Which moves are legal. Derived statuses never appear as a FROM: they resolve back to their base. */
    public const TRANSITIONS = [
        self::DRAFT     => [self::PENDING, self::APPROVED, self::CANCELLED],
        self::PENDING   => [self::APPROVED, self::DRAFT, self::CANCELLED],
        // An approved document can be paid, or pulled back to pending if the approval was wrong.
        self::APPROVED  => [self::PAID, self::PENDING, self::CANCELLED],
        // Part-paid is a money state, so the only decisions left are finishing payment or cancelling.
        self::PARTIALLY_PAID => [self::PAID, self::CANCELLED],
        self::PAID      => [self::CANCELLED],
        self::PARTIALLY_REFUNDED => [self::PAID, self::CANCELLED],
        self::REFUNDED  => [self::CANCELLED],
        self::CANCELLED => [],
    ];

    public const LABELS = [
        self::DRAFT              => 'Draft',
        self::PENDING            => 'Pending approval',
        self::APPROVED           => 'Approved',
        self::PARTIALLY_PAID     => 'Partially paid',
        self::PAID               => 'Paid',
        self::PARTIALLY_REFUNDED => 'Partially refunded',
        self::REFUNDED           => 'Refunded',
        self::CANCELLED          => 'Cancelled',
    ];

    /** UI tone per status, defined once so a status looks the same on every screen. */
    public const TONES = [
        self::DRAFT              => 'slate',
        self::PENDING            => 'amber',
        self::APPROVED           => 'blue',
        self::PARTIALLY_PAID     => 'cyan',
        self::PAID               => 'green',
        self::PARTIALLY_REFUNDED => 'violet',
        self::REFUNDED           => 'violet',
        self::CANCELLED          => 'gray',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Unknown';
    }

    public static function tone(?string $status): string
    {
        return self::TONES[$status] ?? 'gray';
    }

    /** Is this move allowed from where the document currently stands? */
    public static function canMove(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Does this status mean the business has actually committed to the money? */
    public static function isCommitted(?string $status): bool
    {
        return in_array($status, self::COMMITTED, true);
    }
}
