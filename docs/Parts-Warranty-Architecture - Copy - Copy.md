# Parts Catalog & Warranties — architecture

The foundation layer for two things the fleet could not previously do: **name a part the same way
twice**, and **hold a supplier or a garage to what they promised**.

Status: backend complete, 77 tests green, no frontend beyond one unwired page.

---

## 1. Why it exists

**The catalog.** `component_catalog` was authored in a PHP config file and unreachable from the app.
The consequence was not theoretical: `maintenance_required_parts.part_name` was a free-text box, so
"Brake pads", "Front brake pads (set)" and "break" were three unrelated strings for one part.
Nothing could be counted, grouped or joined. A vocabulary nobody can correct is a vocabulary nobody
uses, so the DB became the source of truth and the config became a starting seed.

**The warranty.** The fleet knew warranty as `vehicle_components.warranty_until` — install date plus
the catalog's months, derived, invisible, unmanageable. That is not a warranty. A warranty has a
counterparty, a window that can end two different ways, and a claim you either made in time or lost.

The decisive gap was the **distance leg**. Cover is "12 months **or** 20,000 km, whichever comes
first", and only months were representable — so the system silently granted cover it was never
given. In a rental fleet distance is usually the binding leg: a car doing 6,000 km a month burns a
20,000 km warranty in ten weeks while its 12-month leg still looks perfectly healthy.

---

## 2. ERD

```
                       ┌─────────────────────────┐
                       │   component_catalog     │  132 rows · the parts vocabulary
                       │─────────────────────────│
                       │ slug            UNIQUE  │  stable machine key, never renamed
                       │ name, name_ar           │  EN + AR display
                       │ aliases         JSON    │  search synonyms + symptom wording
                       │ category_key            │  = maintenance_findings categories
                       │ tracking_mode           │  serialized | batch | consumable
                       │ default_warranty_months │  ┐ defaults copied at install,
                       │ default_warranty_km     │  ┘ never a live link
                       │ expected_life_km/months │
                       │ is_active               │  retire, never delete
                       │ edited_in_app           │  ← seeder skips the row once true
                       └───────────┬─────────────┘
                    RESTRICT ┌─────┴──────┬──────────────┐ RESTRICT
                             │            │              │
        ┌────────────────────▼───┐  ┌─────▼───────────┐  │
        │   vehicle_components   │  │   warranties    │  │
        │  (asset layer, exists) │  │                 │  │
        └────────────────────┬───┘  │ kind: part|repair│ │
                  SET NULL   └─────►│ vehicle_id  CASC │ │
                                    │ ─ anchors ─      │ │
   part_purchases ──── SET NULL ───►│ part_purchase_id │ │
   maintenance_tasks ─ SET NULL ───►│ maintenance_task │ │
   vendors ─────────── SET NULL ───►│ provider_vendor  │ │
                                    │ ─ window ─       │ │
                                    │ starts_on        │ │
                                    │ start_odometer   │ │
                                    │ duration_months  │ │
                                    │ duration_km      │ │
                                    │ expires_on     D │ │  D = derived on save
                                    │ expires_at_km  D │ │
                                    │ status/void      │ │
                                    │ softDeletes      │ │
                                    └────────┬─────────┘ │
                                     CASCADE │           │
                                    ┌────────▼─────────┐ │
                                    │ warranty_claims  │ │
                                    │ claimed_on       │ │
                                    │ claim_odometer   │ │
                                    │ was_in_window  F │ │  F = frozen at claim time
                                    │ window_evidence F│ │
                                    │ outcome          │ │
                                    │ outcome_reason   │ │
                                    │ recovered_amount │ │
                                    │ softDeletes      │ │
                                    └──────────────────┘ │
                                                         │
                    ┌────────────────────────────────────┘
                    │
        ┌───────────▼──────────────────┐
        │ maintenance_required_parts   │
        │ part_name          (verbatim)│  the inspector's own words — evidence
        │ component_catalog_id  ← NEW  │  the structured answer beside them
        │ catalog_matched_by    ← NEW  │  exact | core | phrase | manual | null
        └──────────────────────────────┘
```

### Delete rules, and why each was chosen

