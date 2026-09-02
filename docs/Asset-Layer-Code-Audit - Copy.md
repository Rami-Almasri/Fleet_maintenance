# Asset Layer — Final Code Audit (engineering closure)

**Date:** 2026-07-23 · **Type:** closure audit — no new features, no architecture changes; only real defects fixed. · **Companion:** `Asset-Layer-Final-Engineering-Review.md` (architecture-level findings F-1…F-10 remain authoritative; this audit verifies the CODE as it sits in the working tree).

## Executive Summary

The asset-layer codebase was audited file-by-file against the approved documents, linted, grep-swept for debris, schema-verified against the live database, and regression-tested. **Two real defects were found and fixed during this audit** (both introduced by this session's shadow flip: test-environment leakage of the live flag, and a PSR-4 namespace mismatch in a moved test). After the fixes the suite is back to exactly the two failures that pre-date the asset layer and belong to the branch's unrelated uncommitted work. No logic errors were found in the lifecycle code beyond what the final engineering review already registered (F-1…F-10). No dead code, no debug leftovers, no TODO/FIXME markers, no schema drift. The implementation matches the documents everywhere except the four deferrals the documents themselves declare (labor-on-install, photo policy, read endpoints, service_records writes).

**Verdict: ready to commit and continue shadow. Production (enforced + UI) readiness remains gated by the shadow gate + the pre-enforced fix list — as designed.**

## Files reviewed (complete asset-layer scope)

- **Migrations (6):** `2026_07_24_100000…100500` — linted, up+down verified on live and on a prod clone (`--step=6` clean).
- **Models:** `ComponentCatalog`, `VehicleComponent`, `ComponentEvent`, `ServiceRecord`, `app/Support/ServiceTypes` (new); `Vehicle`, `Maintenance`, `PartPurchase`, `MaintenanceMedia`, `VehicleLogEvent` (additive edits only).
- **Services/commands/controllers:** `ComponentService` (new), `ComponentsShadowAudit` (new), `PartWorkflowService` (hook), `PartPurchaseController::install` (validation blocks).
- **Config/seeders:** `config/component_catalog.php`, `config/features.php` (flag), `ComponentCatalogSeeder`, `RolesAndPermissionsSeeder`, `DatabaseSeeder`.
- **Tests/configs:** `tests/Crud/AssetLayerPhase1Test` (12), `AssetLayerPhase2Test` (19), `phpunit.xml`, `phpunit.crud.xml`.
- **Docs (11):** the full chain from `Maintenance-Workflow-Audit.md` to `Asset-Layer-Final-Engineering-Review.md` + `Asset-Layer-Shadow-Log.md` — cross-checked against the code (§ Doc↔code mismatches below).

## Verification results (clean checks — stated explicitly per the audit brief)

| Check | Result |
|---|---|
| `php -l` on all 17 new/edited PHP files | ✅ zero syntax errors |
| TODO / FIXME / HACK / `dd(` / `var_dump` / dump/debug code / commented-out blocks in asset files | ✅ none (grep hits were `in_array` false positives) |
| Dead / unreachable code | ✅ none found. `ServiceRecord`+table currently write-orphaned and `maintenance_media` component FKs unused — **by phase design** (2b/3), not dead |
| Unused classes/configs | ✅ none. `components.backfill` permission dormant until Phase 4 — by design |
| Tests | ✅ 194 total: 31 asset tests green (205 assertions across both suites); only the **2 pre-existing failures** remain (`PauseResumeMaintenanceTest`, `OdometerDiscrepancyNotifyTest`) — proven by stash-test to belong to the branch's uncommitted Incorrect-merge work, NOT the asset layer |
| DB foreign keys | ✅ 27 FKs across the 4 new tables + 2 media FKs, behaviors as designed (restrict/cascade/nullOnDelete per DB-design §2) |
| DB indexes | ✅ all designed indexes present (vehicle_components 25 incl. the slot/status/warranty composites; events 11; service_records 14) |
| Model ↔ migration consistency | ✅ every `$fillable` field of all 4 models maps to a real column; media columns present |
| Naming (tables/columns) | ✅ conventions consistent; one deliberate deviation documented: `$table='component_catalog'` (non-plural) is explicit in the model |
| Live smoke state | ✅ shadow live; flag-off byte-identical and shadow write both verified via transaction-rollback smokes (see shadow log) |

## Defects found IN THIS AUDIT

**Critical — none.**

**High — 2 found, both FIXED during the audit:**
1. **Test-environment leakage of the live flag.** Neither phpunit config pinned `ASSET_LAYER_MODE`; flipping the live `.env` to `shadow` leaked into the test process and broke `test_asset_layer_flag_defaults_off` (and would have made every future run mode-dependent on the machine's `.env`). **Fix:** `<env name="ASSET_LAYER_MODE" value="off"/>` added to `phpunit.crud.xml` AND `phpunit.xml` — tests are now deterministic regardless of the live flag. *(Lesson recorded: any new env-driven flag must be pinned in both phpunit configs the day it is born.)*
2. **PSR-4 namespace mismatch:** `tests/Crud/AssetLayerPhase1Test.php` still declared `namespace Tests\Feature\Components` after its move (an earlier `sed` silently failed on CRLF endings). PHPUnit dir-loading masked it. **Fix:** namespace corrected to `Tests\Crud`.

**Medium — none new.** (The open medium/high items from the final review — F-1 provenance, F-7 TOCTOU, F-8 settlement race, F-9 event-immutability guard, R-H3 serial race, R-M3 route permission, R-H1 integrity command — were re-confirmed as accurate and still open; they are pre-enforced obligations, not merge blockers. No additional instances of those patterns were found.)

**Low — reported, deliberately NOT fixed (cosmetic; per the audit rules):**
- Unused import `use App\Models\Maintenance;` in `ComponentService` (usage count 0). Remove in the next functional PR touching the file.
- Naming drift: `Maintenance::serviceRecordEntries()` vs the ADR's relationship map naming (`serviceRecords`) — the rename avoided nothing real (no collision existed); harmless, but align when Phase 2b touches the model.
- Shelf-disposed rows (`dispose()`) carry `disposition` with `removal_reason`/`removed_at` NULL — semantically intended (shelf disposal is not a vehicle removal) and the audit command's M4 check correctly keys on `removed_at`; documented here so nobody "fixes" it into a false invariant.
- ADR §2.1 contains its own superseded draft paragraph about the status enum (readable but awkward) — the DB-design doc is the authoritative enum source; optional doc cleanup.

## Doc ↔ implementation mismatches (all four are declared deferrals — no silent drift found)

| Doc says | Code today | Status |
|---|---|---|
| Phase2-Workflow-Design §1 payload example includes `predecessor.photo_media_ids` | validation doesn't accept it; photo policy unimplemented | Deferred to Phase 3 (modals+media wiring) — add the rules with the modal |
| §5/§7: optional labor on install → labor line + `repair_labor` service record | not built | Deferred (flagged in final review §4 "mixed job") |
| §8 read/mutating endpoints + `component-context` | not built | Phase 3 by plan |
| `confirmRoutineServices` → `service_records` | not wired | Phase 2b by plan |

## Technical debt register (consolidated, deduplicated)

1. Pre-enforced fixes: R-H3 (serial uniqueness at DB level), R-M3 (install-route permission decision), F-1 (provenance re-link on line replacement), F-7 (re-lock component row in `transfer`/spare-`install`), F-9 (event immutability model guard).
2. Phase 2b builds: `components:integrity` command (closes R-H1/F-8 detection), `service_records` wiring, labor-on-install.
3. Backfill guardrails before Phase 4: F-10 tyre-trap rule, cross-source dedup exclusion, `write_mode='backfill'` never promotable.
4. The 2 pre-existing test failures — owned by the Incorrect-merge track; must be fixed before THAT work merges (they are not asset debt, listed here so they aren't lost).
5. Housekeeping: unused import; `serviceRecordEntries` naming; untracked root files `shot_bootstrap.php` / `odoo_backup.dump` (not asset artifacts — confirm with owner before cleanup).

## Before merging to main (the asset-layer branch)

1. **Commit the asset layer on its own branch** — the exact file scope is the "Files reviewed" list above; it does not overlap the Incorrect-merge dirty files. This remains risk #1 of the whole program.
2. Both audit fixes (already in the tree) go with it.
3. Full Crud suite green except the 2 documented pre-existing failures — condition already met.
4. Nothing else blocks the merge: the layer is dark (`off` by default, tests pinned), additive, and rollback-verified. Enforced-mode obligations (debt item 1) are gate conditions, not merge conditions.

## Can be deferred to future releases

Everything in debt items 2–3 and 5, the Phase 3 UI/API surface, backfill, intelligence — exactly per the roadmap in the handoff §3. No hidden coupling forces any of it earlier.

## Final production-readiness assessment

- **Architecture:** production-grade. Confidence **95%** — validated across five review passes with rejected alternatives documented; the residual 5% is the class of unknowns only real concurrency and real workshop behavior reveal.
- **Code as implemented for its CURRENT stage (dark launch + shadow observation):** confidence **90%** — 31 targeted tests, verified transactions/rollbacks, deterministic test env (after today's fix), clean schema; residual risk concentrated in the known-open F/R items and the unexercised real-world volume.
- **Readiness for `enforced` + customer-visible use TODAY: ~55–60% — intentionally.** The remaining 40% is not unknown work; it is the enumerated, scheduled list: shadow gate with volume floor, the five pre-enforced fixes, the integrity command, and the Phase 3 modals (enforced without the UI prompts would stall the workshop).

**Closing statement:** no undiscovered-defect smell remains in this codebase at the depth this audit could reach; the system fails safe in every mode we could construct, and every known weakness has an ID, an owner phase, and a detection mechanism. That — not zero defects — is what "engineering closure" means here.
