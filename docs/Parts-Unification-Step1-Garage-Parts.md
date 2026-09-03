# Parts Unification — Step 1: garage-supplied parts enter the part lifecycle

**Shipped:** 2026-09-01 · **Code landed in:** `98e7b01` (a commit titled for the Odoo bridge — the
parts work was swept into it by a concurrent session, which is why this note exists: the commit
message does not mention it, so `git log` alone will not explain why any of the code below is here.)

## The defect

A part a GARAGE supplied and billed for lived in exactly one place: a `maintenance_line_items` row
(`kind=part`) holding its price. It never became a `PartPurchase`, never reached a vehicle's
component list, never carried a warranty, never appeared in that part's history or price statistics.

A supplier-bought battery and a garage-fitted battery were the same physical object recorded in two
universes that could not see each other. Measured at the time: of 87 part lines, **0** pointed at a
purchase and **1** had ever produced a component — and that one arrived backwards, from a purchase
that happened to carry a line-item id.

## The shape now

```
Ticket → Garage (vendor) → maintenance_line_items(kind=part) → ComponentCatalog
       → PartPurchase(purchase_source='garage') → VehicleComponent → warranty → history
```

Garage-supplied and supplier-bought parts are the SAME canonical part. No separate "garage part"
concept was introduced: `purchase_source='garage'` has existed since `part_purchases` was created,
and `CostSourceResolver` already knew a garage-sourced purchase's document is the garage's bill.
Only the write was missing.

## The money rule — do not break this

`PartSpendService` sums **line items + purchases with NO `maintenance_line_item_id`**. Every purchase
this feature writes carries one, so it is excluded from the spend total *by the existing design*.
The line item remains the canonical dirham. Nothing in `PartSpendService` changed and no figure on
any page moved.

**If a future change drops that link, fleet parts spend silently doubles.** It is covered by
`test_connecting_the_ledger_does_not_double_count_a_single_dirham`.

## What was added

| Thing | Role |
|---|---|
| `GarageLineItemLedgerService` | The forward door. `classify()` (read-only), `syncLine()`, `syncInvoice()` (reconcile) |
| `ComponentService::installFromGarageLine()` | Third component door, beside `installFromPurchase` / `installFromAction`. All component writes still funnel through the one class |
| `MaintenanceInvoiceService::replaceLineItems()` hook | The live path. Wrapped so a ledger failure never costs the invoice |
| `parts:backfill-garage-lines [--apply]` | History. Dry-run by default |
| `part_purchases.maintenance_invoice_id` + `unique(maintenance_line_item_id)` | See below |
| `tests/Feature/GarageSuppliedPartLifecycleTest.php` | 15 e2e tests (`phpunit.e2e.xml`) |

### Why `maintenance_invoice_id` exists

`MaintenanceInvoiceService::replaceLineItems()` **deletes and recreates** every work line on each
save. Line ids are therefore not stable across an edit, and a create-on-write hook would raise a
fresh duplicate purchase every time a bill was corrected. `syncInvoice()` reconciles by **part
identity + ordinal within that identity**, not by line id, and re-points the purchase at the new
line. The unique index is the database-level idempotency backstop under the service's own check.

## Deliberate refusals — never "fixed" by guessing

| Reason | Why |
|---|---|
| `no_canonical_part` | Wording that never resolved to a catalog entry. Gets **no purchase at all** — a purchase with a null identity is invisible to every price and repeat-buy check that reads the table |
| `consumable_never_a_component` | Standing rule: oil and filters are work, not assets. The purchase is still written |
| `deferred_missing_position` | A bill says "Front brake pads (set)", never which corner. Placing it in the null slot would invent a placement AND occupy the slot, so the real fitting could never be recorded |
| `deferred_slot_occupied` | Replacing a known part means closing out its predecessor, and a close-out needs a reason AND a disposition. A bill does not say what happened to the old part |

Deferrals are **derived, not stored** — recomputed by `--dry-run`, with a plain-language sentence
written into `part_purchases.notes` so the reason is visible where the row is read. A stored status
would go stale the moment somebody fits the part or names the position.

`installed_at` is the line's own `installed_on` or NULL — **never** the day the backfill ran. A null
install date means the component's age reads Unknown, which is true; `warranty_until` then derives to
null on its own, so no warranty is manufactured either.

Acquisition is `garage_supplied`, which is **not** in `ACQ_WARRANTABLE` — so the catalog's default
warranty months are deliberately withheld (that warranty is a conversation with the garage, not an
entitlement on our ledger). A warranty the bill *states* is still honoured.

## Backfill result (live `laravel`, 2026-09-01)

87 part lines → **73 purchases created, 33 components created.**

| Outcome | Lines |
|---|---:|
| Purchase + component | 33 |
| Purchase, component deferred | 41 (29 slot occupied, 12 missing position) |
| Not migratable — needs review | 13 (`no_canonical_part`) |

Re-running reports `0 created, 0 components` — idempotent. Backup taken first:
`storage/app/backups/laravel-20260901-063346.sql`.

> The first dry run projected 60 components; the apply produced 33. `classify()` queries the
> database, which cannot see components that earlier lines *in the same run* are about to create, so
> repeat fittings of one part type on one car read as separate components. The command now simulates
> slot fill during a dry run so `--dry-run` and `--apply` agree.

## Known limitations

1. **74 of 87 lines produced no component.** The honest ceiling of the existing data, not a bug. The
   12 position deferrals become writable when a bill line can carry a position; the 29 slot conflicts
   need a human to say what happened to the outgoing part.
2. **No historical line is attached to a garage invoice** — all 87 are ticket-level lines with
   `maintenance_invoice_id = NULL` (1 `maintenance_invoices` row fleet-wide at the time). For history
   the chain runs *Ticket → Garage → Line → Part*. New invoice-attached work carries the full chain.
3. **`purchased_at` / `installed_at` are NULL on ~80 rows.** `PartPurchase::isInstalled()` reads
   false for a part that *is* fitted; the schema cannot say "fitted, date unknown".
4. Runs under `ASSET_LAYER_MODE=shadow`, so these components are written `provisional`.

## Not done

Step 2 — unifying `part_requests` with `store_stock_requests` into one request lifecycle with vehicle
and maintenance optional — plus warehouses and stock reservations. See the assessment in the Step 1
discussion: the catalog, identity normalisation, purchase, receipt, movement audit, component,
warranty, spare-key and price-intelligence layers already exist and should be reused, not rebuilt.
