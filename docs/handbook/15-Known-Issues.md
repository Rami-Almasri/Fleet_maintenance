# Known Issues & Hazards

**Read this before running anything that writes.**

Every item below is a **real, previously observed failure** in this project — not a theoretical risk. They are grouped by what they threaten.

---

## Tier 1 — Things that will destroy data or mislead you badly

### A "dry run" can commit real records

A rehearsal of a workflow sweep against the live `laravel` database **committed 56 real withdrawals**. The rollback did not hold, because the workflow services commit inside their own transactions.

**Do:** rehearse against a private schema. Take `php artisan db:backup` first. Never trust a `--dry-run` flag whose implementation you have not read.

### The CRUD test suite empties `laravel_test`

`phpunit.crud.xml` runs `migrate:fresh`. If anything you care about is in `laravel_test`, it will be gone. The suite is **already failing at HEAD** (roughly 80 errors of 389), so a red run does not necessarily mean you broke something.

### Never run `artisan migrate` against `fleet_test`

Its migration ledger is broken: **133 tables but only 1 row in `migrations`**. Laravel therefore believes almost nothing has run and will try to create tables that already exist. **Add columns to `fleet_test` by hand.**

### A fresh database cannot be built from migrations

A deploy from an empty schema **would fail**. The 287-migration set does not run clean from zero. Documented in `docs/issues/migrations-cannot-build-fresh-database.md`.

**Do:** rebuild any database by **cloning** an existing one.

### Two sessions sharing one git index

Historically, two concurrent working sessions shared a single git index, and another session's `git stash -u` swallowed uncommitted work. Recovery tags exist (`oil-split-backup`, `oil-revert-tree`) and the underlying fix was never completed.

**Do:** run `git stash list` before assuming your changes vanished, and avoid concurrent sessions on the same checkout.

---

## Tier 2 — Silent wrongness (the dangerous kind)

### The expense ledger is effectively dead

`vehicle_expenses` collapsed by roughly **99% after March 2026**. Every cost figure derived from it is **historical**, not current — but nothing on screen says so unless the surface checks `freshness()`.

This is the archetypal failure mode of this system: *a frozen source keeps rendering precise-looking numbers about a fleet that stopped existing months ago.*

### Sheet imports are not reliably scheduled in production

Some importers have no active scheduled run on the server. **Stale data looks fresh.** Check both that the scheduler is running and the source's own last-import timestamp.

### Schema drift checks ignore column types

`schema:health` can report "matches exactly" while a column is `decimal(12,2)` on one side and `decimal(14,2)` on the other. It has also reported "249 of 248", which means an orphan row, not a rounding artefact.

**Do:** for money columns, compare types by hand. See `docs/Invoice-Precision-Drift.md`.

### Raw SQL bypasses soft deletes

Several models use `SoftDeletes`, including `Maintenance`. A raw query against `maintenances` sees deleted rows.

**Do:** every raw query must declare whether it wants **LIVE** or **HISTORICAL** rows.

### The odometer has ~7 writers and no owner

Highest reading wins; `advanceOdometer` stamps the source. Continuity tolerance is ±5 km. A **forward** jump never blocks; a **backward** one does.

**Do:** read `docs/data-pipeline-roadmap.md` before refactoring any odometer writer.

### Duplicate part detection only sees purchases

Open part requests and required-part lines are invisible to it, so **storing the same part twice can pass silently**. The comparison (`lineKey()`) is a raw string compare, so trivial spelling differences defeat it.

### Client-side filtering over a paginated feed lies

The Action Center's lanes once filtered only the *currently loaded page*, so a busy feed made lanes report "empty" when they were not. **Filter server-side.**

### A withdrawn-request card exists only while the car is in the shop

If the car has come back, the correct treatment is a recount, not a card. Do not infer state from the card's absence.

---

## Tier 3 — Environment and platform traps

### MariaDB locally, MySQL 8 in production

Migrations that pass locally can fail on the server. Known trap: **CHECK constraints on foreign keys**.

### MariaDB silently adds `ON UPDATE CURRENT_TIMESTAMP`

Columns declared `timestamp()` acquire auto-update behaviour, so a stored moment in time changes itself on unrelated updates.

**Do:** use `dateTime()` for any stored moment. Note `odoo_synced_at` is declared `timestamp()`.

### Port 8000 is contested

Other projects on the original development machine also serve on 8000. **"Route api/X not found" for a route you can see in `api.php` usually means you are talking to a different application.** Check the port before debugging routes.

### MySQL privilege-table corruption

XAMPP has died after logging "Server socket created", caused by a crashed Aria `mysql.db` privilege table. Repaired with `aria_chk`.

### Production `umask` breaks file permissions

Requires `chown www-data` after deploy. **This fix has not been committed** — verify whether it is still needed.

### OM batch timeouts in a web request hang the dev server

`OfficeManagerClient` has a patient batch profile (long timeout, 30s-spaced retries). Used inside a live web request it will exceed PHP's max execution time on the single-threaded `artisan serve`. **Pass `interactive: true` for user-facing calls.**

---

## Tier 4 — Features that are not what the documents claim

### The intelligence layer is partly ungrounded

Internal audits found: **zero real documents** ingested into the knowledge platform, roughly **96% of ontology edges seeded** rather than learned, and **hardcoded confidence values**. A predictive-maintenance backtest measured **1.08× lift** — which does not earn the confidence the UI was prepared to display.

**Do:** verify against the service and its backtest before trusting or extending any intelligence output.

### Retired features whose services still run

Several intelligence pages were deleted while their services kept running (the Maintenance Foresight page is the clearest example — the page is gone, the service is not). **A deleted page does not prove a stopped service, and an existing route does not prove a live feature.**

### Reversed decisions still described as current in older documents

- **The Rental-First hard block was removed.** Older documents describe a hard block preventing a car with open maintenance from being rented. It no longer exists — today deferrable faults rent, grounding faults do not.
- **Breakdown is the sole maintenance type.** The other five were removed deliberately, and have been **accidentally re-added once** (regressed by commit `83fd822`). Do not re-add them.
- **The recommendation queue was retired** — a report now becomes a ticket immediately.
- **`/service-due` and the Maintenance Ops Center were deleted.**
- **Service Reminders is no longer its own route** — it became a Fleet Health tab in August 2026.
- **An `awaiting_parts` workflow state was removed.** "Are we waiting on a part?" has one owner: the ticket's `part_requests`.

### Designs that were never fully built

- **`docs/Invoice-As-Financial-Source-of-Truth.md` describes a design, not running code.** Compare against `docs/Financial-Source-of-Truth.md` for the as-built picture.
- The **Supervisor video-review gate** (`repair_review`) is parked.
- **Odoo integration is half-built** — export payload exists, nothing pushes, nothing is pulled. See [16-Odoo-Integration.md](16-Odoo-Integration.md).
- **The OM API is a read-only replica** — FleetView cannot push invoices back into it, whatever a design document may imply.

---

## The short version

Before you run anything:

1. `php artisan db:backup`
2. Confirm which database you are pointed at.
3. If it writes, rehearse on a private schema — not on `laravel`.
4. If it reads a number you intend to trust, check the source's freshness.
5. If a document says a feature exists, verify it in the code first.
