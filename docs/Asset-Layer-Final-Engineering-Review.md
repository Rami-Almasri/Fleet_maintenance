# FleetView Asset Layer — Final Senior Engineering Review

**Date:** 2026-07-23 · **Scope:** everything designed and built in the asset-layer program (9 prior documents + the shipped Phase 1/2a code + the live shadow window). This review adds NEW findings — it does not restate the handoff; where a risk already has an ID there (R-…), it is referenced, not duplicated.

---

# 1. Executive Summary

**Before:** FleetView was a maintenance *event* system — excellent at "what happened" (tickets, faults, dispatch, QC, billing lines) but structurally unable to answer "what IS this vehicle made of right now?". Removed parts did not exist in the data model; warranty was write-only; parts intelligence reconstructed truth from billing strings.

**After:** FleetView is a Fleet Asset Management System. Every vehicle is a container of identifiable physical components with immutable biographies (install → use → removal → disposition), strictly separated from events (tickets) and actions (service records), with inventory as a state of the same asset rather than a parallel system. The decisive architectural properties: one write choke point, append-only history, mandatory dispositions ("nothing disappears"), trust markers (provisional → validated), and a three-mode flag that lets the ledger earn trust in production without ever being able to hurt the money or workflow paths.

**The difference in one sentence:** the old system recorded that money was spent on a car; the new one knows which physical object the money became, where that object is today, and who is accountable for its life.

# 2. Architecture Validation

**The separation is correct** and matches how real fleet/CMMS systems (and accounting: event vs asset vs service) model the domain. Validation findings:

- **Hidden coupling F-1 (REAL, must fix in Phase 2b): billing line replacement breaks provenance.** `syncLineItems`/`MaintenanceInvoiceService::replaceLineItems` DELETE and recreate `maintenance_line_items`. `vehicle_components.source_line_item_id` is `nullOnDelete` — so re-itemizing a ticket after an install silently NULLs the component's billing link (and orphans the `part_purchases.maintenance_line_item_id` the same pre-existing way). Physical truth is unaffected, but cost-lineage reporting degrades. Fix options: re-link by purchase id after replacement, or exempt purchase-generated lines (`entry_source='purchase'`) from replacement. Until then, treat `source_part_purchase_id` as the durable provenance key, `source_line_item_id` as best-effort.
- **Hidden coupling F-2 (watch): two "last done" sources after Phase 2b.** Once `confirmRoutineServices` writes `service_records`, the same fact lives in `ServiceReminder` anchors AND service records. Acceptable ONLY because both are written at the same single gate; never add a second writer to either, and Phase 3's "last done" reads must pick ONE source (`service_records`) and say so.
- **Missing entities (deliberate deferrals, confirm they stay deferred):** Technician (free-text until P3 — quality attribution stops at garage); Warehouse/location entity (single implicit warehouse; multi-location needs a `locations` table later — the `location` string enum will migrate cleanly); **Warranty Claim entity** — today a claim is one `warranty_claimed` event with meta; there is no claim lifecycle (opened → accepted/rejected → credited). Recommend a `warranty_claims` table in Phase 5 when recovery money becomes real; the event stream already carries enough to backfill it.
- **`installer_vendor_id = ticket->vendor_id`** at install time is correct for in-shop but NULL for on-site jobs (ticket has no vendor) — acceptable; document that on-site installs attribute quality to `technician_name` only.

# 3. Database Design Review (at 50k vehicles / 500k components / 10M events)

The schema scales; MySQL with the existing composite indexes handles these volumes comfortably. Findings:

- **F-3 · Missing standalone `component_events(at)` index** — fleet-wide time-range queries ("all component movements in July") currently scan; the per-component/per-vehicle composites don't help. Cheap to add whenever reporting needs it; not needed for shadow.
- **F-4 · `ServiceRecord::lastPerType()` loads all rows per vehicle then de-dupes in PHP.** Fine per-vehicle (hundreds of rows); NEVER call it in a fleet loop — Phase 3's fleet views need a groupwise-max SQL over the `(vehicle_id, service_type, performed_at)` index.
- **F-5 · `components:shadow-audit` loads the whole shadow population into memory.** Fine for a 2-week window; after Phase 4 backfill (500k rows carry `write_mode='backfill'`), any audit-style sweep must chunk. Constrain `--since` or chunk before reusing it as `components:integrity`.
- **F-6 · Brand is free text.** "Bosch"/"BOSCH"/"bosh" will fragment brand-failure analytics. Before Phase 5: either a brand lookup table or normalization at write time (Phase 3 modal should offer known brands). Don't retro-clean silently — normalize at read with a mapping table first.
- **Serial uniqueness has no DB constraint** — already R-H3; at 500k components an application-level check alone WILL eventually admit duplicates. A generated-column unique index (`catalog_id, serial_no, is_live`) or lock-based check is required before enforced.
- **No lifecycle problems found** in the enum/state design at scale; `retired` rows keeping `vehicle_id` makes per-vehicle history O(index) which is exactly right for the profile page. Events being append-only means the table only grows — at 10M rows consider yearly partitioning by `at`, but only when reads actually slow.

