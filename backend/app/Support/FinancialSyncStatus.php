<?php

namespace App\Support;

/**
 * Where a {@see \App\Models\FinancialEvent} stands on its journey to Odoo.
 *
 * This is a DIFFERENT question from {@see FinancialDocumentStatus}, and keeping the two apart is the
 * point. A garage invoice's document status answers "where is this bill in its own life" (draft →
 * approved → paid). A financial event's sync status answers "have we told the accounting system about
 * it yet". A bill can be approved and unpaid while its event is SYNCED, and it can be paid in full
 * while its event is BLOCKED because nobody has mapped the product. Neither status implies the other.
 *
 * The lifecycle:
 *
 *   NOT_REQUIRED  this operation generated no financial obligation (a zero-cost ticket, a warranty
 *                 repair we were not billed for). It exists so "why is there no event here?" has an
 *                 answer that is not silence.
 *   DRAFT         a cost exists but the information behind it is still being gathered.
 *   BLOCKED       validation found something missing or unmapped. Carries the reasons, as codes.
 *   READY         every requirement passed. Safe to send.
 *   SENDING       a push is in flight. Written BEFORE the Odoo call and only ever left by the result of
 *                 that call — this is the state that makes a crash mid-push visible rather than silent.
 *   SYNCED        Odoo confirmed the document. odoo_document_id is set. Terminal in the happy path.
 *   FAILED        Odoo refused, timed out, or the connection broke. Carries failure_reason. Retryable.
 *   CANCELLED     the obligation was withdrawn before it reached Odoo.
 *
 * WHY SENDING IS STORED AND NOT INFERRED. §35 requires that an event is never marked SYNCED before Odoo
 * actually confirms. The inverse matters just as much: if we crash between "Odoo created the bill" and
 * "we wrote SYNCED", the row must not read READY, because a human looking at a READY row will press
 * Send. Leaving it at SENDING means the retry path runs the idempotency search first and links the
 * document that already exists. SENDING is therefore not a cosmetic in-flight flag — it is the record
 * that a push may have landed, and it is what makes the retry safe.
 */
final class FinancialSyncStatus
{
    public const NOT_REQUIRED = 'NOT_REQUIRED';
    public const DRAFT        = 'DRAFT';
    public const BLOCKED      = 'BLOCKED';
    public const READY        = 'READY';
    public const SENDING      = 'SENDING';
    public const SYNCED       = 'SYNCED';
    public const FAILED       = 'FAILED';
    public const CANCELLED    = 'CANCELLED';

    public const ALL = [
        self::NOT_REQUIRED,
        self::DRAFT,
        self::BLOCKED,
        self::READY,
        self::SENDING,
        self::SYNCED,
        self::FAILED,
        self::CANCELLED,
    ];

    public const LABELS = [
        self::NOT_REQUIRED => 'No financial obligation',
        self::DRAFT        => 'Draft',
        self::BLOCKED      => 'Blocked',
        self::READY        => 'Ready for Odoo',
        self::SENDING      => 'Sending',
        self::SYNCED       => 'Synced',
        self::FAILED       => 'Failed',
        self::CANCELLED    => 'Cancelled',
    ];

    public const TONES = [
        self::NOT_REQUIRED => 'gray',
        self::DRAFT        => 'slate',
        self::BLOCKED      => 'amber',
        self::READY        => 'blue',
        self::SENDING      => 'cyan',
        self::SYNCED       => 'green',
        self::FAILED       => 'red',
        self::CANCELLED    => 'gray',
    ];

    /**
     * Which moves are legal.
     *
     * Note SENDING → READY is absent by design. An in-flight push whose outcome we never learned can
     * only be resolved by ASKING Odoo (the reconcile path, which searches for the idempotency ref and
     * lands on SYNCED or FAILED). Letting it drop back to READY would be a claim we cannot support:
     * that nothing was created. See {@see \App\Services\Odoo\FinancialEventSyncService::reconcile()}.
     */
    public const TRANSITIONS = [
        self::NOT_REQUIRED => [self::DRAFT, self::CANCELLED],
        self::DRAFT        => [self::READY, self::BLOCKED, self::NOT_REQUIRED, self::CANCELLED],
        self::BLOCKED      => [self::READY, self::DRAFT, self::CANCELLED],
        self::READY        => [self::SENDING, self::BLOCKED, self::DRAFT, self::CANCELLED],
        self::SENDING      => [self::SYNCED, self::FAILED],
        self::FAILED       => [self::SENDING, self::READY, self::BLOCKED, self::CANCELLED],
        // A synced document belongs to Odoo now. Nothing here may rewrite it; a correction is a credit
        // note raised in Odoo, not a status change in FleetView.
        self::SYNCED       => [],
        self::CANCELLED    => [],
    ];

    /** Nothing further happens to an event in one of these. */
    public const TERMINAL = [self::SYNCED, self::CANCELLED];

    /** States that count as "still owed to Odoo" on the sync dashboard. */
    public const OUTSTANDING = [self::DRAFT, self::BLOCKED, self::READY, self::SENDING, self::FAILED];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Unknown';
    }

    public static function tone(?string $status): string
    {
        return self::TONES[$status] ?? 'gray';
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canMove(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** May a user press "Send to Odoo" on an event in this state? */
    public static function isSendable(?string $status): bool
    {
        return in_array($status, [self::READY, self::FAILED], true);
    }
}
