# Operations Runbook

Deploying, scheduling, backing up, and debugging FleetView in production.

The authoritative deployment procedure is the root **`DEPLOYMENT.md`** ("Deployment & Sync Operations"). This document summarises it, documents the scheduler, and collects the operational warnings.

---

## 1. Production architecture

Production runs on **Docker Compose** (`docker-compose.yml` at the repo root), with services for:

- `db` — MySQL 8
- `backend` — the Laravel API
- `nginx` — web server / reverse proxy
- `scheduler` — the recurring-job runner

Two environment files, deliberately separate:

| File | Holds |
|---|---|
| root `.env` (from `.env.docker.example`) | Infrastructure — `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `APP_PORT`. **Never commit.** |
| `backend/.env` | Application config. `DB_*` are injected automatically by compose from the root `MYSQL_*` values — do not set them here. |

Production values that matter:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-host
APP_FRONTEND_URL=https://your-host
OFFICEMANAGER_API_KEY=...
OFFICEMANAGER_WEB_SYNC_ENABLED=false
FILESYSTEM_DISK=local          # or s3 + AWS_* for offloaded media
LOG_LEVEL=warning
LOG_STACK=daily
```

`APP_KEY` is generated **once** and then persists in `backend/.env` on the host. Re-cache config after setting it.

On boot, the backend container re-runs migrations and rebuilds the config/route/view caches automatically — so **updating is: pull, rebuild, restart.**

### ⚠️ Known deployment defect

**`umask` on the server breaks file permissions**, requiring `chown www-data` after deploy. As of this writing **that fix has not been committed** — it is applied by hand. Check whether this is still the case before your first deploy.

---

## 2. Pre-deployment gate

`DEPLOYMENT.md` opens with a gate to run before every release. The composer shortcut:

```bash
composer verify
# = config:clear + schema:verify-fresh --seed + schema:health --drift --json + artisan test
```

⚠️ Two caveats:

1. **`schema:verify-fresh` exercises a from-empty build, which is currently broken** — see [15-Known-Issues.md](15-Known-Issues.md).
2. **`schema:health` under-reports.** It can report "matches exactly" while a column is `decimal(12,2)` on one side and `decimal(14,2)` on the other. For money columns, verify types by hand.

---

## 3. Backups

**Take one before anything destructive. This is not boilerplate — see §7.**

```bash
# App-level backup (writes into storage/app/backups)
php artisan db:backup

# Raw MySQL dump to the host
docker compose exec db mysqldump -u root -p laravel > backup.sql
```

Uploaded media (photos, inspection videos) live on the `FILESYSTEM_DISK` and are backed up separately — see `DEPLOYMENT.md`.

---

## 4. The scheduler

Defined in **`backend/routes/console.php`**. In production the `scheduler` container runs it; locally there are `run-scheduler.cmd` / `run-scheduler.vbs` / `run-scheduler-hidden.vbs` helpers.

Every job uses `withoutOverlapping()` so a slow run cannot stack with the next tick.

### The full schedule

| Time | Command | What it does |
|---|---|---|
| **Every minute** | `review-reminders:dispatch` | Fires due review reminders |
| **Every minute** | `oil:chase-returns` | Chases odometer readings for oil recalls |
| **Every 10 min** | `trips:warm` | Warms trip data |
| **Every 10 min** | `notifications:scan` | Generates alerts |
| **Every 10 min** | `events:sync-sheet` | Vehicle log events ⇄ sheet |
| **Hourly** | `import:garage-locations` | Garage location tab (edited during the working day) |
| **Hourly** | `oil:settle-returns` | Settles returned oil-recall cars |
| **Hourly at :05** | `om:sync --contracts --skip-backup` | Keeps availability fresh all day |
| Hourly (unspecified min) | `om:sync --invoices --skip-backup` | Invoices from OM |
| Hourly (unspecified min) | `om:sync --customers --skip-backup` | Customers from OM |
| **02:00** | `fleet:check-expiry` | Flags expired registration/insurance |
| **02:30** | `import:maintenance-sheet` | The maintenance log |
| **02:50** | `maintenance:link-reasons` | Links maintenance reasons |
| **03:00** | `om:sync --vehicles --link --skip-backup` | Vehicle master + linking |
| **03:05** | `intelligence:rebuild-signatures` | |
| **03:10** | `notifications:scan` | Morning sweep |
| **03:15** | `import:vehicle-status` | |
| **03:15** | `intelligence:record-outcomes` | |
| **03:20** | `sync:insurance` | |
| **03:25** | `sync:vehicles` | "Faster" tab — make/model/colour, purchase price |
| **03:30** | `sync:registrations` | "F RTA" tab — fines count/amount, status |
| **03:35** | `import:customer-cases` | |
| **03:40** | `sync:oil-change` | |
| **03:45** | `mileage:scan --apply` | Mileage chain audit + autocorrect |
| **04:00** | `service:sync-reminders` | |
| **04:20** | `intelligence:rebuild-visits` | |
| **04:35** | `intelligence:rebuild-recurrence` | |
| **06:00** | `intelligence:rebuild-health --alert` | |
| **07:30** | `inspections:generate-tasks` | |
| **08:00** | `notifications:scan` | Start-of-day sweep |
| **08:05** | `invoices:scan-overdue` | Chases the invoice SLA |
| **Mon 04:00** | `intelligence:evidence-health --promote --alert` | Weekly evidence gate |
| (unspecified) | `checkpoints:scan` | Daily garage checkpoint chase |