| Edge | Rule | Reason |
|---|---|---|
| `warranties.vehicle_id` | CASCADE | the car owns its warranties |
| `warranty_claims.warranty_id` | CASCADE | a claim cannot outlive its promise |
| `warranties.part_purchase_id` / `vehicle_component_id` / `maintenance_task_id` / `maintenance_id` | SET NULL | the warranty is **evidence in a dispute** and must survive losing its anchor |
| `warranties.provider_vendor_id`, all `*_by` | SET NULL | a removed vendor or user must never blank history |
| `*.component_catalog_id` (3 tables) | **RESTRICT** | makes the app's 422 possible; without it a delete orphans what cars are made of |

Pinned by `SchemaAndSeederTest::test_foreign_key_delete_rules_are_what_was_designed` — changing one
fails the build.

### Normalisation notes

Deliberately denormalised, each for a stated reason:

- `warranties.subject`, `provider_name` — frozen text so a promise still reads as a sentence after
  its vendor row is gone.
- `maintenance_required_parts.part_name` kept beside `component_catalog_id` — the inspector's
  wording is evidence, not a lookup key.
- `expires_on` / `expires_at_km` — pure functions of facts on the same row, stored because the
  expiring-soon board range-scans them.

Everything else is 3NF.

---

## 3. Warranty lifecycle

### Recording

```
Coordinator                WarrantyController         WarrantyService            DB
    │                             │                          │                   │
    │ POST /api/warranties        │                          │                   │
    ├────────────────────────────►│ StoreWarrantyRequest     │                   │
    │                             │  · shape only            │                   │
    │                             │  · km cover ⇒ needs      │                   │
    │                             │    start_odometer        │                   │
    │                             ├─────────────────────────►│ create()          │
    │                             │                          │ resolveAnchors()  │
    │                             │                          │  vehicle DERIVED  │
    │                             │                          │  from the anchor  │
    │                             │                          │ assertAnchor…()   │
    │                             │                          │  part ⇒ purchase  │
    │                             │                          │       or component│
    │                             │                          │  repair ⇒ FAULT   │
    │                             │                          ├──────────────────►│
    │                             │                          │ saving(): derive  │
    │                             │                          │  expires_on,      │
    │                             │                          │  expires_at_km    │
    │◄────────────────────────────┴──────────────────────────┴───────────────────┤
    │  201 + verdict computed at read time                                        │
```

### Reading — the verdict is never stored

```
GET /api/warranties
   │
   ├─ narrow in SQL:  status = active AND (expires_on IS NULL OR expires_on >= today)
   │                  ⚠ DATE LEG ONLY — the km leg needs each car's odometer
   │
   └─ per row: Warranty::evaluate(on = today, odometer = vehicle.odometer)
         ├─ status = void ────────────────────────► VOID
         ├─ today > expires_on ───────────────────► EXPIRED, ended_by = time
         ├─ odometer > expires_at_km ─────────────► EXPIRED, ended_by = distance
         ├─ km cover but odometer unknown ────────► distance_unknown = true
         └─ otherwise ────────────────────────────► ACTIVE
                  evidence: "8 of 12 months, 14,200 of 20,000 km"
```

`is_expired` is **not a column** on purpose: it depends on an odometer that keeps moving, so a
stored flag would be correct only until the next rental. The API therefore ships `verdict` beside
`expires_on`; a client rendering the date alone overstates cover on exactly the cars driven hardest.

### Claiming — the verdict freezes

```
Ops                 WarrantyService                      warranty_claims
 │ POST …/claims          │                                    │
 ├───────────────────────►│ odometer = given ?: vehicle.current│
 │                        │ verdict = evaluate(claimed_on, odo)│
 │                        ├───────────────────────────────────►│ was_in_window   ← FROZEN
 │                        │                                    │ window_evidence ← FROZEN
 │◄───────────────────────┴────────────────────────────────────┤
 │
 │  …car does another 40,000 km; the warranty is now expired…
 │  …the claim still reads "5,000 of 20,000 km". That is the point.
 │
 │ POST /api/warranty-claims/{id}/resolve
 ├──► accepted ──► recovered_amount + remedy kept
 └──► rejected ──► outcome_reason REQUIRED, money forced to null, remedy = none
```

Filing outside the window is **allowed** — the fleet argues borderline cases, and a claim lost on
distance is exactly the evidence needed when the next supply contract is written.

