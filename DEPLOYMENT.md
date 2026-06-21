# Deployment & Sync Operations

This system syncs from the **OfficeManager API** (source of truth) + **Google Sheets** (make/model/color/price enrichment) into the app database.

**Architecture:** the **CLI is the engine**, the **web `/sync` page is a read-only dashboard**. Syncs run on a schedule via the `sync-fleet` runner. Nobody can start a sync — or a wipe — from the browser (`OFFICEMANAGER_WEB_SYNC_ENABLED=false`, the default).

---

## 1. Where the sync must run

> **Golden rule:** run the sync **on the machine where the database lives**, and that machine must be able to reach the OfficeManager API (`http://81.85.92.150:8080`).
>
> Do **not** run it on a laptop to update a database hosted elsewhere — it would update the wrong (local) DB.

| Host of the app + DB | Runner script | Scheduler |
|----------------------|---------------|-----------|
| **Windows** (XAMPP / IIS) | `sync-fleet.cmd` | Task Scheduler |
| **Linux** (VPS / cloud)   | `sync-fleet.sh`  | cron |

Both scripts: write a timestamped log, retry on failure, hold a single-instance lock, and prune old backups/logs. Run any single phase or all of them.

Targets: `all` · `cars` · `carinfo` · `registrations` · `insurance` · `contracts` · `contracts-all` · `invoices` · `customers` · `maintenance` · `garages`

> **Two contract modes:** `contracts` = **last 6 months** (fast — all open contracts + recent closed; ideal for frequent runs). `contracts-all` = **full history** (slow — every contract; run occasionally). Both always refresh every open contract and detect recently-returned ones.

---

## 2. One-time setup

### 2a. Configure the app (`backend/.env`)
```env
APP_ENV=production
APP_DEBUG=false                      # IMPORTANT: keep false (avoids the query log growing on long runs)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=laravel
DB_USERNAME=...                      # needs SELECT, INSERT, UPDATE, DELETE, and ALTER (for migrations)
DB_PASSWORD=...

OFFICEMANAGER_BASE_URL=http://81.85.92.150:8080
OFFICEMANAGER_API_KEY=...            # required
OFFICEMANAGER_WEB_SYNC_ENABLED=false # keep false -> /sync stays read-only

# Optional tuning (defaults shown):
OFFICEMANAGER_OWNER_NOS=1541         # our owner number(s), comma-separated
OFFICEMANAGER_EXTRA_CAR_SERIALS=2155 # individual cars under another owner (the 2088 GMC)
OFFICEMANAGER_CONTRACTS_CLOSED_MONTHS=0   # 0 = full history for our cars
```

Then:
```bash
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
```

### 2b. Point the runner at this server
Edit the top of the script for your paths:

**`sync-fleet.cmd` (Windows)**
```bat
set "PHP=C:\xampp\php\php.exe"
set "ARTISAN_DIR=C:\path\to\fleet-fullstack\backend"
```

**`sync-fleet.sh` (Linux)** — set via env or edit defaults:
```bash
PHP=php
ARTISAN_DIR=/var/www/fleet-fullstack/backend
chmod +x sync-fleet.sh
```

### 2c. Test it manually (before scheduling)
Run a light phase first and read the log:
```bash
# Windows:  sync-fleet.cmd customers
# Linux:    ./sync-fleet.sh customers
```
Log: `backend/storage/logs/sync/sync-<phase>-<timestamp>.log`. If it ends with `SUCCESS`, you're good — then try `all`.

---

## 3. Schedule the nightly sync

### Windows — Task Scheduler
Run in an **Administrator** Command Prompt (edit the path + time):
```cmd
:: Full sync nightly at 02:30
schtasks /create /tn "FleetSync-All" /tr "\"C:\path\to\fleet-fullstack\sync-fleet.cmd\" all" /sc DAILY /st 02:30 /rl HIGHEST

:: (optional) contracts refresh every 3 hours
schtasks /create /tn "FleetSync-Contracts" /tr "\"C:\path\to\fleet-fullstack\sync-fleet.cmd\" contracts" /sc HOURLY /mo 3 /rl HIGHEST
```
- Run now to test: `schtasks /run /tn "FleetSync-All"`
- List / remove: `schtasks /query /tn "FleetSync-All"` · `schtasks /delete /tn "FleetSync-All" /f`
- In Task Scheduler GUI, set **"Run whether user is logged on or not"** so it runs headless.

### Linux — cron
```bash
crontab -e
```
```cron
# Full sync nightly at 02:30
30 2 * * * /var/www/fleet-fullstack/sync-fleet.sh all   >> /var/log/fleetsync.cron 2>&1
# (optional) contracts every 3 hours
0 */3 * * * /var/www/fleet-fullstack/sync-fleet.sh contracts >> /var/log/fleetsync.cron 2>&1
```

---

## 4. Pre-flight sanity checklist (before going live)

Run these from `backend/`. All should pass.

**1) PHP & app boot**
```bash
php -v                       # 8.2+
php artisan about            # app boots, env/DB shown
```

**2) Database connection & permissions**
```bash
php artisan migrate:status   # connects + lists migrations (read)
php artisan db:backup        # proves INSERT/SELECT + write access to storage/app/backups
```
The DB user must have **SELECT, INSERT, UPDATE, DELETE** (and **ALTER** for migrations). `db:backup` writing a `.sql` file confirms read access + disk space.

**3) OfficeManager API reachable + key valid**
```bash
# quick check — should print a small JSON sample, not a timeout/401
php artisan om:sync --contracts --from=2026-06-01 --to=2026-06-02 --dry-run --skip-backup
```
`--dry-run` writes nothing. If it lists contracts, the API host, port, and key are all good. If it times out → the server can't reach `81.85.92.150:8080` (firewall/network). If `OFFICEMANAGER_API_KEY is not set` → fix `.env`.

**4) Disk space** for nightly backups (~45 MB each; the runner prunes after 14 days).

**5) Web stays read-only**
```bash
php artisan tinker --execute="echo config('officemanager.web_sync_enabled') ? 'WEB SYNC ON' : 'read-only OK';"
```
Should print `read-only OK`.

**6) First real run**
```bash
# Windows:  sync-fleet.cmd all
# Linux:    ./sync-fleet.sh all
```
Watch it on the **/sync** page (live counts + progress) or tail the log. When the bottom of the log shows the per-phase summary with all `[ OK ]`, scheduling is safe to enable.

---

## 5. Day-to-day

- **Watch:** open `/sync` in the app — live counts, current job progress, and the "recent syncs — what changed" history (CLI/scheduled runs show up here automatically).
- **Data quality:** `/data-health` lists incomplete records (missing VIN/mileage, etc.) to fix at the source.
- **Run a phase manually:** double-click `sync-fleet.cmd` → pick from the menu, or `sync-fleet.cmd <target>`.
- **Logs:** `backend/storage/logs/sync/`. **Backups:** `backend/storage/app/backups/`.
- **A failed phase** is recorded and retried on the next run — every phase is idempotent (safe to re-run, never duplicates).

## 6. Danger zone — full wipe & rebuild (manual only)
Deliberately **not** in the runner or the web UI. Only when you truly want to delete everything and rebuild (it backs up first):
```bash
php artisan fleet:refresh --wipe
```