### ⚠️ The scheduler is not fully live in production

Historically, **some imports have not actually been running on the server**. The consequence is the dangerous one: **stale data looks fresh.** A page renders a confident number sourced from a sheet nobody has imported in months.

Before trusting any imported figure, check both:
1. that the scheduler container is actually running, and
2. the source's own last-import timestamp (the expense provider exposes `source()` and `freshness()` precisely for this).

---

## 5. The nightly OM sync runner

`DEPLOYMENT.md` §"Sync Operations" covers a separate runner (`sync-fleet.cmd` on Windows, `sync-fleet.sh` on Linux) that can be scheduled via Task Scheduler or cron:

```bash
./sync-fleet.sh customers     # test one domain first
./sync-fleet.sh all           # full sync
```

Recommended cron: full sync nightly at 02:30, contracts every 3 hours.

Verify integrations after any deploy — `DEPLOYMENT.md` gives read-only checks that confirm the OM API is reachable and the key is valid, and that the Google service-account credential is wired.

---

## 6. Everyday commands

There are **96 project commands** — see [07-Artisan-Commands.md](07-Artisan-Commands.md). The namespaces you will use most:

| Namespace | For |
|---|---|
| `om:` | OfficeManager sync |
| `import:` | Sheet and file imports |
| `sync:` | Per-domain syncs |
| `intelligence:` | Rebuilding derived intelligence data (12 commands) |
| `maintenance:` | Maintenance data operations (9 commands) |
| `expenses:` | Expense import and classification |
| `oil:` | Oil recall flows |
| `notifications:` | Alert generation |
| `schema:` | Schema health and drift |
| `db:backup` | **Take this before anything destructive** |

---

## 7. ⚠️ Rehearsals are not automatically safe

**A "dry run" of a workflow sweep against the live `laravel` database once committed 56 real withdrawals.**

The rollback did not hold because the workflow services commit inside their own transactions. Therefore:

- **Rehearse against a private schema**, never against `laravel`.
- Take `php artisan db:backup` first regardless.
- Do not trust a `--dry-run` flag you have not read the implementation of.

---

## 8. Troubleshooting

| Symptom | Likely cause |
|---|---|
| **"Route api/X not found"** for a route you can see in `api.php` | **Another project owns port 8000.** Check the port before debugging routes. |
| MySQL won't start; XAMPP dies after "Server socket created" | Corrupted Aria `mysql.db` privilege table. Repair with `aria_chk`. |
| A migration passes locally, fails on the server | **MariaDB locally vs MySQL 8 in production.** Known trap: CHECK constraints on foreign keys. |
| A timestamp column changes itself on unrelated updates | MariaDB silently added `ON UPDATE CURRENT_TIMESTAMP` to a `timestamp()` column. Use `dateTime()` for stored moments. |
| Dev server hangs on a page that calls OM | The batch timeout profile was used in a web request. Pass `interactive: true`. |
| A cost figure looks confident but wrong | Check ledger freshness — the expense ledger collapsed ~99% after March 2026. |
| A lane or filter reports "empty" but shouldn't | Client-side filtering over a paginated feed. Filter server-side. |
| A schema drift check says "matches exactly" but data is wrong | Drift check ignores column *types*. Compare types by hand. |

---

## 9. Danger zone

`DEPLOYMENT.md` ends with a manual-only full wipe & rebuild section. Treat it accordingly:

- **Never run `migrate:fresh` against `laravel_test`** unless you intend to empty it (the CRUD suite does exactly this).
- **Never run `artisan migrate` against `fleet_test`** — its migration ledger is broken (133 tables, 1 migrations row). Add columns by hand.
- **A fresh database cannot currently be built from migrations.** Clone an existing one.

Full detail in [15-Known-Issues.md](15-Known-Issues.md).
