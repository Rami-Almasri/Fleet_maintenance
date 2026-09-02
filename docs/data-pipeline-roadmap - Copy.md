# Master Data Pipeline Roadmap — Odometer/Mileage & Maintenance Cost

**Status:** Audit / review document. **No writer code has been changed.**
**Purpose:** Map every writer, source, and precedence rule for the two most safety-critical fields in the system — `vehicles.odometer` and maintenance `cost` — so we can agree a refactor *before* touching the live writers.
**Scope root:** all paths are relative to `backend/` unless noted.
**Produced:** 2026-07-06 (code audit). Re-verify line numbers before editing — the code moves.

> This document exists to satisfy the **Traceability Visibility** rule: no field should be a black box. For each field below you can see *where it comes from*, *who writes it*, *in what order*, and *where the ambiguity is*.

---

## 0. Traceability Map (one-glance summary)

| Field | Sources (where from) | # Writers | Single owner today? | Biggest risk |
|---|---|---|---|---|
| `vehicles.odometer` | OM API `Milage`, contract handover readings, validated baseline chain, FASTER sheet `km`, manual UI, inspector test-drive | **7** | ❌ No — 3 different guard policies | Manual "Apply Baseline" can silently lower it (Risk #1) |
| `vehicles.baseline_odometer` | earliest valid contract OUT reading | 2 | ✅ `MileageBaselineService` | — |
| `contracts.out_milage` / `in_milage` | OM API (authoritative) + retired sheet importer | 2 | ✅ OM API overwrites each sync | Legacy sheet path still live |
| `mileage_overrides.corrected_value` | human (Mileage Chain Audit page) | 1 | ✅ `MileageChainService` | Never consumed by the healers (Risk #7) |
| ticket `*_odometer` (test/dispatch/receive/return) | inspector/garage readings | 4 (ticket-only) | ✅ `MaintenanceWorkflowService` | 3 of 4 orphaned — never heal the car (Risk #2) |
| `maintenances.cost` | Σ line items, or manual close/recordCost lump sum | 5 | ⚠️ Contested (`cost_is_itemized`) | Manual vs itemized divergence (Risk #3/#4) |
| `maintenance_line_items.line_total` | `qty × unit_price` (model hook) | 1 | ✅ model `booted()` | Duplicated sum in GarageInvoiceService (Risk #10) |

---

## A. Odometer / Mileage — Writers & Sources

### A1. `vehicles.odometer` (the live car-card reading) — SEVEN writers

| # | Writer | File:line | Source | Guard |
|---|--------|-----------|--------|-------|
| 1 | `OfficeManagerSync::mapApiVehicle` → `importFleetVehicles` | maps `OfficeManagerSync.php:256` (`'odometer' => Milage ?? 0`); saved via `fill($data)->save()` `:206` | OM API `Milage` | only-up `:203-205`; web-origin skip `:197-198` |
| 2 | `OfficeManagerSync::reconcileOdometersFromContracts` | raw `DB::update` `:574-595`, invoked `:533` at end of every `om:sync` | latest contract handover reading, `ORDER BY ev_date DESC, milage DESC` | `WHERE r.reading > v.odometer` + web-origin skip `:593` |
| 3 | `MileageBaselineService::apply` | `:384` (`$updates['odometer']=$target`) via `update()` `:393` | validated contract chain (`latest_valid`) | `shouldCorrect` `:379-381`: seed / raise / heal DOWN only if typo `>30000km` above chain; web-origin skip `:378` |
| 4 | `MileageBaselineService::applyOne` (manual "Apply Baseline") | `:325` (`$v->odometer=$a['latest_valid']`), save `:330`; entry `VehicleController::applyBaseline` `VehicleController.php:220-224` | validated chain latest | **NO forward-only guard** (see Risk #1) |
| 5 | `VehicleImporter` (FASTER sheet enrich) | `VehicleImporter.php:107` (`km ?? 0`); preserve/skip `:133-140`; save `:154` | Google sheet "km" column | fills only when existing null/'' UNLESS `overwriteEnrichment`/`overwriteVins`; web-origin skip `:96` |
| 6 | `VehicleService::store` / `update` | `StoreVehicleRequest.php:36` / `UpdateVehicleRequest.php:49` (`odometer nullable|integer|min:0`); controller `VehicleController.php:289,661` | manual UI form | validation only — **no only-up guard** |
| 7 | `MaintenanceWorkflowService::applyTestOdometer` | `:2658-2662` (`$vehicle->odometer=$odometer; save()`); from `startTestDrive` `:313` and ops test-drive `:401` | inspector test-drive reading | forward-only `:2660` (`null || current<reading`) |

### A2. `vehicles.baseline_odometer` — 2 writers
`MileageBaselineService::apply` `:370` (bulk) and `::applyOne` `:327` (manual). Source = earliest valid OUT reading in contract history; also stamps `baseline_synced_at`.

### A3. Contract handover readings `contracts.out_milage` / `in_milage`
- **OM API (authoritative, overwrites every sync):** `OfficeManagerSync.php:906` (`OutMilage`) / `:911` (`InMilage`).
- **Legacy sheet importer:** `ContractImporter.php:89` / `:95` — sheets retired per project memory, code path still present.

### A4. Non-destructive corrections `mileage_overrides.corrected_value`
`MileageChainService::saveOverride` `:195-211` (updateOrCreate); `::clearOverride` `:214-217`. Human input on the Mileage Chain Audit page. **Never touches the `contracts` row.**

### A5. Maintenance ticket odometer columns (`test_/dispatch_/receive_/return_odometer`)
All written on the ticket only, in `MaintenanceWorkflowService.php`:
- `test_odometer` `:299,:370`; `dispatch_odometer` `:1216`; `receive_odometer` `:1308`; `return_odometer` `:1462`.
- Each runs through `OdometerContinuityService::evaluate` (`recordOdometerFlag` `:132-134`); verdict stored in `maintenances.odometer_flags` (classification only — never blocks).
- **Critical:** of these four, **only `test_odometer` propagates to `vehicles.odometer`** (`applyTestOdometer`). `dispatch/receive/return` are orphaned.

### A6. `maintenance_line_items.installed_odometer`
`MaintenanceWorkflowService::applyLineItems` `:2359` — user value if numeric, else fallback `$fallbackOdo = return_odometer ?: receive_odometer` `:2323`. Part lines only. *(This is what stamps the mileage on a fitted tyre.)*

---

## Odometer — Precedence & Cascades

**Nightly order** (`routes/console.php`):
1. `om:sync` ~03:00 → writer #1 (API `Milage`, only-up) then writer #2 (`reconcileOdometersFromContracts`, only-up from latest handover) `OfficeManagerSync.php:533`.
2. `mileage:scan --apply` **03:45** (`console.php:55`) → writer #3, the only automated path that can heal a reading **DOWN** (typo `>30000km` above the validated chain).
3. `service:sync-reminders` 04:00 — reads odometer, never writes it.

**Effective precedence:** last-writer-wins = the 03:45 baseline scan, but only inside its narrow correction policy. Between 03:00 and 03:45 the car can display an OM typo.

**Placeholder convention:** null/0/1 = "no reading" (`MileageBaselineService::PLACEHOLDER_MAX=1`, `MileageChainService::isReal`).

**No observer** heals odometer on individual contract writes — healing is batch-only. Confirmed: no odometer logic in `app/Observers`.

---

## B. Maintenance Cost — Writers & Sources

### B1. `maintenances.cost` (ticket grand total)

| Writer | File:line | Source | Notes |
|--------|-----------|--------|-------|
| `Maintenance::recalcLineItemTotals` | `Maintenance.php:806` | Σ line items (parts+labor) | **Owns `cost` when itemized**; sets `cost_is_itemized=true` `:807` |
| `MaintenanceWorkflowService::markReady` | `:1476` | garage close-out form | overridden by `applyLineItems` `:1490-1492` if line_items supplied |
| `MaintenanceWorkflowService::close` | `:1896` | inspector sign-off | manual lump sum |
| `MaintenanceWorkflowService::recordCost` (deferred) | `:2087` | late invoice entry | stamps `cost_recorded_at/by` |
| `syncLineItems` → `applyLineItems` | totals `:2371`, save `:2213` | itemised invoice | variance gate vs `receipt_total` `:2189` |

### B2. `maintenances.parts_total` / `labor_total`
**Only** `Maintenance::recalcLineItemTotals` `:804-805` (set) / `:795-796` (nulled when line set empty).

### B3. `maintenance_tasks.parts_cost` / `labor_cost`
**Only** `MaintenanceTask::recalcCosts` `:154-155`, `saveQuietly()` `:156`. Source = its line items grouped by kind.

### B4. `maintenance_line_items.line_total`
Auto-derived in `MaintenanceLineItem::booted` saving hook `:78` = `round(qty × unit_price)`. `fillable` includes it `:48` but the hook always overwrites — raw inputs are `quantity`/`unit_price`. `warranty_until` similarly derived `:80-84`.

### B5. `garage_invoice_submissions.parts_total` / `labor_total`
`GarageInvoiceService.php:110-121` — submission-side preview summed by private helper `:222` ("mirrors the model's qty×unit_price"). On acceptance routes through `workflow->syncLineItems` `:175` (the canonical path).

---

## Cost — Rollup & Cascades

Event-driven through model hooks:

```
MaintenanceLineItem saved/deleted            (MaintenanceLineItem.php:90-91)
   → rollUpCosts()                            (:99-113)
       ├─ if line has task(s):
       │     MaintenanceTask::recalcCosts()   (MaintenanceTask.php:150-159)
       │        → sets parts_cost/labor_cost, saveQuietly
       │        → optional(maintenance)->recalcFromTasks(true)   (:158)
       └─ else (task-less general charge):
             maintenance->recalcFromTasks(true)   (MaintenanceLineItem.php:112)

MaintenanceTask saved/deleted                 (MaintenanceTask.php:74-75)
   → maintenance->recalcFromTasks(true)

Maintenance::recalcFromTasks(bool fresh)      (Maintenance.php:755-779)
   → recalcLineItemTotals()  → cost / parts_total / labor_total / cost_is_itemized
   → rolls up worst OPEN fault_severity (:770-775)
   → saveQuietly()  (:778)   [does NOT touch vendor_id or workflow_status — by design]
```

- `saveQuietly()` on every rollup write prevents infinite hook recursion.
- `recalcLineItemTotals` is **pure in-memory** (no save) so it composes inside `markReady`'s transaction; `recalcFromTasks` persists.
- Ticket cost does **not** roll up to any contract/vehicle column — `maintenances.cost` is terminal; readers (`RealProfitService`, `OperationsService`) aggregate it live.

---

## Risks & Inconsistencies (ranked)

1. **`applyOne` has no forward-only guard (silent odometer downgrade).** `MileageBaselineService.php:325` sets `odometer = latest_valid` unconditionally, unlike batch `apply()` which gates with `shouldCorrect` `:379-383`. Clicking "Apply Baseline" (`VehicleController::applyBaseline`) on a car whose validated chain sits *below* the live reading lowers the odometer with no warning. Two code paths, two policies, same button-family.

2. **Post-repair readings are orphaned.** `dispatch/receive/return_odometer` (`MaintenanceWorkflowService.php:1216,1308,1462`) are the freshest real mileage the fleet captures, but none heal `vehicles.odometer` or write back to `contracts` — only `test_odometer` does (`applyTestOdometer:2658`). The car card can lag actual last-known mileage indefinitely. (`MileageBaselineService`'s "maintenance carries no mileage" docblock `:31-33` is now stale.)

3. **Manual `cost` vs itemized `cost` can silently diverge.** `recordCost` `:2087` and `close` `:1896` overwrite `maintenances.cost` directly **without** clearing `cost_is_itemized` or the line items. An itemized ticket later given a different lump sum still advertises `cost_is_itemized=true` while `cost ≠ parts_total+labor_total`. Ambiguous ownership.

4. **Deleting all line items leaves a stale itemized cost.** `recalcLineItemTotals` `:794-798` returns early on empty set — clears `cost_is_itemized=false`, nulls `parts_total/labor_total`, but **leaves the last-computed `cost`** ("a lump-sum stays as the manager typed it"). After de-itemizing, `cost` is a fossil of deleted lines.

5. **Two competing odometer healers, different algorithms, 45 min apart.** `reconcileOdometersFromContracts` (latest-by-*date*, only-up, `:581`) vs `MileageBaselineService::apply` (validated-*chain*, can heal down). They can pick different "latest" values.

6. **`reconcile` SQL tiebreak can import a typo.** `ORDER BY ev_date DESC, milage DESC` `:581` — on equal dates prefers the *higher* reading, exactly the "OutMilage typed higher than InMilage" case its own docblock `:567-569` warns against.

7. **Mileage overrides never influence the healed odometer.** `mileage_overrides.corrected_value` is read **only** by `MileageChainService` (audit view). Neither healer consults it, so a human chain correction is cosmetic — it does not fix the live odometer or baseline.

8. **`VehicleImporter` odometer uses `?? 0` under overwrite flags.** `:107` — with `overwriteEnrichment`/`overwriteVins` set and a blank sheet "km", it writes `0`. The preserve check `:135` tests only `null`/`''`, not `0`.

9. **Manual UI odometer edits bypass only-up.** `UpdateVehicleRequest` allows any `>=0`; no provenance/lock distinguishes a deliberate manual fix from a typo.

10. **Duplicated cost-summing logic.** `GarageInvoiceService.php:222` re-implements `qty × unit_price` rounding already in `MaintenanceLineItem::booted:78`. Two implementations that can drift.

---

## Open Questions for the refactor (decide before touching writers)

1. **One odometer authority?** Seven writers, inconsistent guards (only-up ×4, forward-only ×1, none ×2). Consolidate behind a single `setOdometer(value, source, allowDecrease)` service.
2. Should **`return_odometer` (post-repair) feed** the baseline engine / live odometer? If yes, `MileageBaselineService` needs a maintenance-reading source alongside contract readings.
3. Should **`mileage_overrides` be consumed by the healing engines** (not just the audit view) so human corrections actually stick?
4. Define the **single owner of `maintenances.cost`**: always `parts_total+labor_total` when itemized? Should `recordCost`/`close` refuse to overwrite an itemized cost (or force de-itemization)? Resolve the de-itemize fossil (Risk #4).
5. Should **`applyOne` reuse `apply()`'s `shouldCorrect`** so manual and batch corrections share one policy?
6. Fix the **`reconcile` tiebreak** (Risk #6) — order by date then prefer the IN reading, or exclude same-contract Out>In rows.
7. **Retire/gate legacy sheet paths** (`ContractImporter` mileage, `VehicleImporter` odometer `?? 0`) now that OM API is the declared source of truth.

---

## Proposed sequencing (for the reviewed refactor PR — not yet started)

- **Phase 1 (safety, low-risk):** add the missing forward-only guard to `applyOne` (Risk #1); fix `VehicleImporter` `?? 0` under overwrite (Risk #8); fix the `reconcile` tiebreak (Risk #6). Pure guard additions, no behavioural surprise.
- **Phase 2 (ownership):** introduce a single `setOdometer()` authority; route all 7 writers through it with an explicit `source` + `allowDecrease` (Open Q1). Feed `return_odometer` + `mileage_overrides` into it (Open Q2/Q3).
- **Phase 3 (cost ownership):** make `maintenances.cost` ownership explicit — `recordCost`/`close` must de-itemize or refuse when `cost_is_itemized` (Open Q4); clear the fossil cost on full de-itemize.

Each phase is independently reviewable and shippable. **No code from any phase has been written** — this document is the review artifact that precedes it.
