# The Storehouse — parts the fleet owns before a car needs them

## Why it exists

Until now a part could only reach a car by being bought **for that car, on that day, against that
ticket**. Real workshops do not work that way: filters, bulbs, pads and belts are bought in tens when
they are cheap and fitted months later to whichever car needs one.

There was nowhere to put those ten filters. The only way to fit one was to record a fresh purchase,
which invented a supplier, invented a price, and made the original buy look as though it had
evaporated.

The storehouse is that place. It closes the loop at both ends: stock goes **up** when a part arrives,
and **down** when a job takes one — and the unit that leaves becomes an ordinary part purchase, so
nothing about how a fitted part is costed changes.

## The three tables

| Table | What one row is |
|---|---|
| `store_items` | A shelf: one part TYPE we stock, and how many we hold now. |
| `store_movements` | One unit-movement in or out. Append-only. The evidence behind every level. |
| `store_stock_requests` | "We need this on the shelf" — procurement with **no car attached**. |

`store_items.qty_on_hand` is a **cache** of the movement ledger, never an independent truth. Every
change to it is written by `StoreService::move()` inside a transaction that has the item row locked
and writes the matching movement in the same breath.

```
php artisan store:verify        # replays every ledger and proves the levels
php artisan store:verify --fix  # rewrites a drifted level FROM the ledger (never the other way)
```

## Identity — one shelf per part

`store_items.stock_key` is derived, never typed:

* `cat:{component_catalog_id}` when the part is in the catalog — the strongest identity anyone can
  assert, because a human picked it;
* `name:{part_name_key}` otherwise, from the normalised wording.

The two rungs mirror `PartIdentityService` exactly, and the column is UNIQUE. A catalogued part
therefore cannot end up with two shelves under two spellings — which is the failure that makes every
inventory system eventually useless.

## Why a stock request is not a `PartRequest`

A `PartRequest` is the intent to fit a part **to a vehicle**: `vehicle_id` is required on it, its
duplicate engine asks "has THIS CAR had this part before", and its install step bills a ticket. None
of that is true of stocking a shelf — there is no car, there is no fault, and there is nothing to
bill until the part is later issued to a job.

Forcing a stock buy through `part_requests` would have meant inventing a vehicle for it, and every
duplicate warning and cost roll-up downstream would then be describing a car that was never involved.

## Why an issue is a purchase

It would have been less code to decrement the shelf and write the `VehicleComponent` directly. That
would also have created a **second way for a part to land on a car** — one that skips the duplicate
engine, the install guards, the fault linkage and the cost bridge.

There is ONE write path onto a vehicle (`PartWorkflowService`). The storehouse is a **source** for
that path, never a bypass of it. `StoreService::issueToRequest()`:

1. locks the shelf and refuses to issue more than it holds;
2. approves the request if it is still open (the spend was authorised when the part was bought INTO
   the store — asking twice would approve the same money twice), recording who released it;
3. creates a `PartPurchase` with `purchase_source = store`, priced at the shelf's **weighted average
   cost** — what the fleet actually paid;
4. writes the `issue` movement linking shelf → vehicle → ticket → request → purchase;
5. stamps the purchase **delivered** — the part is in our own building, so the ticket must never sit
   in "waiting for parts" for it.

From there the part travels the ordinary install path: `maintenance_line_items` (cost) and
`vehicle_components` (the asset), so it appears on `/vehicles/{id}?tab=components` like any other
fitted part, flagged `from_store` so the empty Supplier column reads as "the storehouse" rather than
as a missing record.

`PartWorkflowService::purchase()` **refuses** `purchase_source = store` unless the caller is
StoreService (`_from_store`), and `PartRequestController` validates against
`DIRECT_PURCHASE_SOURCES`. A store purchase with no stock movement behind it would leave the shelf
permanently over-counted with nothing to point at.

## The paper rule