# 4. Workflow Review (the six scenarios, adversarially)

| Scenario | Physical truth lost? | Component can disappear? | History can go wrong? | Lifecycle bypassable? |
|---|---|---|---|---|
| **Battery replacement** | No — predecessor close is atomic with install (verified by rollback test) | No — 422 without disposition | Only if the wrong predecessor payload is entered (human error, auditable + correctable via compensating events) | No HTTP path bypasses; direct DB is procedural-only (see §5) |
| **Wrong diagnosis** | No — but **only after someone acts**: the uninstalled purchase is invisible to the asset ledger until intake, and M6/integrity detection is manual until Phase 2b ships the command. Interim exposure documented (R-H1 adjacent) | No | No | The "move to stock" action has NO UI yet — during shadow it requires tinker; acceptable, but log every such action in the shadow log |
| **Tyre transfer** | No — one row, both-side event | No | **F-7:** `transfer()`/`install()` (spare) check the component's status BEFORE the transaction and never re-lock the component row itself (only the target slot). Two concurrent transfers of the same component both pass and produce a contradictory event chain. `remove()` re-locks; these two don't. **Zero exposure today** (no HTTP surface) — but this is a required fix BEFORE Phase 3 endpoints ship. | No |
| **Vehicle sale** | No — settlement forces decisions | No | **F-8:** `settleForVehicleSale` locks the CURRENT active set; a concurrent install on the same vehicle (between lock and commit) inserts a new active row the settlement never saw → sold car with one active component. Nightly integrity catches it; still, Phase 3's settlement endpoint should re-count actives just before commit and abort on drift | No |
| **Warranty comeback** | No — read-side context only; claim = disposition path | No | `in_warranty` snapshot on the claim event is computed at claim time — correct (warranty state at the moment matters, not later) | No |
| **Mixed job (part + labor)** | Part side: solid. **Labor side: NOT BUILT YET** — `installFromPurchase` does not accept labor cost/hours; no `kind=labor` line or `repair_labor` service record is created (designed in Phase2-Workflow-Design §7, deferred). Until Phase 2b/3, labor on installs continues through the existing manual line-item paths — no data is lost, but the component↔labor link is absent | No | No | n/a |

# 5. Security Review

- **RBAC surface is currently minimal and safe:** the only HTTP path into asset writes is the install endpoint (`parts.purchase`); all other ComponentService methods have no routes. The known asymmetry (predecessor block = a removal, performed under `parts.purchase`) is R-M3 — must be resolved before enforced, and every Phase 3 mutating route MUST take `components.manage` (write it into the route review checklist, §10-C).
- **CLI commands check no permissions** (`shadow-audit`, future `backfill`) — standard for this codebase (ops-gated shell), acceptable; the `components.backfill` permission only matters when a UI trigger exists. Do not build a UI trigger without checking it.
- **Privilege escalation:** none found — no mass-assignment path reaches status/location/removal fields; `validated_by` is server-stamped; quarantine requires the CLI.
- **F-9 · Event immutability is convention, not code.** Nothing prevents a future developer calling `$event->update(...)`. Recommend (Phase 2b, 5 lines): override `ComponentEvent` `update`/`delete` to throw. Same consideration for the removal leg after write. Until then it's protected by review discipline only.
- **Direct DB manipulation:** prohibited procedurally (handoff rule 1); MySQL grants are app-wide so a rogue query is technically possible — the mitigation is the audit shape itself (events + integrity scan expose tampering as inconsistency).

# 6. Transaction & Concurrency Review

**CRITICAL — none.** (Single-threaded `artisan serve` in current production materially reduces real exposure; the list below is ranked for the concurrent future.)

**HIGH**
- **F-7 (from §4):** transfer/spare-install lack a re-lock + re-check of the component row inside the transaction (TOCTOU). Fix pattern already exists in `remove()`. Required before any HTTP exposure.
- **R-H3 (handoff):** serial duplicate race — needs a DB-level answer before enforced.

**MEDIUM**
- **F-8 (from §4):** settlement vs concurrent install race → post-settlement active residue; re-check before commit + integrity net.
- **R-M1 (handoff):** shadow savepoint semantics under MySQL deadlock — the swallowed inner failure can poison the outer transaction; re-test under real concurrency before any multi-worker deployment.
- **R-L1 (handoff, pre-existing):** `installPurchase` double-submit lacks a lock on the purchase row; the asset hook inherits whatever duplication the billing path allows. Fix both together.

