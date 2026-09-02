# Invoice precision drift — root cause and migration plan

`invoices.discount` and `invoices.total_after_discount` are **`decimal(12,2)` on the live database**
and **`decimal(14,2)` in a clean migration run**. Found 2026-08-04 while verifying that a fresh
clone reproduces the live schema. Not fixed yet — deliberately; see *Why not yet*.

## What the evidence says

The `migrations` table on live records both of these, in this order:

| id | batch | migration | in git? |
|----|-------|-----------|---------|
| 39 | 26 | `2026_06_21_140000_add_discount_period_to_invoices` | **yes** — creates both columns `decimal(14,2)` |
| 40 | 27 | `2026_06_21_150000_add_discount_to_invoices` | **no — no trace in any commit, stash or dangling object** |

Batch 26 ran first and created the columns at `decimal(14,2)`. Live is now `decimal(12,2)`. The only
thing that ran in between is batch 27, so **batch 27 narrowed them**.

That migration was never committed. `git log --all`, the stash list and `git rev-list --all --objects`
all return nothing for the filename. It existed only in a working tree, ran against the live
database, and was then lost — almost certainly to the same `git stash --include-untracked` sweep
that has taken this branch's working tree twice (see `concurrent-sessions-stash-sweep` memory).

The giveaway is visible in `schema:health` output: **"249 of 248 applied"** — one more migration
recorded in the database than there are files on disk.

## Impact

**No data impact.** `decimal(12,2)` holds up to 9,999,999,999.99. No invoice in this fleet
approaches that, nothing truncates today, and no figure is wrong.

**The defect is reproducibility.** Live and a fresh deploy have permanently different column
definitions, and the change that caused it cannot be replayed because the migration no longer
exists. Any environment built from the repo gets 14,2; this one machine keeps 12,2.

## Why not yet

These are financial columns on `invoices`, inside the area a parallel session is actively changing
(part invoices, VAT/discount bands, cost journey). A schema change landing underneath in-flight work
is how conflicts get made. The drift is inert; the coordination risk is not.

Until then it is **allowlisted, not ignored** — `config/schema.php` lists both differences verbatim,
so `composer verify` stays green on these two and goes red on anything new. The allowlist matches
exactly, so if either column drifts *further* the entry stops matching and the build fails.

## The safe migration

Widening a `decimal` is lossless: `(12,2) → (14,2)` only adds integer range, keeps the scale, and
rewrites no values. No backfill, no downtime concern at this table size.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realign invoices.discount / total_after_discount with the committed migration.
 *
 * Live carries decimal(12,2) because a never-committed migration narrowed them after
 * 2026_06_21_140000 created them at decimal(14,2). That file is gone, so live cannot be reproduced
 * from the repo. This restores the definition the repo actually specifies.
 *
 * Widening only — no value changes, no truncation, nothing to back-fill. On an environment already
 * at 14,2 (any fresh clone) this is a no-op that costs one ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount', 14, 2)->nullable()->change();
            $table->decimal('total_after_discount', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately NOT narrowing back. Down-migrating to 12,2 would risk truncating any value
        // that had legitimately used the wider range, and the 12,2 state was itself the defect.
        // Reversing this migration is a no-op on purpose.
    }
};
```

`->change()` needs `doctrine/dbal` on Laravel < 11. This project is on Laravel 11+, where native
column changes are built in — confirm before running.

### Steps

1. Wait for the invoice/cost-journey work to land.
2. Add the migration above, run `php artisan migrate`.
3. Confirm: `schema:health --drift` reports **zero** differences.
4. **Delete both entries from `config/schema.php`.** The test
   `SchemaDriftComparatorTest::test_accepted_drift_entries_match_the_current_message_format`
   asserts the list is non-empty, so it will need its assertion relaxed when the list empties —
   that failure is the reminder that the debt is paid.
5. The orphan `2026_06_21_150000` row stays in `migrations`. Leave it: Laravel ignores records
   without files, and deleting history rows to make a count look right is worse than the count.
   It is the only surviving evidence of what happened.

## Prevention

Already in place from this investigation:

- `schema:health --drift` compares full column definitions, index order/uniqueness and FK rules, not
  just names — it now detects exactly this class of drift.
- Drift **fails** the build instead of warning, and `composer verify` actually passes `--drift`.
- `config/schema.php` makes accepted drift explicit, exact-matched and self-documenting.

The remaining exposure is uncommitted migrations. A migration that runs against the live database
but is never committed is unreproducible by definition — commit migrations before running them.
