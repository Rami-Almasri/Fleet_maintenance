# DEFECT: migrations cannot build a database from zero

**Severity:** deployment blocker · **Area:** infrastructure only · **Found:** 2026-07-30
**Do not fix this in an ontology commit.** It is unrelated to the fault-vocabulary work and should
land on its own branch with its own verification.

---

## 1. `php artisan migrate` fails on an empty database

On a **verified-empty** schema the run aborts at:

```
2026_07_13_120000_add_recommendation_queue_to_maintenances .......... FAIL
SQLSTATE[42S21]: 1060 Duplicate column name 'recommendation_scheduled_for'
    alter table `maintenances` add `recommendation_scheduled_for` timestamp null after `awaiting_invoice_since`
```

Only one migration file references that column, so something earlier in the same run already adds
it. **A fresh production deploy would fail here.** It has stayed hidden because every existing
database was built up incrementally over months and never rebuilt from zero.

### Why the symptom keeps changing
MySQL/MariaDB DDL is not transactional. A migration that fails partway leaves its earlier statements
applied but the migration itself **unrecorded**, so the next attempt re-runs it and collides on a
different object each time — observed across retries: `jobs`, `contact_reminders`, `complaints`,
`garage_recommendation_decisions`, `sessions`.

> **Only the first failure on a genuinely empty database is diagnostic.** Chasing later
> already-exists errors leads to the wrong table every time.

### Related symptom, same root cause
On the dev database, `create_fault_concept_actions_table` and `create_capability_promotions_table`
report **Pending** while their tables exist and hold data. Do not "fix" that by re-running them.

---

## 2. `db:wipe` / `dropAllTables` silently leaves tables behind

```
$ php artisan db:wipe --force
INFO  Dropped all tables successfully.

$ (count tables)
22
```

It is single-pass; foreign-key-ordered drops need several. Because `migrate:fresh` is built on it,
**`RefreshDatabase` — and therefore the entire test suite — rests on a call that silently
half-works.**

Reliable wipe:

```php
DB::statement('SET FOREIGN_KEY_CHECKS=0');
do {
    $tables = /* information_schema.TABLES for this schema */;
    foreach ($tables as $t) { DB::statement("DROP TABLE IF EXISTS `{$t}`"); }
} while (count($tables) > 0);   // 2+ passes in practice
DB::statement('SET FOREIGN_KEY_CHECKS=1');
```

---

## 3. Rebuilding a test database (current workaround)

**Do not migrate `laravel_test`.** Clone the healthy dev schema:

```php
foreach (base tables in `laravel`) {
    DB::statement("CREATE TABLE laravel_test.`$t` LIKE laravel.`$t`");
}
DB::statement('INSERT INTO laravel_test.migrations SELECT * FROM laravel.migrations');
```

Result: 109/110 tables, 211 migrations recorded.

`mysqldump | mysql` does **not** work — it aborts partway even with `--force` and
`SET FOREIGN_KEY_CHECKS=0`, landing only 27 of 110 tables.

---

## Suggested fix (not applied)

1. On a scratch database, run `migrate` and capture the **first** failure only.
2. Make the offending migration idempotent (`if (! Schema::hasColumn(...))`) or delete the duplicate
   column addition, whichever the history shows is correct.
3. Repeat until `migrate` completes on an empty schema.
4. Add a CI job that migrates from zero, so this can never regress silently again.
5. Separately, override `dropAllTables` with the multi-pass version above.

## Verification target

```
php artisan db:wipe && php artisan migrate     # completes with no failures
php artisan migrate:status                     # zero Pending
php artisan test tests/Crud                    # 217 passed / 10 pre-existing PartInstalled failures
```
