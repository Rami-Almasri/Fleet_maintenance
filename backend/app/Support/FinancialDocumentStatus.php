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

    // ── What may be OFFERED, as opposed to what is legal ─────────────────────────────────────────────
    //
    // TRANSITIONS above says what the machine will ACCEPT. It is deliberately permissive, because it is
    // the enforcement boundary and every caller — an API client, a test, a back-office fixup — is
    // measured against it. What a person should be OFFERED on a row is a narrower question, and the two
    // were being conflated: a screen that renders one button per legal transition offers every move at
    // once, which is how a draft ended up showing Submit, Approve and Cancel side by side with Edit and
    // Delete. Five buttons, no order of importance, and one of them (Approve) skipping the review stage
    // that the other one (Submit) exists to start.
    //
    // So this map is the second half of the same machine: for the state a document is IN, which actions
    // a person may be shown, which one is the PRIMARY one, and what each costs in permission. It never
    // widens TRANSITIONS — {@see actionsFor()} intersects the two, so an action can only ever be offered
    // if the underlying move is also legal. It only ever narrows it, in two deliberate places:
    //
    //   DRAFT → APPROVED stays LEGAL but is NOT OFFERED. Approving straight from draft is a real path
    //   (a small team where the buyer is the approver uses it, and the API and its tests depend on it),
    //   but putting the button next to Submit makes skipping review the same single click as asking for
    //   it. To approve from the UI you submit first — one extra click, and the pending state records
    //   that the bill was offered up for a look.
    //
    //   APPROVED → PENDING is offered as "unapprove", but the money states that DERIVE from approved
    //   (partially paid, partially refunded) do not offer it, because those statuses carry their own
    //   TRANSITIONS entry and its authors already left PENDING out of it. Withdrawing an approval on a
    //   bill somebody has already been paid against would strand the payment.
    //
    // Actions that are not transitions at all (edit, delete) sit here too, because "which of these five
    // buttons belongs on a draft row" is one question and it deserves one answer. Their availability is
    // {@see \App\Models\Concerns\IsFinancialDocument::isEditable()}, which is why they carry `to => null`.
    public const ACTION_SUBMIT    = 'submit';
    public const ACTION_APPROVE   = 'approve';
    public const ACTION_RETURN    = 'return';      // pending → draft: send it back for correction
    public const ACTION_UNAPPROVE = 'unapprove';   // approved → pending: the approval was wrong
    public const ACTION_PAY       = 'pay';
    public const ACTION_CANCEL    = 'cancel';
    public const ACTION_EDIT      = 'edit';
    public const ACTION_DELETE    = 'delete';

    /**
     * Moving a document through its life is maintenance.manage for every document kind — the lifecycle
     * routes are one group and one gate.
     *
     * KEYING the paper is not: a supplier bill is written by whoever buys parts (parts.purchase) and a
     * garage bill by whoever runs maintenance (maintenance.manage). So PERM_PAPER below is a PLACEHOLDER
     * that {@see actionsFor()} substitutes for the document's own answer. Hard-coding one document's
     * permission into a map both kinds read would tell a garage invoice that its Edit button needs a
     * parts permission, which is neither what the route checks nor true.
     */
    private const PERM_MONEY = 'maintenance.manage';
    public const PERM_PAPER = 'document.paper';

    /**
     * Per state: the actions to offer, in the order they should be read. The FIRST entry is the primary
     * one — the thing this document is waiting for — and the rest are secondary and belong behind a menu.
     *
     * `to`      the stored status this action moves the document to, or null when it moves nothing
     * `perm`    the permission required — checked here AND by the route middleware
     * `needs`   'editable' when the action additionally requires the document to still be editable
     * `danger`  destructive enough to be styled and confirmed as such
     * `reason`  the action refuses without a written reason
     */
    public const ACTIONS = [
        self::DRAFT => [
            self::ACTION_SUBMIT => ['to' => self::PENDING,   'perm' => self::PERM_MONEY, 'label' => 'Submit for review'],
            self::ACTION_EDIT   => ['to' => null,            'perm' => self::PERM_PAPER, 'label' => 'Edit', 'needs' => 'editable'],
            self::ACTION_DELETE => ['to' => null,            'perm' => self::PERM_PAPER, 'label' => 'Delete', 'needs' => 'editable', 'danger' => true],
            self::ACTION_CANCEL => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        self::PENDING => [
            self::ACTION_APPROVE => ['to' => self::APPROVED,  'perm' => self::PERM_MONEY, 'label' => 'Approve'],
            self::ACTION_RETURN  => ['to' => self::DRAFT,     'perm' => self::PERM_MONEY, 'label' => 'Return for correction', 'reason' => true],
            self::ACTION_EDIT    => ['to' => null,            'perm' => self::PERM_PAPER, 'label' => 'Edit', 'needs' => 'editable'],
            self::ACTION_DELETE  => ['to' => null,            'perm' => self::PERM_PAPER, 'label' => 'Delete', 'needs' => 'editable', 'danger' => true],
            self::ACTION_CANCEL  => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        self::APPROVED => [
            self::ACTION_PAY       => ['to' => self::PAID,      'perm' => self::PERM_MONEY, 'label' => 'Record payment'],
            self::ACTION_UNAPPROVE => ['to' => self::PENDING,   'perm' => self::PERM_MONEY, 'label' => 'Withdraw approval'],
            self::ACTION_CANCEL    => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        self::PARTIALLY_PAID => [
            self::ACTION_PAY    => ['to' => self::PAID,      'perm' => self::PERM_MONEY, 'label' => 'Record payment'],
            self::ACTION_CANCEL => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        self::PARTIALLY_REFUNDED => [
            self::ACTION_PAY    => ['to' => self::PAID,      'perm' => self::PERM_MONEY, 'label' => 'Record payment'],
            self::ACTION_CANCEL => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        // Settled and fully-credited bills are finished. Cancelling one is still legal — a bill paid in
        // error has to be withdrawable — but there is nothing else left to do to it.
        self::PAID => [
            self::ACTION_CANCEL => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        self::REFUNDED => [
            self::ACTION_CANCEL => ['to' => self::CANCELLED, 'perm' => self::PERM_MONEY, 'label' => 'Cancel invoice', 'danger' => true, 'reason' => true],
        ],
        // Withdrawn. The record stays readable and nothing more happens to it.
        self::CANCELLED => [],
    ];

    /**
     * The actions to offer on a document that is SHOWN as `$shown` and STORED as `$stored`.
     *
     * Both statuses matter and they are not always the same one. What a person sees is the derived
     * status (a part-paid bill reads "Partially paid"), and that is the state whose offer list applies —
     * but the machine enforces against the STORED status, so an action is only offered when its move is
     * legal from there too. The intersection can only ever be smaller than TRANSITIONS, never larger,
     * which is what makes it safe for a screen to render this list without re-deriving anything.
     *
     * `$can` answers the permission question for the current user; `$editable` is the document's own
     * {@see \App\Models\Concerns\IsFinancialDocument::isEditable()}. Both are applied HERE rather than in
     * the client, so every screen offers the same buttons to the same person.
     *
     * `$paperPermission` is what keying THIS kind of document costs — see PERM_PAPER above.
     *
     * @param  callable(string):bool  $can
     * @return array<int, array{key:string, label:string, to:?string, primary:bool, permission:string, danger:bool, needs_reason:bool}>
     */
    public static function actionsFor(
        ?string $shown,
        ?string $stored,
        bool $editable,
        callable $can,
        string $paperPermission = 'parts.purchase',
    ): array {
        $offered = self::ACTIONS[$shown ?: self::DRAFT] ?? [];
        $from    = $stored ?: self::DRAFT;
        $out     = [];

        foreach ($offered as $key => $spec) {
            $to = $spec['to'] ?? null;
            // The placeholder becomes this document's real paper permission.
            $perm = $spec['perm'] === self::PERM_PAPER ? $paperPermission : $spec['perm'];

            // A move must still be legal where the document actually stands. A non-move (edit, delete)
            // asks the editability question instead — the same one the API enforces on write.
            if ($to !== null && ! self::canMove($from, $to)) {
                continue;
            }
            if (($spec['needs'] ?? null) === 'editable' && ! $editable) {
                continue;
            }
            if (! $can($perm)) {
                continue;
            }

            $out[] = [
                'key'          => $key,
                'label'        => $spec['label'],
                'to'           => $to,
                // The first action that survives every filter is the one the row leads with. Derived
                // rather than declared, so a user who lacks the permission for the headline action gets
                // a sensible second choice promoted instead of a row whose primary slot is empty.
                'primary'      => false,
                'permission'   => $perm,
                'danger'       => (bool) ($spec['danger'] ?? false),
                'needs_reason' => (bool) ($spec['reason'] ?? false),
            ];
        }

        // The headline is the thing this document is WAITING FOR, and two kinds of action never qualify.
        // Edit and Delete are how a document is corrected rather than how it moves forward — leading with
        // Edit on a draft would bury the ask-for-approval step behind a menu. And a destructive action is
        // never a headline at all: a settled bill's only remaining move is Cancel, and rendering that as
        // the row's most prominent button invites the one click nobody wants. A row with nothing but
        // destructive moves left gets NO primary action, and they all sit behind the menu, which is the
        // honest reading of "there is nothing this document is waiting for".
        foreach ($out as $i => $action) {
            if ($action['danger'] || in_array($action['key'], [self::ACTION_EDIT, self::ACTION_DELETE], true)) {
                continue;
            }
            $out[$i]['primary'] = true;
            break;
        }

        return $out;
    }

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
