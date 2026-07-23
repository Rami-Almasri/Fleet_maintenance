# Asset Layer — Shadow Launch Plan (production safety review)

**Date:** 2026-07-23 · **Status:** FOR APPROVAL — no code changes in this document · **Parents:** `Asset-Layer-Phase2-Final-Review.md` (edge cases locked), Phase 2a shipped dark (flag `off`, full regression green).

**Goal:** flip `ASSET_LAYER_MODE=shadow` in production safely, run 2 weeks of real workshop activity, and PROVE the asset ledger matches physical truth before anything reads from it or blocks on it.

---

## 1. Shadow mode behavior (exact contract)

**Writes that happen in shadow** — one call site only, `PartWorkflowService::installPurchase`:
- a `vehicle_components` row per part install (active, install leg from the purchase),
- predecessor close **only when** the modal/API supplied a `predecessor` block (nothing is guessed),
- `component_events` rows + their `vehicle_log_events` timeline mirrors,
- nothing else: no ticket writes, no billing writes, no service_records yet (that hook is Phase 2b), no reads anywhere in the UI.

**Source of truth stays exactly where it is today:** ticket lifecycle = `workflow_status` engine; money = `maintenance_line_items` / invoices; service anchors = `ServiceReminder` via `confirmRoutineServices`; parts procurement = `part_requests`/`part_purchases`. Shadow rows are OBSERVATIONS. If shadow and reality disagree, reality wins and shadow gets corrected — never the other way.

**If ComponentService fails:** the call is inside `try { … } catch (\Throwable $e) { report($e); }` — the billing install commits regardless. A shadow failure can never block, delay, or roll back workshop work. (Verified by test `test_shadow_mode_failure_never_blocks_the_billing_install`.)

**Where failures are logged:** `report()` → the standard Laravel channel (`storage/logs/laravel.log` in the container). Two log signatures matter:
- exceptions whose trace contains `ComponentService` — a failed shadow write (expected causes at first: ambiguous catalog 422, missing serial 422, occupied slot without predecessor 422);
- `asset_layer.position_missing` warnings — a positioned catalog installed without a corner/axle (shadow measures this instead of blocking).

