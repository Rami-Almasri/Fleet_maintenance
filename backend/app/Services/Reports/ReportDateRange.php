<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * THE PERIOD A REPORT IS READ OVER — one value, one meaning, in every layer that touches it.
 *
 * A date filter is the sort of thing that gets re-implemented three times (once in SQL, once in the
 * collection pass, once in the printed header) and then disagrees with itself at the boundaries. So
 * the range is a value object: it validates itself once, it knows how to narrow a query, and it knows
 * how to answer "is this day inside?" — and both answers come from the same two strings.
 *
 * THE SEMANTICS, stated once:
 *   • INCLUSIVE on both ends. `from <= event_date <= to`. A same-day range (from === to) selects that
 *     day and nothing else, which is a legitimate question and not an empty one.
 *   • Either end may be omitted. `from` alone is "everything since"; `to` alone "everything until";
 *     neither is ALL HISTORY, which is the default so no existing reader loses their report.
 *   • `from > to` is REJECTED, never silently swapped. Swapping guesses at intent and then answers a
 *     question nobody asked; a 422 lets the person fix their own filter.
 *   • A future range is accepted and simply selects nothing. The record has no rows there — that is a
 *     fact about the record, and refusing the query would hide it.
 *   • A record with NO date cannot be placed in a period, so it is outside every active range. It is
 *     never quietly swept into the selection to make a count look fuller.
 */
final class ReportDateRange
{
    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {
    }

    /**
     * Build a range from two request values.
     *
     * @throws ValidationException when the range is inverted — the one case where continuing would
     *                             produce a confidently wrong report rather than a refused one.
     */
    public static function of(?string $from, ?string $to): self
    {
        $from = self::normalise($from, 'from');
        $to   = self::normalise($to, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages([
                'to' => 'The end of the period must not be earlier than its start.',
            ]);
        }

        return new self($from, $to);
    }

    /** The default: every record this car has, exactly as the report behaved before the filter existed. */
    public static function allHistory(): self
    {
        return new self(null, null);
    }

    /** Is any bound set? A report over an inactive range must be byte-for-byte the old report. */
    public function isActive(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * Is this recorded day inside the period? THE authoritative answer.
     *
     * The SQL narrowing below is an optimisation — it keeps thousands of rows out of PHP — but the
     * dates the report actually uses are sometimes a COALESCE across columns, so the final selection
     * is asserted here against the date the report will PRINT. If the two ever disagreed, this wins,
     * and the report can never show a row outside the period it claims to cover.
     */
    public function contains(?string $date): bool
    {
        if (! $this->isActive()) {
            return true;
        }

        if ($date === null || $date === '') {
            return false;
        }

        $day = substr($date, 0, 10);

        return ($this->from === null || $day >= $this->from)
            && ($this->to === null || $day <= $this->to);
    }

    /**
     * Narrow a query by a plain date/datetime COLUMN.
     *
     * whereDate() rather than a raw BETWEEN on a datetime: `out_date <= '2026-07-31'` would drop
     * everything logged during the last day of the range, which is exactly the off-by-one an
     * inclusive filter exists to avoid.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyToColumn($query, string $column)
    {
        if ($this->from !== null) {
            $query->whereDate($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->whereDate($column, '<=', $this->to);
        }

        return $query;
    }

    /**
     * Narrow a query by a SQL EXPRESSION — for the tables whose effective date is a COALESCE across
     * several columns. The expression is composed here in code and never from request input.
     *
     * A NULL expression compares as NULL, i.e. not matched, which is the correct reading: a row with
     * no date at all belongs to no period.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function applyToExpression($query, string $expression)
    {
        if ($this->from !== null) {
            $query->whereRaw("DATE({$expression}) >= ?", [$this->from]);
        }

        if ($this->to !== null) {
            $query->whereRaw("DATE({$expression}) <= ?", [$this->to]);
        }

        return $query;
    }

    /** The period as the API reports it — the descriptor every consumer prints its header from. */
    public function toArray(): array
    {
        return [
            'from'      => $this->from,
            'to'        => $this->to,
            'active'    => $this->isActive(),
            // Which shape of header to print. The wording lives in the phrase catalog, not here.
            'shape'     => match (true) {
                ! $this->isActive()                       => 'all_history',
                $this->from !== null && $this->to !== null => $this->from === $this->to ? 'single_day' : 'between',
                $this->from !== null                       => 'since',
                default                                    => 'until',
            },
        ];
    }

    /**
     * A date as the report stores it: Y-m-d, or null for "not given".
     *
     * An unparseable value is a validation failure rather than a silently ignored filter — a filter
     * that quietly does nothing is worse than one that refuses, because the reader believes it applied.
     */
    private static function normalise(?string $value, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'The ' . $field . ' date is not a date this report can read (expected YYYY-MM-DD).',
            ]);
        }
    }
}