---

## 4. API contracts

Envelope for every endpoint: `{ data, success, message }`. Validation failures use Laravel's native
`{ message, errors }` so forms can highlight fields.

### Parts Catalog — `/api/parts-catalog`

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/` | `parts.view \| components.view \| maintenance.view` | whole list + legend; `?q=` searches EN/AR/aliases/slug/part-number |
| POST | `/` | `components.manage` | 201 |
| POST | `/{part}` | `components.manage` | update; **slug is not editable** |
| POST | `/{part}/retire` | `components.manage` | hide from pickers; idempotent |
| POST | `/{part}/restore` | `components.manage` | un-retire |
| DELETE | `/{part}` | `components.manage` | **422 if referenced** |

<details><summary>GET / — response shape</summary>

```jsonc
{ "data": {
  "parts": [{
    "id": 5, "slug": "alternator", "name": "Alternator", "name_ar": "دينمو",
    "aliases": ["dynamo","generator","battery not charging","ما يشحن"],
    "category_key": "electrical", "tracking_mode": "serialized",
    "default_warranty_months": 12, "default_warranty_km": 20000,
    "expected_life_km": null, "position_scheme": null, "positions": [],
    "is_active": true,
    "usage_count": 51,
    "references": { "fitted_components": 51, "warranties": 0, "required_parts": 0 },
    "can_delete": false,
    "edited_in_app": false, "edited_at": null, "edited_by_name": null
  }],
  "categories": [{ "key": "engine", "label": "Engine", "label_ar": "المحرك" }],
  "tracking_modes": [{ "key": "batch", "label": "Batch", "label_ar": "بالكمية", "hint": "…" }],
  "position_schemes": [{ "key": "axle_corner", "label": "Corner (FL / FR / RL / RR)" }],
  "counts": { "total": 132, "active": 132, "retired": 0, "edited": 0, "missing_ar": 0 }
}}
```
</details>

**DELETE refusal (422)** — the contract that replaced a SQL error:

```jsonc
{ "success": false,
  "message": "Cannot delete \"AC Compressor\" — it is still referenced by 51 fitted components and 2 warranties. Retire it instead: it disappears from the pickers and the history keeps its meaning.",
  "data": { "references": { "fitted_components": 51, "warranties": 2 },
            "can_retire": true, "retire_url": "/api/parts-catalog/12/retire" } }
```

**Validation:** `name` required ≤120 · `category_key` ∈ findings categories · `tracking_mode` ∈
serialized|batch|consumable · `aliases` ≤40 entries, each nullable string ≤120 (trimmed,
case-insensitively de-duplicated) · consumable + `position_scheme` ⇒ 422 · changing `tracking_mode`
while components exist ⇒ 422.

### Warranties — `/api/warranties`

| Method | Path | Permission |
|---|---|---|
| GET | `/` | `parts.view \| maintenance.view` |
| GET | `/{warranty}` | `parts.view \| maintenance.view` |
| GET | `/vehicle/{vehicle}` | `parts.view \| maintenance.view` |
| POST | `/` | `parts.purchase \| maintenance.manage` |
| POST | `/{warranty}` | `parts.purchase \| maintenance.manage` |
| POST | `/{warranty}/void` | `parts.investigate \| maintenance.manage` |
| POST | `/{warranty}/reinstate` | `parts.investigate \| maintenance.manage` |
| DELETE | `/{warranty}` | `parts.investigate \| maintenance.manage` |
| GET/POST | `/{warranty}/claims` | view / `parts.purchase` |
| POST | `/api/warranty-claims/{claim}/resolve` | `parts.investigate \| maintenance.manage` |

Permission logic: reading rides with `parts.view` (the people chasing a warranty bought the part);
recording and claiming is `parts.purchase`, the same bar as spending the money; voiding and
adjudicating is `parts.investigate`, because those two decide whether money is recoverable.

Query params on GET `/`: `kind` `status` `vehicle_id` `provider_id` `expiring_days` `per_page`.
The response carries `filters_note` stating that `expiring_days` matches the **date leg only**.

<details><summary>Warranty response shape</summary>

```jsonc
{ "id": 1, "kind": "part",
  "vehicle_id": 42, "vehicle": { "id": 42, "plate_no": "A-12345", "odometer": 125000 },
  "subject": "AC Compressor", "component_catalog_id": 4,
  "part_purchase_id": 88, "vehicle_component_id": null,
  "maintenance_task_id": null, "maintenance_id": null,
  "provider_vendor_id": 7, "provider_name": "Gulf Parts", "reference_no": "INV-9931",
  "starts_on": "2026-05-01", "start_odometer": 100000,
  "duration_months": 12, "duration_km": 20000,
  "expires_on": "2027-05-01", "expires_at_km": 120000,
  "status": "active", "void_reason": null,
  "verdict": { "state": "expired", "ended_by": "distance", "reason": null,
               "months_used": 3, "km_used": 25000, "distance_unknown": false,
               "evidence": "3 of 12 months, 25,000 of 20,000 km" },
  "claims_count": 1 }
