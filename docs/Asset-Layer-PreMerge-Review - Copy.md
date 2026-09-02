# Asset Layer — Principal Engineer Pre-Merge / Pre-Production Review

**Date:** 2026-07-23 · **Type:** adversarial line-by-line review of the full asset-layer scope + blast-radius check across the whole project. Supersedes nothing; consolidates on top of `Asset-Layer-Final-Engineering-Review.md` (F-1…F-10) and `Asset-Layer-Code-Audit.md`. Findings are reported ONLY where proven; where nothing was found, that is stated with the reason for confidence.

## Executive Summary

This pass re-read every asset-layer file line-by-line, hunted for the 18 requested defect classes, and traced the addition's blast radius through the maintenance workflow, billing, parts, timeline, notifications, reminders, sync, permissions, and reporting. **One genuine latent logic bug was found, proven, minimally fixed, and pinned with a regression test:** in `enforced` mode, a consumable purchase (oil/coolant — legitimate in the parts flow) would have aborted its own billing install because catalog resolution refuses consumable-only categories. The bug was invisible until now because consumables were only ever tested in `shadow`, where the abort is swallowed — a textbook false-sense-of-security test, which was also re-pointed to a genuinely failing scenario so the swallow contract stays covered. Beyond that: no new race conditions, transaction leaks, permission bypasses, N+1s, or data-corruption paths were found beyond the already-registered F/R items; the documents and code are consistent; the wider system is untouched by construction and verified untouched by the 195-test suite.

**Merge readiness: 92/100. Production (enforced) readiness: 58/100 — by design, gated on the shadow gate + the enumerated pre-enforced list.**

## Findings (by severity)

### CRITICAL — none found.

### HIGH

**PM-1 · Enforced-mode consumable purchase blocks its own billing install — FIXED in this review.**
- *Where:* `ComponentService::installFromPurchase` / `resolveCatalog` (service), exercised via `PartWorkflowService::installPurchase` hook.
- *Cause:* `resolveCatalog` excludes consumable catalogs (correct — consumables never become components) and aborts 422 when nothing remains. For `category_key='fluids'` (all-consumable) or `part_class='consumable'`, the abort propagated; in `enforced` mode that rolls back the ENTIRE install including the billing line — contradicting the design ("consumable → service-side cost; billing proceeds").
- *Impact:* every oil/coolant purchase would have become uninstallable the day the flag reached `enforced`. Latent (enforced is off), but certain.
- *Fix applied (minimal):* early return `null` (skip component path, `asset_layer.consumable_skipped` info log) when no explicit catalog id is given AND the purchase resolves to consumable-only (`part_class='consumable'` or all active catalogs for the category are consumable). An EXPLICIT consumable catalog id still aborts (caller error). New regression test runs the scenario in BOTH shadow and enforced (`test_consumable_purchase_installs_cleanly_in_every_mode_without_a_component`); the old shadow-swallow test was re-pointed to the ambiguous-catalog failure so the try/catch contract remains covered. Suite: 195 tests, only the 2 pre-existing non-asset failures.

*(Carry-forward HIGHs from earlier reviews, re-verified still accurate, still open, still pre-enforced obligations — not merge blockers: R-H3 serial race without DB constraint; R-M3 install-route permission asymmetry; F-7 missing in-transaction re-lock in `transfer()`/spare-`install()` (zero exposure today — no HTTP surface); R-H1 integrity command not yet built.)*

### MEDIUM

**PM-2 · Enforced-mode behavior for NULL/unknown `category_key` purchases (documented, not changed).** `category_key` is nullable in the request flow; without it and without an explicit pick, enforced mode 422s the install. This is CORRECT design (the Phase 3 modal must pick), but it means **enforced cannot ship before the modal ships** — already implied by the roadmap; now stated as a hard sequencing constraint. In shadow these appear as expected M1/R-M4 gaps.

**PM-3 · Spare re-install odometer is unvalidated against the component's event history.** DB-design §4 rule 4 promises event-odometer monotonicity per vehicle; `install()` (spare) accepts any `odometer`. Consequence: a wrong reading pollutes `life_km` analytics, never corrupts state. Smallest fix (Phase 2b, with F-7's re-lock): compare against the component's latest event odometer for the same vehicle. Not fixed now — no data-corruption path, and shadow's job is to measure exactly this kind of noise.

*(Carry-forward MEDIUMs re-verified: F-1 provenance nulling via line-item replacement; F-8 settlement vs concurrent-install; R-M1 shadow savepoint semantics under deadlock; R-M2 quarantine-on-active leaves slot occupied.)*

### LOW

- Unused import `App\Models\Maintenance` in `ComponentService` (still present; cosmetic — remove with the next functional PR).
- `ComponentsShadowAudit --since=` parses with `Carbon::parse` — a garbage value throws an unhandled exception at the CLI (acceptable for an ops tool; wrap when it grows into `components:integrity`).
- `recordEvent()` does a `Vehicle::find` per affected vehicle per event (≤2 tiny PK lookups per operation) — negligible at current volume; batch if event volume ever grows 100×.
- `Maintenance::serviceRecordEntries()` naming vs docs (`serviceRecords`) — align opportunistically.

