<?php

namespace App\Intelligence\Evidence;

/**
 * One drillable claim: what was asserted, how it was measured, and the rows behind it.
 *
 * ── WHY EVERY NUMBER NEEDS ONE ───────────────────────────────────────────────────────────────────
 * This platform grades suppliers. The first time a score goes against a garage, someone will dispute
 * it — and the only acceptable answer is the repairs themselves, on screen, not a promise to look
 * into it. A grade nobody can audit is a grade that gets argued with instead of acted on.
 *
 * The three parts are deliberately separate. `claim()` is what we said. `method()` is how, in
 * language an operator reads. `rows()` is the evidence. A drawer that showed only rows would make
 * the reader reconstruct the reasoning; one that showed only method would be a defence rather than a
 * disclosure.
 */
interface EvidenceQuery
{
    /**
     * The stable id this evidence is addressed by, e.g. `recurrence.garage:331`.
     *
     * Ids are emitted alongside the metric they explain, so a card carries the route to its own
     * proof rather than the UI having to know how to reconstruct it.
     */
    public function id(): string;

    /** The claim, restated with its figures — the sentence the drawer opens with. */
    public function claim(): string;

    /**
     * How it was measured, in operational language.
     *
     * Not the SQL. "For each repair at this garage we looked for the same fault returning on the
     * same car" is what a supervisor can check against their own memory of the workshop; a query
     * plan is not.
     */
    public function method(): string;

    /** The engine-level detail, shown under a "technical details" disclosure. */
    public function technicalNote(): ?string;

    /** Column keys, in display order. */
    public function columns(): array;

    /**
     * The rows, paginated.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function rows(int $page = 1, int $perPage = 50): array;
}