```
</details>

**Store validation:** `kind` required · `starts_on` required · `duration_km` > 0 requires
`start_odometer` (else "20,000 km from when?" has no answer) · neither duration ⇒ 422 unless
`unlimited_confirmed=true` · anchors checked against `kind` in the service.

**Claim:** `failure_description` required; `was_in_window` / `window_evidence` are **not accepted
from the client** — the one field that must be trustworthy cannot be the field anyone can type.

---

## 5. Service dependencies

```
  HTTP ─► PartsCatalogController ─────► ComponentCatalog ◄── ComponentCatalogSeeder
                                            ▲   ▲                 (config = seed only,
                                            │   │                  skips edited_in_app)
       ─► WarrantyController ─► WarrantyService                        
                                    │  ├─► Warranty ─► WarrantyClaim
                                    │  ├─► PartPurchase        (read: defaults, anchor)
                                    │  ├─► VehicleComponent    (read: anchor)
                                    │  ├─► MaintenanceTask     (read: the fault)
                                    │  └─► Vehicle             (read: odometer — the km leg)
                                    │
       ─► MaintenanceRequiredPartService ─► PartCatalogMatcher ─► ComponentCatalog
                    │                          (exact-only, no aliases)
                    └─► PartWorkflowService ─► PartIntelligenceService (repeat detection)
