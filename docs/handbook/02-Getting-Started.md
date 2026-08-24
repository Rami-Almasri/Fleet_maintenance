# Getting Started

How to get FleetView running on a development machine.

> **Note.** `backend/README.md` is the stock Laravel readme and contains no project-specific information. This document replaces it.

---

## 1. Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| **PHP** | 8.2+ | The project targets `^8.2`. |
| **Composer** | 2.x | |
| **Node.js** | 18+ | For the React frontend. |
| **MySQL** | 8.0 (production) | Locally this project runs **MariaDB via XAMPP**. See the warning in §6. |
| **Git** | any | |

The original development environment is **Windows 11 + XAMPP**, with MySQL on the default port and the MySQL CLI at `C:\xampp\mysql\bin\mysql.exe`. Nothing in the code is Windows-specific; production runs on Linux.

---

## 2. Get the code

```bash
git clone <repository-url> fleet-fullstack
cd fleet-fullstack
```

Repository layout:

```
fleet-fullstack/
├── backend/     Laravel 12 API
├── frontend/    React 19 SPA
├── docs/        documentation (this handbook is in docs/handbook/)
└── secrets/     credential handling notes - nothing sensitive is committed
```

---

## 3. Database — read this before you run any migration

**⚠️ Do not create the database by running `php artisan migrate` on an empty schema. It will fail.**

The migration set (287 files) cannot currently build a database from zero. This is a known, documented defect — see `docs/issues/migrations-cannot-build-fresh-database.md` and document 15 in this handbook.

**The supported way to get a working database is to clone an existing one.** Ask the maintainer for a dump, then:

```bash
# create the schema
mysql -u root -e "CREATE DATABASE laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# load the dump
mysql -u root laravel < fleetview-dump.sql
```

The live/development database is named **`laravel`** and currently holds **134 tables**.

There are also two test databases, `laravel_test` and `fleet_test`. Both have caveats — see §7 and document 15.

---

## 4. Backend setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Then edit `.env`. The variables you must set for a working local instance:

```ini
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=

# Required: OfficeManagerClient throws on construction if this is missing
OFFICEMANAGER_API_KEY=<ask the maintainer>
OFFICEMANAGER_BASE_URL=<ask the maintainer>
```

Google Sheets, Power BI and the AI keyword features need further variables — see document 13, [Configuration Reference](13-Configuration.md). They are all optional for local work; the features degrade rather than crash.

Start the API:

```bash
php artisan serve
```

**⚠️ Port 8000 is contested.** Other projects on the original development machine also serve on 8000. If you get "route api/X not found" for a route you can see in `routes/api.php`, check which process owns the port **before** debugging routes — you are probably talking to a different application.

> Do **not** run `composer setup`. It is the stock Laravel script and includes `php artisan migrate --force`, which will fail for the reason in §3.

---

## 5. Frontend setup

```bash
cd frontend
npm install
npm start
```

This is **Create React App** (`react-scripts`), not Vite — despite a stray `vite.config.js` in the backend folder. The dev server runs on port 3000 and proxies API calls to the backend.

Useful scripts:

| Command | What it does |
|---|---|
| `npm start` | Dev server |
| `npm run build` | Production build |
| `npm run check` | **Run before committing UI work** — i18n + RTL checks |
| `npm run check:i18n` | Missing translations and hardcoded strings |
| `npm run check:i18n:report` | Report of hardcoded strings still to convert |
| `npm run check:rtl` | Right-to-left layout checks (the app is bilingual EN/AR) |

---

## 6. A local-vs-production difference that has caused real bugs

Local development runs **MariaDB** (XAMPP). Production runs **MySQL 8**. They are not fully compatible, and migrations that pass locally have failed on the server.

Two known traps:

1. **CHECK constraints on foreign keys** behave differently. Test schema changes against MySQL 8 before deploying.
2. **MariaDB silently adds `ON UPDATE CURRENT_TIMESTAMP`** to columns declared with `timestamp()`. If you are storing a *moment in time* that must not change when the row is updated, declare it `dateTime()` instead.

---

## 7. Running tests

There are several suites with separate configurations:

```bash
cd backend

php artisan test                    # default suite (phpunit.xml)
php artisan test --testsuite=...    # narrow it down

# Windows helper scripts
run-crud-tests.cmd                  # CRUD + notification suite (phpunit.crud.xml)
run-golden-tests.cmd                # golden suite (phpunit.golden.xml)
```

| Suite | Config | Database | Status |
|---|---|---|---|
| Default | `phpunit.xml` | | |
| CRUD + notifications | `phpunit.crud.xml` | `laravel_test` | **⚠️ Already failing at HEAD.** It runs `migrate:fresh`, which **empties `laravel_test`**. |
| Foundation | `phpunit.foundation.xml` | | See `backend/tests/Foundation/README.md` |
| Golden | `phpunit.golden.xml` | | |

**⚠️ Never run `php artisan migrate` against `fleet_test`.** Its migration ledger is broken — 133 tables but only 1 row in the `migrations` table — so Laravel believes almost nothing has run. Add columns to it by hand.

Two tests are worth knowing about because they encode project rules rather than behaviour:

- **`NoFinancialBypassTest`** — fails if you add a second write path for money. That failure is correct; fix your code, not the test.
- The **Foundation** suite guards structural guarantees. See its README.

---

## 8. Verifying your setup

```bash
cd backend
php artisan route:list          # should list ~493 api/* routes
php artisan list                # should show ~96 project commands alongside Laravel's
php artisan schema:health       # schema drift check (see the caveat below)
```

**⚠️ `schema:health` under-reports.** It can say "matches exactly" while a column is `decimal(12,2)` on one side and `decimal(14,2)` on the other. For money columns, verify types by hand.

---

## 9. Before you change anything

1. Read [01-System-Overview.md](01-System-Overview.md) — the whole system in narrative form.
2. Read [03-Architecture.md](03-Architecture.md) and the root `ARCHITECTURE-CONVENTIONS.md` — how code is expected to be written here.
3. Read [15-Known-Issues.md](15-Known-Issues.md) — **especially before running any command that writes.**
4. Take a backup: `php artisan db:backup`.

That last point is not boilerplate. A rehearsal against the live database in this project once committed 56 real records.