**Verified sound:** slot locking (`lockForUpdate` on the predecessor query, 409 on multi-active); enforced-mode all-or-nothing (tested); removal atomicity (tested); nested-transaction joining (service `DB::transaction` inside the caller's = savepoint, correct semantics for both shadow and enforced).

# 7. Shadow Mode Review

- **Is two weeks enough? Only if volume is.** The plan measures TIME but not SAMPLE SIZE. Add a volume floor to the gate: **≥ 20 real part installs, of which ≥ 3 replacements exercised the predecessor/disposition flow, ≥ 1 serialized part with a serial, and ≥ 1 warranty-relevant removal.** If the workshop's fortnight doesn't produce that, extend — a quiet two weeks proves nothing.
- **Signals that prove readiness:** the §5 gate criteria + the volume floor + a DECLINING M1 gap trend week-over-week (gaps caused by R-M4 catalog ambiguity should dominate early and shrink as the team learns to pass catalog ids; a flat or rising trend means the modal must ship before enforced).
- **Must BLOCK enforced:** any unexplained M1 gap; any M3/M4 hit ever; any phantom component; R-H3 and R-M3 unresolved; F-7 unfixed if Phase 3 endpoints ship simultaneously; the workshop not briefed on the two new blocking prompts (position, disposition).
- **Acceptable mistakes during shadow:** missing serial/brand/position, missing predecessor closes, catalog-ambiguity gaps — all COUNTED, all expected, all fixed by the Phase 3 modal.
- **Unacceptable at any time:** a component row for a part never fitted (phantom), a row on the wrong vehicle, any billing/workflow disturbance traced to the hook, any manual DB edit of asset tables.

# 8. Future Intelligence Readiness

The handoff §6 table stands. Gaps found in this pass, per capability:

| Capability | Missing data point | Where to add |
|---|---|---|
| Failure prediction / RUL | usage intensity per component (km driven WHILE installed needs odometer-at-events discipline — events already carry `odometer`, but only if UIs always send it: make it required in Phase 3 modals) | Phase 3 UI validation |
| Supplier scoring | purchase PRICE history per part_number is in `part_purchases` (fine), but supplier lead time (ordered→received) needs the audit Part I order-tracking fields — still unbuilt | audit Part I / parts P1 track |
| Workshop scoring | on-site installs attribute to free-text technician only (§2) | technician entity (P3) |
| Warranty recovery | claim OUTCOME (accepted/credited amount) — events record the claim, not the result | `warranty_claims` table (Phase 5, §2) |
| True TCO | consumables cost flows only once service_records writes land (Phase 2b) + backfill depth (Phase 4) | already roadmapped |
| Fleet health | nothing missing structurally — it's a read model over the rest | — |

# 9. Migration & Backfill Risk (dangerous assumptions found)

- **F-10 · THE TYRE TRAP (most dangerous assumption in the current backfill design):** historical tyre lines carry NO position. The design's "latest per (vehicle, catalog, position) = active" rule would put every historical tyre in ONE position-NULL slot, chain four real corners into a fake replacement sequence, and mark ONE tyre active while retiring three that are physically on the car. **Rule for Phase 4: for catalogs with a `position_scheme`, when position is unknown, backfill rows as RETIRED history only (`unknown_legacy`) and create NO active rows** — current tyres/pads enter the ledger through real future installs or a deliberate stock-take, never through slot inference. Better an honest empty "current" than a false one.
- **Duplicate-import cross-source:** a purchase AND the line item it generated describe the same part — the sweep order (purchases first) must EXCLUDE line items already referenced by `part_purchases.maintenance_line_item_id`, not merely already-consumed ids. Make it an explicit exclusion in the command.
- **Old invoices (`invoice_items`)** are description strings — service records only, never components. Confirmed correct in the design; keep it.
- **`installed_by` on legacy rows** is the billing actor, not a fitter — never present backfilled `installed_by_name` as "technician" in UI.
- **Never invent history** is designed in (`unknown_legacy`) — the residual risk is UI presentation: Phase 3 must render legacy rows visibly differently, or users will treat inferred sequences as facts.
- Idempotency/backup/dry-run/purge requirements: already specified; add "run `components:integrity` immediately after backfill and BLOCK promotion of backfill rows entirely" (backfill rows stay `provisional`-equivalent under `write_mode='backfill'` — they are never promoted to `validated`; validated is reserved for observed reality).

# 10. Final Production Checklists

**A) Shadow continuation (daily/weekly — active now):** M1–M4 daily, gaps reconciled vs log; shadow log updated; weekly physical spot-check; flag-rollback drill once in week 1; volume floor tracked (§7); commit the asset layer branch (overdue).