## Things Verified Safe (explicitly, with the reason)

1. **Transactions:** every ComponentService mutation runs in `DB::transaction`; nested calls join the caller's via savepoints; no early `beginTransaction` without matching scope anywhere in the new code (grep-verified — no manual begin/commit calls at all, closure API only). No transaction leaks possible by construction.
2. **The flag contract:** `off` byte-identical (regression-fenced test + live smoke), `shadow` swallow proven unable to touch billing (test), `enforced` all-or-nothing (rollback test). Test env now pins the flag (`phpunit*.xml`), so these fences are deterministic.
3. **Mass assignment:** `status`, `location`, the entire removal leg, and the trust markers are non-fillable and covered by an explicit test; no `forceFill` on `VehicleComponent` exists outside `ComponentService`'s explicit assignments.
4. **Permissions:** the only HTTP path into asset writes remains the pre-existing install endpoint; no new route was added, so no new authorization surface exists to bypass. The known R-M3 asymmetry is a Phase-3 decision, not a bypass.
5. **N+1 / performance:** no loops over lazy relations in the new code paths; `lockSlot` is one indexed query; `shadow-audit` eager-loads `catalog`. The two flagged scale ceilings (`lastPerType`, audit memory load) are documented with their limits and are unreachable at shadow volumes.
6. **Blast radius:** Maintenance workflow, billing, vehicles, parts intelligence, reminders, notifications, OM sync, reporting — none read or depend on the new tables; the only two touched execution paths (`installPurchase`, install validation) are additive and flag-gated. Verified by: code reading, grep for consumers of the new tables (none outside asset files/tests), and the full 195-test suite returning to exactly the 2 pre-existing failures.
7. **Timeline/notifications integration:** `event_type` (40) and `source_tag` (20) column widths fit the new constants; the frontend timeline explicitly falls back to category/source heuristics for unlisted event types (verified in `lib/vehicleTimeline.js` comments/logic) — unknown component events render, never break.
8. **FK/index/cascade audit vs design:** 27 FKs + 2 media FKs live, behaviors match DB-design §2 (restrict on catalog, cascade on events/service-records-to-vehicle, nullOnDelete elsewhere); all composite indexes present; every model `$fillable` maps to a real column (tinker-verified). No duplicate or unused relations found.
9. **Serialization:** API resources for components don't exist yet (Phase 3), so no accidental exposure of cost fields; `meta` JSON casts are arrays end-to-end; no `toArray()` leaks added to existing resources.
10. **No dead code, no TODO/FIXME/HACK, no debug artifacts, no commented-out blocks** in the asset scope (grep-verified; earlier hits were `in_array` false positives).

## Test-coverage review (gaps stated honestly)

Covered well: all flag contracts (now including the enforced-consumable pin), slot rules, inventory doors, lifecycle transitions, sale settlement, trust markers, audit command paths, permission matrix, schema integrity.
**Not covered (accepted, with owners):** concurrency races themselves (F-7/F-8/R-H3 have no failing tests — they are design-level findings; write the race tests WITH the fixes in Phase 2b); `transfer` of an `in_stock` component (would 422 correctly — trivial, add opportunistically); shadow-audit `--since` malformed input; production-shape data volumes (owned by the shadow window itself, which is the real test). One false-security test existed (the consumable/shadow one) — found and re-pointed in this review.

## Remaining Technical Debt (single consolidated list)

Pre-enforced: R-H3 · R-M3 · F-1 · F-7 (+PM-3 alongside) · F-9 · `components:integrity` (R-H1/F-8 detection) · PM-2 sequencing (modal before enforced). Phase 3: endpoints/UI/resources, quarantine-excluded reads, legacy-row labeling. Phase 4: F-10 tyre-trap rule, cross-source dedup, backfill-never-promotable. Housekeeping: unused import, `serviceRecordEntries` naming, ADR §2.1 draft paragraph, untracked root files (`shot_bootstrap.php`, `odoo_backup.dump` — not asset artifacts, confirm with owner).

## Scores

- **Merge Readiness: 92/100.** Deductions: −5 the code is still uncommitted (procedural, but it is THE risk); −3 housekeeping debris (unused import, naming, doc paragraph) that will otherwise fossilize.
- **Production (enforced) Readiness: 58/100.** The missing 42 points are all enumerated and scheduled: shadow gate with volume floor (≈15), the five pre-enforced fixes + integrity command (≈15), Phase 3 modals/endpoints without which enforced stalls the workshop (≈12). Nothing unknown; nothing architectural.

## Fix before merge vs. defer

**Before merge to main:** (1) `git commit` the asset layer on its own branch — the file scope is enumerated in the Code Audit; (2) nothing else — PM-1 is already fixed and tested in this review; the suite is green minus the 2 documented pre-existing failures that belong to (and must block) the separate Incorrect-merge track.
**Defer (scheduled, not forgotten):** everything in the debt list above, in the phase order already committed to in the roadmap.

**Final statement:** after five review passes culminating in this adversarial one, the remaining defect surface consists entirely of items with IDs, owners, phases, and detection mechanisms — plus whatever only real workshop volume can reveal, which is precisely what the currently-running shadow window exists to catch. This is as high as confidence honestly goes before reality votes. Merge it.
