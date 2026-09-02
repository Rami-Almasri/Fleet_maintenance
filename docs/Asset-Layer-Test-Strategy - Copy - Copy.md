# Asset Layer — Test Strategy

**Date:** 2026-07-23 · **Companion to:** `FleetView-Asset-Layer-Handoff.md`. Everything here either exists and is green, or is specified for the phase that builds it.

## 0. How to run (read this before trusting any green)

- **Asset + workflow tests run against MySQL:** `vendor/bin/phpunit -c phpunit.crud.xml [--filter …]` — the `laravel_test` schema (created automatically by `run-crud-tests.cmd`, same engine as production). The default `phpunit.xml` (sqlite `:memory:`) CANNOT migrate this codebase (a pre-existing `ALTER TABLE … MODIFY COLUMN … ENUM` migration is MySQL-only) — a sqlite run that "passes" simply didn't run these tests.
- Current baseline (2026-07-23): `AssetLayerPhase1Test` 12 tests + `AssetLayerPhase2Test` 19 tests = **31 green (193 assertions)**; full Crud suite 191 tests with exactly 2 pre-existing non-asset failures (see handoff R-C1). Any third failure after your change is yours.

## 1. Unit-level (model & guard tests — exist in `AssetLayerPhase1Test`)

Covered now: schema/column integrity for all 4 tables + media FKs; full relationship graph; every invalid (status,location) pair rejected; active-requires-vehicle / in-stock-forbids-vehicle / retired-may-keep-vehicle; `warranty_until` derivation incl. clearing; mass-assignment protection on status/location/removal leg; consumable-cannot-instantiate; successor chain; seeder idempotency + is_active survival; permission matrix per role; flag default `off`; empty-tables regression fence.

Add when touched: `positionsFor()` edge cases if a new `position_scheme` is introduced; `trusted()` scope behavior in any new read path.

## 2. Feature-level (lifecycle through the service — exist in `AssetLayerPhase2Test`)

Covered now, grouped by contract:
- **Flag contracts:** off = zero asset writes with billing success; shadow happy path (full row + event + timeline mirror + derived warranty); shadow failure (consumable category) swallowed with billing untouched; enforced missing-disposition rolls back billing AND asset (all-or-nothing); enforced serial requirement; ambiguous-catalog 422.
- **Slot rules:** two-active-in-slot 409 (never a guess); position scheme validation (wrong position 422; missing position 422 under enforced; valid corner accepted).
- **Inventory doors:** intake from an uninstalled purchase (provenance copied, purchased+stored events) + intake-twice 409.
- **Lifecycle:** spare re-install preserves ORIGINAL warranty; transfer = same row, two-sided event, both timeline mirrors; transfer into occupied slot 409; removal without disposition impossible (both halves); shelf disposal terminal + retired-is-absorbing (no re-install, no transfer); sale settlement (every-component-decided 422, mixed keep/strip outcomes, zero active after).
- **Trust markers:** write_mode stamped per regime; enforced rows born `validated`; shadow-audit reports gaps + exit codes; `--promote` validates only clean rows; `--quarantine` requires `--reason`, excludes from `trusted()`, never deletes.

To add in Phase 2b: integrity-command per-check tests (multi-active slot, active-on-sold → settlement-pending notification, purchased-never-installed aging); `confirmRoutineServices` → `service_records` (row created on PASS with source=workflow_close; ServiceReminder still rolls; flag off = zero rows). To add in Phase 3: endpoint × permission matrix (viewer 403 on every mutate), response-shape snapshots for the re-pointed `serviceHistory`/`tireHistory`, quarantine-excluded-from-every-read assertions.

## 3. Production verification (run on the real DB at every deploy point — all executed 2026-07-23 locally)

1. `php artisan db:backup` → verify the dump file exists and is plausibly sized.
2. Clone prod → `migrate --force` on the clone (only expected migrations run) → `migrate:rollback --step=N` → assert zero asset tables/columns left → re-migrate. (Scripted precedent in the session log; ~2 min.)
3. Seeders on the clone → `component_catalog` count (21) + the role-grant SQL (manage on manager/maintenance/supervisor only).
4. **Flag-OFF smoke** through the real code path, wrapped in `DB::beginTransaction … rollBack` (zero pollution): purchase installs, `VehicleComponent::count()` unchanged.
5. **Shadow smoke** the same way: component row appears in-txn with `write_mode=shadow`, `validation=provisional`, 1 event, 1 timeline mirror, derived warranty; zero residue after rollback. (Exact tinker snippets are in the session's shadow-launch execution; copy them from `Asset-Layer-Shadow-Log.md` context.)
6. First REAL workshop install observed live → opening entry of the shadow log.

## 4. Shadow monitoring queries (daily M1–M4, twice-weekly M5–M7)

Canonical SQL lives in `Asset-Layer-Shadow-Launch-Plan.md` §2; the `components:shadow-audit` command runs the row-level checks (M2/M3/M4/M7 + serial) and the M1 gap join, with exit code ≠ 0 on any finding — suitable for cron/CI. The one check the command does NOT yet cover: `position_missing` counts (grep `laravel.log`) and M6 aging purchases (weekly SQL). Every M1 gap must reconcile to a logged `ComponentService` exception — **a gap with no log line means the hook itself failed silently: P1, stop and investigate.**

## 5. Disaster recovery tests (rehearse, don't improvise)

- **Flag rollback drill:** set `ASSET_LAYER_MODE=off` + `config:clear` → run one install → assert zero new asset rows and normal billing. (Takes 2 minutes; do it once during shadow week 1 so the rollback path is *known* to work.)
- **Restore drill:** restore the latest `db:backup` dump into a scratch schema; run `components:shadow-audit --since=<dump time>` on it; confirm the asset tables round-trip through backup/restore intact. Do this before Phase 4 backfill (which must ALSO be rehearsed on a restored backup first, with `--dry-run` reviewed).
- **Bad-batch drill (tabletop):** pick a shadow row, walk the §7.2 correction path end-to-end in a transaction-rollback tinker session: `remove()` with a correction note → verify compensating events → `--quarantine` with reason → verify `trusted()` exclusion. Confirms the correction tooling before it's needed in anger.
- **Savepoint semantics check (before any high-concurrency deploy):** simulate a ComponentService exception in shadow under load and verify billing commits (guards handoff risk R-M1).

## 6. Regression fences (never remove these)

- `test_off_mode_writes_zero_asset_rows` — the byte-identical guarantee behind every rollback promise.
- `test_new_tables_stay_empty_without_explicit_writes` — Phase 1's "inert until wired" proof.
- `test_shadow_mode_failure_never_blocks_the_billing_install` — the shadow safety contract.
- `test_enforced_mode_rolls_back_everything_when_disposition_is_missing` — the all-or-nothing contract.
- The full Crud suite at every deploy point — the maintenance workflow must stay green with the flag in EVERY mode you're about to run in production (off today; add a `shadow`-mode full-suite run before the enforced flip).