**B) Before `enforced`:** gate §5 + volume floor met; `components:shadow-audit` final run clean; `--promote` executed; R-H3 (serial index/lock), R-M3 (route permission), F-1 (provenance on line replacement) resolved; F-7 fixed if any new endpoint ships with it; `components:integrity` scheduled and green ≥ 1 week; workshop briefed on the two blocking prompts; full Crud suite green under `shadow` mode config; fresh backup.

**C) Before UI release (Phase 3):** every mutating route gated `components.manage`; every read excludes `quarantined`; response-shape snapshots for re-pointed endpoints green; odometer required on install/remove/transfer modals (§8); Playwright pass; SHOW_FINANCIALS honored; legacy rows visually distinct; F-9 immutability guard shipped.

**D) Before backfill (Phase 4):** rehearsed on restored backup with `--dry-run` report reviewed by owner; tyre-trap rule (F-10) implemented; cross-source exclusion implemented; purge counterpart tested; `db:backup` fresh; run windowed (off-hours); integrity scan clean after; backfill rows never promotable.

**E) Before intelligence (Phase 5):** gate passed + backfill done + catalog `expected_life_*` reviewed vs first actuals; brand normalization decided (F-6); every figure has a `ComponentExplainer`; money figures labeled with completeness per the financial source-of-truth contract.

# 11. Things nobody should change (the 25 rules)

Rules 1–12 are in the handoff §7 — they stand. Additional 13:

13. Never re-order the flag semantics: `off` < `shadow` < `enforced` is a one-way ratchet per environment; downgrades are rollbacks and restart the validation clock.
14. Never promote `write_mode='backfill'` rows to `validated` — validated means observed, not inferred.
15. Never infer positions for position-scheme catalogs during backfill (the tyre trap, F-10).
16. Never write `ServiceReminder` anchors or `service_records` from anywhere but the `confirmRoutineServices` gate.
17. Never add a second workflow→asset write seam without a design document and a flag.
18. Never let a fleet-wide view call per-vehicle helpers in a loop (`lastPerType`, biography loads) — write the SQL.
19. Never make the OM sync wait on, fail on, or prompt for asset questions.
20. Never surface `installed_by` as "technician" — they are different people until the technician entity exists.
21. Never trust `source_line_item_id` as the primary provenance key (F-1) — `source_part_purchase_id` is the durable one.
22. Never add a disposition/reason enum value without updating: the model constants, the outcome map in `ComponentService`, the audit command checks, and the design doc — all four, same PR.
23. Never run asset tests on sqlite and call them green.
24. Never change `component_catalog.slug` values — they are the seed identity; rename via `name`, retire via `is_active`.
25. Never resolve a slot conflict (two actives) programmatically — a human decides which part is real, always.

# 12. Final Recommendation

**Is the architecture production-grade? YES.** The four-entity separation, single write choke point, append-only events, mandatory dispositions, trust markers, and the three-mode flag are the right shapes, correctly reasoned, with rejected alternatives documented. Nothing in this review found an architectural error — the findings are implementation hardening and operational discipline.

**Is the implementation production-grade TODAY? Not yet — it is exactly where the plan says it should be:** a correctly dark-launched foundation in its validation window. Before real customers (or real management decisions) depend on the ledger:

1. **Commit the code** (it is uncommitted on a shared working tree — the single most fragile fact of the whole program).
2. Pass the shadow gate WITH the volume floor (§7), then fix-before-enforced: serial uniqueness (R-H3), route permission (R-M3), provenance-on-replacement (F-1), TOCTOU re-locks (F-7) and ship `components:integrity`.
3. Ship Phase 3 UI so the disposition/serial/position prompts stop being API-only — enforced mode without the modal would grind the workshop.
4. Backfill with the tyre-trap rule (F-10) — an honest sparse history over a confident wrong one.
5. Only then let intelligence read it.

**Top 5 remaining risks, ranked:**
1. **Uncommitted code on a shared branch** — one careless `git checkout .` erases the program. (Operational, trivial to fix, highest probability × impact today.)
2. **F-10 tyre-trap backfill assumption** — the one path that could make the ledger CONFIDENTLY WRONG, which is worse than empty.
3. **R-H3 serial race without a DB constraint** — silent identity corruption at scale.
4. **R-H1/F-8 settlement gaps until `components:integrity` ships** — sold cars with live components is the invariant customers would notice first.
5. **F-1 provenance erosion via line-item replacement** — slow, silent decay of cost lineage that no one sees until a TCO report is wrong.

None of these is architectural. The system was designed so that every one of them is detectable, bounded, and fixable — which is, in the end, the review's strongest endorsement of the design.
