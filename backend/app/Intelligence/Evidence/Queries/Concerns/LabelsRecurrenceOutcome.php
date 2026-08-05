<?php

namespace App\Intelligence\Evidence\Queries\Concerns;

use App\Intelligence\Recurrence\RecurrenceWindow;

/**
 * The one place a paired repair row is turned into a WORD.
 *
 * ── HELD MEANS NOTHING CAME AFTER IT ─────────────────────────────────────────────────────────────
 * A fault on a car is a CHAIN of repairs, and only the newest link can still be holding. Everything
 * behind it has been disproven by the repair that followed — however long the gap was. So `held` is
 * decided by ONE thing: no recurrence is on record. It is never a statement about the gap.
 *
 * Two wrong versions of this shipped before, in opposite directions:
 *
 *   v1  labelled ANY non-null return "came back", including 125-, 154- and 402-day gaps, while the
 *       rate above the table counted those same repairs as held. The chips could not be tallied to
 *       the published numerator, so the drawer built to prove the number disproved it.
 *
 *   v2  fixed the tally by calling out-of-window returns "held" — and printed that word in the same
 *       row as a return date and a gap of 253 days. Arithmetically consistent, visibly absurd.
 *
 * The resolution is that SCORING and NAMING are different questions. Scoring asks "did it come back
 * inside the window", and only in-window returns count against a garage. Naming asks "what happened
 * to this repair", and the honest answer for a 253-day gap is that it came back later. Hence three
 * words over two counting buckets: `came back later` is a comeback in plain English and a non-event
 * to the metric, and saying both is what keeps the page trustworthy.
 *
 * The window is therefore an ARGUMENT, not a constant — one contract decides it
 * (App\Intelligence\Recurrence\RecurrenceWindow::fromContract) and this reads it.
 */
trait LabelsRecurrenceOutcome
{
    /** The labels that count against a garage — everything the published numerator is made of. */
    public const SCORED_AS_COMEBACK = ['back within a month', 'came back'];

    /**
     * @return 'held'|'back within a month'|'came back'|'came back later'
     */
    private function outcome(?string $nextOccurredAt, int|string|null $daysToReturn, RecurrenceWindow $window): string
    {
        // The only route to `held`: nothing followed this repair. The newest link in the chain holds
        // the status until a later repair takes it away.
        if ($nextOccurredAt === null || $daysToReturn === null) {
            return 'held';
        }

        $days = (int) $daysToReturn;

        // It came back — just too late to be laid at this garage's door. Named, not counted.
        if ($days > $window->windowDays) {
            return 'came back later';
        }

        return $days <= 30 ? 'back within a month' : 'came back';
    }
}