Putting a part on the shelf is a **money event**: someone bought it, from someone, for a price, on a
document. Recording the price and forgetting the document leaves a shelf cost nobody can check, so a
`receipt` **cannot be booked without the supplier's invoice** — `StoreService::resolveInvoice()`
aborts, and the shelf change goes with it.

The document is a `PartInvoice` — the SAME supplier bill that backs a part bought for a car, not a
second kind of paper. One supplier trip commonly buys some parts for a car in the workshop and some
for the shelf, on one invoice, so the receipt can either **raise a new bill** (number, date,
supplier, VAT, printed total, photo) or **attach to one already keyed**. `store_movements.part_invoice_id`
is the link, and `PartInvoice::recalcTotals()` sums both halves — its purchases and its store
receipts — so the invoice's own total matches its printed one on a mixed trip.

The existing **variance gate** then applies unchanged: if the printed total disagrees with the lines
by more than a cent, an explanation is mandatory. The form does the same arithmetic live, so the
mistake is caught while the paper is still in the person's hand.

Two in-movements have **no** invoice, by design, and both say which they are on screen rather than
showing a blank:

* the **opening count** — stock already on the shelf the day the storehouse started. Its document, if
  one ever existed, is not ours to invent, and demanding one would force somebody to make a number up.
* a part **returned unused** — already paid for on the receipt that first brought it in.

A `receipt` with no invoice can therefore only be one booked before this rule existed; the ledger
flags it **No bill on file** rather than dashing it, because it is a gap someone can still close.

Deleting an invoice releases its receipts (`part_invoice_id → null`) exactly as it releases its
purchases. The stock stays; only the paper goes. Deleting a document must never delete parts that are
physically on a shelf.

## Money

* A receipt with a price **re-weights** the shelf average: `(old_qty × old_avg + new_qty × price) / total`.
* An issue is priced **from** the average and never feeds back into it.
* A count correction carries no price and does not touch the average — five filters found in a corner
  are five filters we already paid for.
* A shelf with no priced receipt behind it issues at **0** and says so, in the purchase note and on
  screen. It never invents a number, and the "Stock value" tile excludes it rather than counting it
  as free.

## The two doors, at the moment they matter

Both the ticket's *Request a Part* modal and the Parts board's *Record purchase* modal ask
`GET /store/availability` on the part's identity and show what the shelf holds **before** the buy is
decided. The offer to take it from stock is:

* never the default — a shelf answer that pre-selected itself would take a part out of stock because
  a form defaulted that way, not because anyone decided to;
* withdrawn when the quantity exceeds what the shelf can cover, rather than offered and then failed;
* hidden (but the stock level still shown) from users who may request parts but not move stock.

## API

| Verb | Route | Permission |
|---|---|---|
| GET | `/store/availability` | `parts.view\|parts.request\|maintenance.view` |
| GET | `/store/items`, `/store/items/{item}`, `/store/movements` | `parts.view` |
| POST | `/store/items/receive`, `/store/items/{item}`, `/store/items/{item}/adjust` | `parts.purchase` |
| GET/POST | `/store/stock-requests` | `parts.view` / `parts.request` |
| POST | `/store/stock-requests/{r}/approve\|reject` | `parts.investigate\|maintenance.manage` |
| POST | `/store/stock-requests/{r}/ordered\|receive` | `parts.purchase` |
| POST | `/store/issue/{partRequest}` | `parts.purchase\|maintenance.logistics` |
| POST | `/store/return/{partPurchase}` | `parts.purchase` |

Permissions ride the existing parts vocabulary rather than inventing a store one: looking is
`parts.view`, asking is `parts.request`, anything that moves or prices a unit is `parts.purchase`
(the same bar as spending money), adjudicating is `parts.investigate`.

## UI

`/parts?tab=store` — three views: **On the shelf**, **Requests**, **Movements**. Nothing on that page
can hand a part to a car; that happens from the ticket, where the fault and the odometer are.