```

**Dependency direction is one-way.** The catalog knows nothing about warranties, required parts or
purchases; they depend on it. `WarrantyService` reads `component_catalog` for defaults but copies
them — editing the catalog never rewrites a promise already made.

**Evidence classes** (per the Evidence Layer rule): `WarrantyService` is **F** (fact capture) with
one **D** output — the frozen claim verdict. `PartCatalogMatcher` is **D** (derived). Neither emits
judgements; a warranty is a contract, not an opinion.

### Two identity rules — a known inconsistency

| Path | Keys on | Effect |
|---|---|---|
| `PartIntelligenceService::detectDuplicate` (buy-time modal) | **SKU** when present, else name | a fresh hand-typed SKU hides the repeat |
| `PartIntelligenceService::repeatPurchases` (fleet sweep) | **name** | catches it later |

3,550 of 3,552 purchases carry a distinct hand-typed `part_number`, which is why the sweep is
name-keyed. The buy-time check is not, so a buyer is **not warned at the till**. Pinned by
`RepeatPartDetectionTest::test_buy_time_identity_is_sku_first_and_misses_a_renamed_sku`. Left as-is
deliberately: widening it would fire on every legitimate re-buy and needs its own windowing
decision.

---

## 6. Deployment & migration notes

### Migrations added

| File | Effect |
|---|---|
| `2026_08_04_100000_make_component_catalog_editable` | `name_ar`, `aliases`, `default_warranty_km`, `edited_in_app` + audit |
| `2026_08_04_100100_create_warranties_table` | `warranties`, `warranty_claims` |
| `2026_08_04_140000_link_required_parts_to_component_catalog` | FK + `catalog_matched_by`, **backfills** |

The backfill uses `PartCatalogMatcher` and is exact-only. On the live data it linked **4 of 6** rows;
`break` and `Front brake disc` stayed NULL because no rule could identify them without guessing.

### Deploying

```bash
php artisan migrate --force
php artisan db:seed --class=ComponentCatalogSeeder --force   # idempotent, safe every deploy
composer verify                                             # includes schema:health --drift
```

- **Seeding is safe to repeat.** Rows with `edited_in_app` are skipped entirely; retirements
  (`is_active=false`) survive; parts new to the config are still inserted.
- **Verified from empty**: 248 migrations → 124 tables, column-identical to live.
- ⚠ **Never `migrate:fresh` on this machine.** Dropped InnoDB tablespaces are not released before
  the re-CREATE, so it fails with "table already exists" for tables that do not, and poisons the
  schema. Reproduced in a brand-new database — environment fault, not code. This is why the
  Foundation suite runs in transactions against a pre-migrated schema.

### Rollback

All three migrations have working `down()`. Dropping `warranties` loses claim history — it is
evidence, so prefer forward fixes.

### Feature flag

`PARTS_REQUIRE_CATALOG_LINK` (default **false**). When the required-parts picker ships, flip it to
true so a line naming no known part is refused at the door. **Check `parts:link-required` reports
zero unlinked pending lines first**, or in-flight inspections start failing to save.

---

## 7. Known pre-existing technical debt — NOT part of this feature

Recorded, not fixed, deliberately. None was introduced by this work and none is being touched.

| # | Item | Impact | Owner | Follow-up |
|---|---|---|---|---|
| 1 | **Decision provenance** — 3 decisions carry no engine version (28.6% stamped) | decisions made before a change cannot be explained afterwards | intelligence/dispatch | the assign-dispatch payload drops `provenance`; fix at the emit site |
| 2 | **Forecast coverage** — 2 of 7 decisions carry a forecast | a decision without a forecast can never be scored for accuracy | intelligence | emit a forecast with every decision |
| 3 | **Calibration** — 0 of 2 forecasts scored | accuracy is unmeasured, so confidence is unearned | intelligence | `intelligence:forecast-calibration --score` |
| 4 | **Cost-source freshness** — 33 expense lines in 90 days vs 823 in the prior 90 | cost figures are increasingly historical; anything reading them understates recent spend | data pipeline | check whether the expense import is partly failing or the sheet is filled in less |
| 5 | **Invoice precision drift** — `invoices.discount` 12,2 live vs 14,2 clean | no data impact; this machine cannot be rebuilt from the repo | — | see `docs/Invoice-Precision-Drift.md`; allowlisted in `config/schema.php` |
| 6 | **Orphan migration record** — `2026_06_21_150000_add_discount_to_invoices` recorded, file never committed | `schema:health` reports "249 of 248 applied" | — | leave the row; it is the only evidence of what happened |
| 7 | **`migrate:fresh` broken locally** | the whole `tests/Crud` suite cannot start | infra | repair MySQL (stop server, clear orphaned tablespaces) or keep using transaction-based suites |
| 8 | **Buy-time vs sweep identity** (§5) | a repeat with a fresh SKU is not flagged at purchase | parts | needs a windowing decision before widening |

Items 1–4 are the four `schema:health` WARNs; they do not fail the build and are out of scope by
instruction.

---

## 8. Test coverage

`php vendor/bin/phpunit -c phpunit.foundation.xml` — **77 tests, 209 assertions**.

| Suite | Tests | Covers |
|---|---|---|
| `SchemaAndSeederTest` | 10 | columns, FK delete rules, indexes, slug uniqueness, idempotent seeding, the edit guard, retirement surviving a re-seed |
| `PartsCatalogCrudTest` | 13 | create/update/delete/retire/restore, edit stamping, alias normalisation, slug uniqueness, consumable+position, tracking-mode re-classification, **422 naming references**, search |
| `PartCatalogMatcherTest` | 12 | exact EN/AR, parenthetical core, phrase containment, slash abbreviations, **symptom aliases never link**, ties, retired parts |
| `WarrantyLifecycleTest` | 24 | both legs derived, whichever-first, distance vs time expiry, unknown odometer, void rules, anchor validation per kind, immutable anchors, **frozen claim verdicts**, rejection rules, API surface |
| `RepeatPartDetectionTest` | 10 | same part/car/window, different car, different part, consumables, self-exclusion, **the SKU-vs-name gap** |
| `SchemaDriftComparatorTest` | 8 | type/precision/nullable/default/collation/FK-rule/index-order drift, allowlist format |
```