**How we detect MISSING component writes** (the failure mode logging can't show — a swallow means no row): the gap query is the join between the two ledgers:
```sql
-- Installed parts with no component row = every shadow miss, by definition
SELECT pp.id, pp.part_name, pp.category_key, pp.installed_at, pp.vehicle_id
FROM part_purchases pp
LEFT JOIN vehicle_components vc ON vc.source_part_purchase_id = pp.id
WHERE pp.installed_at >= '<SHADOW_START>' AND vc.id IS NULL;
```
Every row here MUST correspond to a reported exception in the log; a gap row with no matching log line is a P1 bug in the hook itself.

## 2. Production monitoring (the shadow report)

Daily report = the seven queries below. Proposal (needs your OK, ships WITH the shadow deploy): wrap them in one **read-only** command `components:shadow-report [--since=]` printing a scorecard — monitoring only, zero writes, so it does not violate "no UI reads"; until approved, the SQL runs via `mysql`/tinker as-is.

| # | Check | Query core | Healthy |
|---|---|---|---|
| M1 | Installed parts WITHOUT components (the gap) | §1 query | 0 unexplained (each gap row ↔ one logged exception) |
| M2 | Components WITHOUT source purchase | `vehicle_components WHERE source='workflow' AND source_part_purchase_id IS NULL AND created_at >= shadow_start` | 0 (workflow rows always carry provenance) |
| M3 | Active slot conflicts | `SELECT vehicle_id, component_catalog_id, position, COUNT(*) c FROM vehicle_components WHERE status='active' GROUP BY 1,2,3 HAVING c>1` | 0 always (the 409 guard makes new ones impossible) |
| M4 | Removed without disposition | `WHERE removed_at IS NOT NULL AND (disposition IS NULL OR removal_reason IS NULL)` | 0 always (closeOut is atomic — any hit is a code bug) |
| M5 | Warranty data quality | shadow-window components: % with `warranty_months` set; `warranty_until` NULL while months+installed_at set (derivation failure = bug); claims: `warranty_claimed` events with `meta.in_warranty=false` (claiming outside warranty — process smell) | months coverage reported (target §5); derivation failures 0 |
| M6 | Purchased, waiting for installation | `part_purchases WHERE installed_at IS NULL AND purchased_at < NOW()-INTERVAL 7 DAY` and no component via intake | reviewed weekly, each has an owner |
| M7 | Vehicles with incorrect component state | invalid (status,location) pairs; `active` with NULL vehicle; `in_stock` with vehicle; `active` components on `sold/disposed` vehicles | 0 for the first three (model guard); sold-car actives listed for settlement |

Cadence: M1–M4 daily (5 minutes), M5–M7 twice a week. Findings + the position_missing count go into a dated section of a running `docs/Asset-Layer-Shadow-Log.md` — that file is the evidence base for the §5 gate decision.

## 3. Workshop validation process (the 2 weeks)

- **Daily (office, ~10 min):** run M1–M4; reconcile every M1 gap against the log; note the day's installs count vs component rows created.
- **Physical spot-check (2–3 vehicles/week):** pick vehicles that had an install that week (+1 random car with no recent work as a control). The **Inspector (Abu Maroof)** physically confirms — is the recorded part actually on the car (brand/serial where readable)? Is the removed part where its disposition says (scrap bin / store shelf / sent to supplier)? The **Supervisor (Waleed/Abdullah)** compares against the shadow rows (via tinker/SQL — no UI yet) and records match/mismatch in the shadow log. Admin (you) reviews weekly.
- **Acceptable differences during shadow:** missing serial/brand detail (UI doesn't prompt yet), missing position on tyres/pads (counted, not judged), missing predecessor closure (old part still shows active because the modal didn't ask — counted as "predecessor gap", expected until enforced). **Not acceptable:** a component row for a part that was never fitted (phantom), wrong vehicle, M3/M4 violations, any billing/workflow disturbance traced to the hook.
- **Corrections:** always through `ComponentService` semantics, never raw UPDATEs — wrong-slot/phantom rows are closed via `remove()` with an honest disposition + note (`removal_note: 'shadow correction'`), real predecessor gaps are closed the same way; events stay append-only so every correction is itself auditable. (Executed via tinker by admin until the modals exist.)

## 4. Rollback plan

- **Trigger:** any billing/workflow disturbance traced to the hook (should be impossible by construction — immediate flag-off + investigate), or shadow noise so high the data is unusable.
- **How:** set `ASSET_LAYER_MODE=off` in the container `.env` + `php artisan config:clear` (config isn't cached on this deployment; if that changes, `config:cache`). No deploy, no migration. The hook short-circuits; behavior returns byte-identical.
- **Already-created rows:** KEEP them. They are additive observations nothing reads; deleting history to hide a bug is exactly what this layer exists to prevent. If a systematic defect corrupted a batch, correct via compensating `remove()` calls (audited), or — worst case, pre-enforced only — a reviewed, backup-gated purge of rows `WHERE source='workflow' AND created_at BETWEEN <bad window>` with the decision recorded in the shadow log.
- **Resume:** fix → re-run both asset suites + full Crud regression locally → flip back to shadow → **the 2-week clock restarts** if the defect affected data quality (it's a validation window, not a countdown).

## 5. Shadow acceptance criteria (gate to `enforced`)

Measured over the FINAL 7 consecutive days of the window (so early teething doesn't mask or poison the result):

1. **Coverage:** ≥ 95% of ticket part installs produced a component row automatically; 100% of the remainder are explained (logged exception with a known cause) and manually reconciled. No unexplained gap.
2. **Integrity:** 0 occurrences of M2, M3, M4, and the first three M7 classes, across the whole window.
3. **Physical truth:** every spot-checked vehicle (≥ 4 vehicles) matches — parts recorded = parts on the car; every checked removed part is where its disposition says. 0 phantom components ever.
4. **Workflow safety:** 0 billing or workflow failures attributable to the hook; install latency not noticeably degraded (spot-check timings).
5. **Warranty chain:** ≥ 80% of new components carry `warranty_months`; `warranty_until` derived on 100% of those; at least one real replacement exercised the predecessor flow end-to-end (removal reason + disposition + `replaced_by` chain + events) correctly.
6. **History mechanics:** every transfer/removal performed during the window shows the correct event chain and timeline mirrors.
7. **Readiness residue:** `position_missing` and predecessor-gap rates are KNOWN numbers with a plan (they become blocking 422s in enforced — the workshop must be briefed on exactly those two prompts before the flip).

All seven or no flip. Partial pass = extend shadow, fix, re-measure.

## 6. Production deployment checklist (the flip itself)

Server: VFZDubai `ali@81.85.92.150:8087` (Docker; remember the umask quirk — after any image deploy: `chown -R www-data /var/www/html` + `chmod 0644` php configs).

**Pre-flip (in order):**
- [ ] Phase 1+2a code deployed (this is the FIRST production deploy of the asset layer — Phase 1 has only run locally; prod currently has neither tables nor hook)
- [ ] `php artisan db:backup` on the server — fresh dump verified present in `storage/app/backups`
- [ ] `php artisan migrate --force` → exactly the 5 `2026_07_24_1000xx` migrations run; `php artisan migrate:status | tail` shows them Ran
- [ ] `php artisan db:seed --class=RolesAndPermissionsSeeder --force` then `--class=ComponentCatalogSeeder --force`; verify `component_catalog` = 21 rows and the role grant matrix (SQL from the Phase 1 verification) — manage on manager/maintenance/supervisor only
- [ ] Confirm flag reads `off` and the app is byte-identical: one manual part install on a test ticket → `vehicle_components` count stays 0
- [ ] Run the Crud suite against the prod schema copy if feasible (or rely on the local 191-test run recorded 2026-07-23)

**Flip:**
- [ ] `.env`: `ASSET_LAYER_MODE=shadow` → `php artisan config:clear`
- [ ] Smoke: one real (or test-ticket) part install → expect a `vehicle_components` row + `installed` event + timeline entry; check `laravel.log` for a clean pass
- [ ] Note the timestamp as `<SHADOW_START>` in `docs/Asset-Layer-Shadow-Log.md` (created same day) — the 2-week clock starts here

**Standing (daily/weekly):**
- [ ] M1–M4 daily, M5–M7 twice weekly (§2), results into the shadow log
- [ ] `laravel.log` watched for `ComponentService` exceptions + `asset_layer.position_missing`
- [ ] Weekly physical spot-checks (§3) recorded
- [ ] Week-2 review: score §5 → decision (enforce / extend / rollback) recorded in the shadow log

**Explicitly NOT in this launch:** UI reads, service_records writes, backfill, notifications/detectors, the `components:integrity` scheduled command (its checks run manually as M-queries during shadow; the command ships with Phase 2b after the gate).

---

# 7. Shadow Data Recovery & Correction Strategy

## 7.1 Shadow data ownership — provisional until the gate passes

Shadow-created components are **PROVISIONAL observations, not trusted records**, until the §5 gate passes and they are explicitly promoted. This is now a first-class marker on the row (one additive migration, `2026_07_24_100500`, added to `vehicle_components` BEFORE the first production deploy so no data ever exists without it):

| Column | Values | Meaning |
|---|---|---|
| `write_mode` | `off` \| `shadow` \| `enforced` (stamped from the flag at write time) | which regime created the row — the "created_by shadow_asset_layer" marker. Backfill rows (Phase 3) will stamp `backfill` |
| `validation_status` | `provisional` (default) \| `validated` \| `quarantined` | trust state. `provisional` = observation; `validated` = confirmed against reality/checks; `quarantined` = known-bad, excluded from every future read surface, kept for audit |
| `validated_at` / `validated_by` | timestamp / user FK | who promoted it and when |

Combined with `created_at >= <SHADOW_START>` and `source='workflow'`, any query can isolate the shadow population exactly. Rule for Phase 2b and beyond: **no read surface (UI, context endpoint, intelligence) ever consumes `quarantined` rows, and pre-gate they treat `provisional` as clearly-labeled unconfirmed data** (post-gate, promoted rows are the trusted base).

## 7.2 Bug scenario handling (wrong records created during shadow)

1. **Identify:** scope by marker — `write_mode='shadow' AND created_at BETWEEN <bug window>` intersected with the specific defect signature (e.g. wrong catalog resolution → filter by catalog/category). The M-queries plus the shadow log's daily counts bound the window; `component_events` gives per-row forensics.
2. **Correct — three sanctioned tools, nothing else:**
   - wrong/phantom row still active → `ComponentService::remove()` with an honest disposition + `removal_note: 'shadow correction: <defect ref>'`;
   - row is real but untrustworthy → mark `quarantined` via the audit command (§7.3) — kept, excluded, auditable;
   - systemic batch from one defect → quarantine the whole window scope via the audit command, never row-by-row hand edits.
3. **No manual database edits:** raw UPDATE/DELETE on `vehicle_components`/`component_events` is prohibited (the same discipline as `maintenances`). Every correction path above writes through the service or the command, so it stamps events/markers. Enforcement is procedural (this document + code review) — MySQL grants stay as they are.
4. **Audit history preserved:** events are append-only; corrections ADD events (a compensating `removed`, a quarantine marker with reason in the command output logged to the shadow log). Nothing is ever rewritten, so the bug itself remains visible history.

## 7.3 Shadow validation workflow — `php artisan components:shadow-audit`

Ships WITH the flip (read-only by default; this answers §6's open question — it replaces the proposed `shadow-report`). Runs the M-checks scoped to the shadow population and prints the scorecard:

```
components:shadow-audit [--since=] [--promote] [--quarantine=ids] [--json]

  Shadow component writes:        47
  ├─ pass all checks:             44
  ├─ mismatches:                   3
  │    M1 gaps (installs w/o component):  2   ← listed with purchase ids
  │    missing provenance (M2):           0
  │    slot conflicts (M3):               0
  │    removal w/o disposition (M4):      0
  │    invalid state pairs (M7):          0
  │    no serial on serialized:           1   ← listed
  ├─ unresolved (provisional):     3
  └─ recommended action:  RECONCILE 2 gaps against laravel.log; fix/quarantine 1 serial row.
```

- default: report only (the daily §2 tool).
- `--promote`: marks every shadow row that passes ALL checks `validated` (+validated_at/by). Failing rows stay `provisional` and are listed. Used once at the gate (and safe to re-run).
- `--quarantine=1,2,3`: explicit known-bad marking with a mandatory `--reason=`.

## 7.4 Data promotion (shadow → enforced)

- **Nothing becomes official automatically.** The flip to `enforced` changes future writes only; it does not touch existing rows.
- **Gate day sequence:** run `components:shadow-audit` one final time → resolve or quarantine every listed mismatch → `--promote` (clean rows → `validated`) → only then flip the flag. Post-flip, enforced-mode rows are born `validated` (they passed blocking validation at write time); shadow-era `provisional` leftovers must be zero — anything unresolvable is `quarantined` with a reason, never silently carried.
- **Unresolved records:** blocked from promotion by definition. If any remain that can't be quarantined with a clear reason, the gate FAILS (§5 criterion 1's "100% explained" clause) and shadow extends.

## 7.5 Production safety — final pre-shadow checklist (supersedes §6 pre-flip)

- [ ] `php artisan db:backup` — fresh dump verified present
- [ ] Migration rollback tested on a copy of the production DB **including migration 100500** (the Phase-1 five were verified 2026-07-23; re-verify all six on a fresh copy before deploy)
- [ ] `php artisan migrate --force` — all six asset migrations Ran
- [ ] Seeders run + catalog(21)/grant-matrix verified
- [ ] **Flag-OFF smoke test:** one install through the real code path → zero rows in all four asset tables
- [ ] Logs tailed during the smoke (`laravel.log`) — clean
- [ ] Flip `ASSET_LAYER_MODE=shadow` + `config:clear`
- [ ] **Shadow smoke test:** one install through the real code path → component row + `installed` event + timeline mirror, `write_mode='shadow'`, `validation_status='provisional'`
- [ ] **First REAL workshop install manually observed** (office watches the row appear as the workshop works) — recorded as the opening entry of `docs/Asset-Layer-Shadow-Log.md` with `<SHADOW_START>`

---

*Approved for execution: deploy migrations → verify flag-OFF behavior → enable shadow → start the 2-week window. No UI reads until the §5 gate passes.*
