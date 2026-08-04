<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Accepted schema drift
    |---------------------------------------------------------------------------
    |
    | `schema:health --drift` FAILS the build on any difference between the live
    | schema and a clean migration run. Each string below is a difference that has
    | been investigated and consciously accepted for now: it is still reported, but
    | it does not fail CI.
    |
    | Matching is EXACT against the message `schema:health --drift` prints. That is
    | deliberate. If an accepted column drifts further — decimal(12,2) becomes
    | decimal(10,2) — the message changes, stops matching, and the build goes red.
    | An entry can only ever excuse the exact state it was written for.
    |
    | RULES FOR ADDING AN ENTRY
    |   1. Never add one to make a red build green. Investigate first.
    |   2. Every entry carries WHY it is accepted and WHAT removes it.
    |   3. An entry is a debt, not a decision. Empty this array.
    |
    */
    'accepted_drift' => [

        // ── invoices.discount / total_after_discount: decimal(12,2) live vs (14,2) clean ──
        //
        // ROOT CAUSE (established 2026-08-04, evidence in the migrations table):
        //   batch 26  2026_06_21_140000_add_discount_period_to_invoices   → creates both as decimal(14,2)
        //   batch 27  2026_06_21_150000_add_discount_to_invoices          → narrowed them to decimal(12,2)
        //
        // The batch-27 migration WAS NEVER COMMITTED TO GIT — `git log --all` finds no trace of it in
        // any commit, stash or dangling object. It existed only in a working tree, ran against the live
        // database, and was then lost, almost certainly to the same stash-sweep that has taken the tree
        // twice on this branch. Its row survives in `laravel.migrations`, which is why `schema:health`
        // reports "249 of 248 applied" — one more migration recorded than there are files on disk.
        //
        // So live went 14,2 → 12,2 while a fresh clone stops at 14,2, and the change is unreproducible
        // because the migration that made it no longer exists anywhere.
        //
        // IMPACT: none in practice. decimal(12,2) still holds 9,999,999,999.99, far above any invoice
        // this fleet issues; nothing truncates today. The defect is reproducibility, not data.
        //
        // REMOVED BY: a corrective migration that widens both columns to decimal(14,2) on live, making
        // it match the committed migration rather than the lost one. Widening a decimal is lossless and
        // needs no backfill. It is not applied yet because these are financial columns inside the
        // invoice work another session is actively changing, and a schema change landing under an
        // in-flight feature is how conflicts get made. Apply it when that work settles, then DELETE
        // these two lines — the build will confirm the fix.
        'invoices.discount (column) type: clean decimal(14,2), live decimal(12,2)',
        'invoices.total_after_discount (column) type: clean decimal(14,2), live decimal(12,2)',
    ],

];
