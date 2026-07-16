# Deployment & Sync Operations

> **Two ways to run FleetView in production:**
> 1. **[Docker Compose](#docker-production-deployment)** (recommended, below) — the whole stack (MySQL + API + web + scheduler) in containers.
> 2. **Bare-metal** (XAMPP/VPS) — the original sync-operations guide starts at [§ Where the sync must run](#1-where-the-sync-must-run).
>
> The Docker stack runs the Laravel **scheduler in its own container**, so the OfficeManager/Google-Sheets syncs, notifications and mileage jobs all run automatically — you do **not** also need the Windows Task / cron `sync-fleet` runner described further down (that section stays for bare-metal installs).

---

# Docker Production Deployment

## Architecture

Single-server stack, orchestrated by `docker-compose.yml`:

```
                         ┌──────────────────────────────────────────┐
   Internet :80  ───────▶│  nginx (web)                             │
   (put TLS proxy        │   • serves React SPA (static build)       │
    in front for 443)    │   • /api,/sanctum,/up → php-fpm           │
                         │   • /storage/* → uploaded media           │
                         └───────────────┬──────────────────────────┘
                                         │ fastcgi :9000
                         ┌───────────────▼──────────────────────────┐
                         │  backend (php-fpm, Laravel 12)            │
                         │   • migrates + caches on boot (app role)  │
                         └───────┬───────────────────┬──────────────┘
                                 │                   │
              ┌──────────────────▼───────┐   ┌───────▼───────────────┐
              │  scheduler                │   │  db (MySQL 8)          │
              │  php artisan schedule:work│   │  volume: db_data       │
              │  (om:sync, sheets, scans) │   └────────────────────────┘
              └───────────────────────────┘
   Optional (profile "workers"):  queue (queue:work) · redis
   Shared volume: storage_data (uploads, logs, backups)  ·  Google creds bind-mounted read-only
```

**Design notes**
- The **frontend is static** — the CRA build is baked into the nginx image at build time; there is no Node runtime container. `REACT_APP_API_URL` defaults to `/api` (same origin), so no CORS setup is needed.
- The **backend image is reused** by `backend`, `scheduler` and `queue` (built once, tagged `fleetview/backend:latest`). `CONTAINER_ROLE` decides whether a container runs migrations (only `app`).
- **Redis and the queue worker are off by default** — the app uses the `database` driver for cache/session/queue and has no queued jobs today. Enable them later with `--profile workers`.
- **Secrets are never in the images**: MySQL creds come from the root `.env`, Laravel config from `backend/.env`, and the Google service-account JSON is bind-mounted read-only from `./secrets/google/`.

## Files

| File | Purpose |
|------|---------|
| `backend/Dockerfile` | php-fpm 8.2 image: extensions (`pdo_mysql, mbstring, bcmath, gd, exif, pcntl, zip, intl, opcache`) + composer `--no-dev`, OPcache on. No ffmpeg (no server-side transcoding). |
| `backend/docker/entrypoint.sh` | Waits for DB → (app role) `migrate --force` + `storage:link` → `config/route/view cache` → exec. |
| `backend/docker/{opcache,uploads}.ini`, `www.conf` | Prod PHP tuning, 256 MB uploads, `clear_env=no` so env reaches PHP. |
| `backend/.dockerignore` | Keeps `.env`, `vendor`, `node_modules`, logs, sqlite out of the image. |
| `frontend/Dockerfile` | Node 20 build stage → nginx 1.27 serving SPA + proxy. |
| `frontend/nginx.conf` | SPA fallback, `/api`→php-fpm, `/storage`→media, gzip, 256 MB body. |
| `frontend/.dockerignore` | Keeps `node_modules`, `build`, `.env*` out. |
| `docker-compose.yml` | db · backend · nginx · scheduler (+ optional queue · redis). |
| `.env.docker.example` | Template for the root `.env` (MySQL creds, port, API URL). |
| `secrets/README.md` | Where to drop `google/credentials.json` on the server. |

## Prerequisites (install on the server)

- **Docker Engine ≥ 24.0** and **Docker Compose v2 ≥ 2.20** (`docker compose`, not the legacy `docker-compose`).
- Verify: `docker --version` and `docker compose version`.

## Environment setup (one time, on the server)

```bash
# 1. Get the code
git clone <repo-url> fleet-fullstack && cd fleet-fullstack

# 2. Infra env for compose (MySQL creds, port). NEVER commit the resulting .env.
cp .env.docker.example .env
#    → edit .env: set MYSQL_PASSWORD + MYSQL_ROOT_PASSWORD (strong), APP_PORT

# 3. Laravel app env
cp backend/.env.example backend/.env
#    → edit backend/.env:
#        APP_ENV=production   APP_DEBUG=false
#        APP_URL=https://your-host     APP_FRONTEND_URL=https://your-host
#        (DB_* are set automatically by compose from the root .env MYSQL_* values —
#         no need to edit DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD here)
#        OFFICEMANAGER_API_KEY=...     (OFFICEMANAGER_WEB_SYNC_ENABLED=false)
#        FILESYSTEM_DISK=local  (or s3 + AWS_* for offloaded media)
#        LOG_LEVEL=warning   LOG_STACK=daily

# 4. Google service-account key (only if using Sheets sync)
mkdir -p secrets/google
cp /path/to/service-account.json secrets/google/credentials.json
```

## Build & start

```bash
# Build images and start the base stack (db, backend, nginx, scheduler)
docker compose up -d --build

# App key — generate ONCE, then it lives in backend/.env (persists on the host)
docker compose exec backend php artisan key:generate

# (re-cache config now that APP_KEY is set)
docker compose restart backend scheduler
```

App is now at `http://<server>:${APP_PORT}`.

To also run the optional queue worker + redis:
```bash
docker compose --profile workers up -d
```

## Migrations

Migrations run **automatically** on `backend` boot (`entrypoint.sh`, app role). To run them manually:
```bash
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan migrate:status   # inspect
```
Seed roles/permissions (first deploy only, if needed):
```bash
docker compose exec backend php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

## Verify the integrations (after first start)

```bash
docker compose exec backend php artisan about                      # app boots, DB shown
# OfficeManager API reachable + key valid (writes nothing):
docker compose exec backend php artisan om:sync --contracts --from=2026-06-01 --to=2026-06-02 --dry-run --skip-backup
# Google Sheets credential wired:
docker compose exec backend php artisan tinker --execute="new App\Services\GoogleSheetsService();"
docker compose logs -f scheduler                                    # watch scheduled jobs fire
```

## Backups

The DB lives in the `db_data` volume; uploads in `storage_data`.

```bash
# App-level DB backup (writes into storage_data → storage/app/backups)
docker compose exec backend php artisan db:backup

# Raw MySQL dump to the host
docker compose exec db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup-$(date +%F).sql

# Uploaded media (photos/videos)
docker run --rm -v fleet-fullstack_storage_data:/data -v "$PWD":/out alpine \
  tar czf /out/storage-$(date +%F).tar.gz -C /data .
```
> Volume names are prefixed with the compose project (folder) name — confirm with `docker volume ls`.

## Updating

```bash
git pull
docker compose up -d --build          # rebuilds images, recreates changed containers
# migrations + config/route/view caches re-run automatically on backend boot
```
Roll back by checking out the previous tag/commit and re-running `docker compose up -d --build`. The `db_data` and `storage_data` volumes are untouched by rebuilds.

## TLS / HTTPS

nginx here listens on plain `:80`. For production TLS, terminate in front:
- a host reverse proxy (Caddy/Traefik/nginx) or cloud load balancer on `:443` proxying to `${APP_PORT}`, **or**
- add a certbot/Caddy sidecar.

Then set `APP_URL`/`APP_FRONTEND_URL` to the `https://` host and keep only `:443` open publicly.

---

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
