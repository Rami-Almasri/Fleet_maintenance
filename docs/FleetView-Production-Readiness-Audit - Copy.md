# FleetView — Production Readiness Audit

**Purpose:** Baseline assessment of the **current** FleetView system before handing it to real employees. This is a *stabilise-and-ship* audit, **not** a redesign. No new tables, migrations, engines, or logic are proposed here. The Intelligence Layer is explicitly **Phase 2**, deferred until real usage data exists.

**Date:** 2026-07-12 · **Branch:** `ui-overhaul-v1` · **Method:** read-only code audit (backend + frontend), verified against a live boot + `migrate:status`. Nothing was modified.

**Stack (verified):** Laravel 12.61.1 · PHP 8.2.12 · MySQL (`laravel`, via XAMPP) · React (CRA) SPA · Sanctum bearer-token auth · Spatie roles/permissions · Spatie medialibrary · Google Sheets API · OfficeManager (OM) REST API.

---

## Executive Verdict

**The system is close to employee-ready, but not yet production-configured.** The core plumbing is sound: the app boots cleanly, all **95 migrations are applied** (zero pending), RBAC is complete and consistently enforced, every routed controller method exists, every frontend API call resolves to a real route, secrets are clean, and exception handling is centralized.

Three things stand between "works on the dev machine" and "real employees using it":

1. **Deploy configuration** — `.env` is still in dev mode (`APP_ENV=local`, `APP_DEBUG=true`, `FILESYSTEM_DISK=local`, mail/broadcast = `log`), a committed `frontend/.env` hard-points the SPA at `localhost`, and S3 is unconfigured.
2. **The rental lifecycle (contract → handover → return) is the weak operational flow** — the backend logic exists but the vehicle **availability status is not flipped by the web UI** on create/close, and several transitions have no wired frontend. Everything else (maintenance, inspections, condition grading, history) is UAT-ready.
3. **A few go-live safety items** — open public signup, login ignores account `status`, and the bootstrap admin ships with password `password`.

None of these require redesign. They are configuration, wiring, and hardening tasks. Details and a prioritized blocker list follow.

---

## 1. Current System Readiness

### 1.1 What is ready today (UAT-ready)
| Area | State | Notes |
|---|---|---|
| App boot & migrations | ✅ | Boots clean; all 95 migrations `Ran`; MySQL connected. |
| RBAC (roles/permissions) | ✅ | 37 permissions, 10 roles, complete matrix, uniformly enforced at the route layer. No dead perms, no undefined-but-checked perms. |
| Maintenance workflow (open → repair → close) | ✅ | 5 intake paths, full state machine, odometer/photo gates, cost deferral. Most mature module. |
| Condition grading & rental eligibility gate | ✅ | green/orange/yellow/red; `ContractEligibilityService` blocks red/yellow. |
| Inspection Schedules & Readiness dashboard | ✅ | Recurring safety/ops inspections + 9-check readiness evaluation, self-contained. |
| Vehicle create (manual) & vehicle history | ✅ | Manual `POST /Vehicle` works day 1; Activity timeline unions log/logistics/inspection events. |
| Error handling | ✅ | `ResponseHelper::fromException` wired globally in `bootstrap/app.php`; no data-corrupting swallows. |
| Route ↔ controller ↔ frontend integrity | ✅ | No broken routes; every `api.*` call resolves. |

### 1.2 Weak / partial flows (need attention before or during UAT)
| Area | State | Issue |
|---|---|---|
| Rental contract create | ⚠️ PARTIAL | Web form does **not** set `vehicles.operational_status='rented'` → same car can be double-booked from the form until an OM reconcile runs. `vehicle_id` and `customer_id` are both nullable. |
| Vehicle handover / delivery | ⚠️ FRAGMENTED | No dedicated customer check-out transaction; the only status-flipping path (`OperationController@start`) has no frontend. |
| Vehicle return / close | ⚠️ BACKEND-ONLY | `OperationsService::closeOperation` correctly frees the car, but **no frontend calls `/close`**; UI edit-to-closed bypasses the free-vehicle logic. Returns currently rely on nightly OM sync + reconcile. |
| Damage report (create) | ⚠️ NO UI CREATE | Viewing works (`DamageAccidents.js`); the only write path is `POST /Inspections` with `damage_flagged=true`, which no frontend calls. |
| Inspection photo capture (hotspot/S3) | ⚠️ BACKEND-ONLY | `inspection_records` + presigned API complete, but `InspectionPrototype.js` is a client-side demo that never POSTs; presign hard-requires S3. |

### 1.3 Seed data readiness
`php artisan migrate --seed` runs `DatabaseSeeder`, which bootstraps everything needed for day 1:
- **`RolesAndPermissionsSeeder`** — 37 permissions + 10 roles (idempotent, safe on every deploy).
- **`FaultCauseSeeder`**, **`FindingKeywordSeeder`** — diagnostic libraries (symptom→root-cause, keyword risk grades).
- **`GarageRoutingRuleSeeder`** — safe no-op on a fresh DB (rules created from admin UI later).
- **Bootstrap super-admin** — `admin@fleet.local` / `password`, role `super-admin`.

**Not seeded (by design):** live fleet, contracts, customers — these come from the OM sync (`om:sync` / `fleet:refresh`), not seeders. `BrancheSeeder`, `OrganisationSeeder`, `VehicleSeeder` are empty stubs (no branch/org record is seeded).

### 1.4 Critical bugs & broken flows (verified)
| # | Sev | Issue | Location |
|---|---|---|---|
| B1 | **High** | Production build points the whole SPA at `localhost` — `frontend/.env` commits `REACT_APP_API_URL=http://127.0.0.1:8000/api`; CRA loads `.env` in prod builds too and no `.env.production` overrides it. → blank app in prod. | `frontend/.env:1`, `frontend/src/api/client.js:4` |
| B2 | Med | Delivery/Orders board 500s on cold cache if Google Sheet unreachable or `credentials.json` missing (no graceful degrade on cold path). | `TripDashboardController.php:60` |
| B3 | Med | `operational_status` drift — web contract create/close don't flip availability (see 1.2). Double-book risk between syncs. | `ContractService::store` |
| B4 | Med | Red car stays red after maintenance close — `close()` never resets `condition_grade`, so a breakdown-grounded car remains booking-blocked until manually re-graded. | `MaintenanceWorkflowService::close` |
| B5 | Low | `VehicleStatusDashboard.js` is orphaned dead code (route redirects elsewhere; capability preserved in Readiness tab). | frontend |

### 1.5 Missing validations (data-integrity gaps)
| # | Gap | Risk |
|---|---|---|
| V1 | Contract `in_milage ≥ out_milage` not enforced (each only `nullable\|integer\|min:0`). | Negative rental distance; drags canonical odometer backward. |
| V2 | Contract `in_date ≥ out_date` not enforced. | Negative duration skews utilization/profit. |
| V3 | `OperationController::close` accepts backward `in_milage` vs recorded `out_milage`. | Backward odometer written with no rejection. |
| V4 | Contract money fields (`rents_debit`, `contract_balance`, `day_price`) lack `min:0`. | Negatives flow into revenue rollups — **confirm intentional** (credits) before changing. |
| V5 | Contract `vehicle_id`/`customer_id` nullable — a contract can save with neither. | Orphan contracts. |

### 1.6 Go-live safety items (RBAC / auth)
| # | Sev | Issue |
|---|---|---|
| S1 | **High** | `POST /auth/signup` is public + open, minting `viewer` accounts that can read the whole fleet/customer/billing dataset. Disable, admin-gate, or default to an empty role pending activation. |
| S2 | Med | `login` never checks `status` — a `suspended` user still authenticates and mints tokens. Suspension is cosmetic. |
| S3 | Med | Bootstrap admin ships with password `password` (public in repo) at a predictable email. **Rotate immediately on deploy.** |
| S4 | Low | No in-app user-management API — role assignment is CLI-only (`php artisan user:role`). Fine for a small team. |

---

## 2. Employee Usage Scenarios (test matrix)

For each scenario: **Actor · Permission · Entry point · DB change · Expected result · Status.** Use this as the UAT script.

### 1. Vehicle creation / import — ✅ WORKS (two paths)
- **Manual:** manager · `vehicles.manage` · Vehicles.js "Add New Vehicle" → `POST /Vehicle` → `VehicleService::store`. Only `vin` required+unique; writes `vehicles` row `origin='web'`.
- **OM sync:** `php artisan om:sync --vehicles` → `origin='api'`. **Requires live OM API.** ⚠️ Nightly cron (`--link`) refreshes existing cars only — **bulk population needs a manual `om:sync --vehicles`.**
- **Expected:** new car appears in Vehicles list. **Test:** confirm a hand-created car defaults to a rentable lifecycle status.

### 2. Vehicle availability — ✅ WORKS
- Grade: manager · `vehicles.manage` · `POST /Vehicle/{id}/condition`. Availability = `operational_status`, **derived from open contracts** by `OperationsService::reconcileAllOperationalStatus`.
- **Expected:** car shows Available/Rented/Maintenance; red/yellow blocks rental. ⚠️ Status only as fresh as the last sync/reconcile.

### 3. Rental contract flow — ⚠️ PARTIAL
- rental manager · `contracts.manage` · `/contracts/new` → `POST /Contract` → `ContractService::store` (eligibility gate → `Contract::create`, `origin='web'`).
- **Expected:** contract saved, number `W-#####`. ⚠️ **Does not set car to `rented`** → double-book risk (B3). Vehicles must pre-exist.

### 4. Vehicle handover / delivery — ⚠️ FRAGMENTED
- Handover ≈ contract creation (8-point readiness checklist, stamps `out_date/out_milage/opened_by`) but omits the status flip. The complete path (`OperationController@start`, `operations.manage`) has **no frontend**.
- **Test focus:** confirm what the delivery clerk actually clicks and whether the car frees/locks correctly.

### 5. Vehicle return — ⚠️ BACKEND-ONLY
- `operations.manage` · `POST /Contract/{contract}/close` → `OperationsService::closeOperation`: sets `state='closed'`, `in_date/in_milage/in_fuel/closed_by`, `operational_status='available'`.
- ⚠️ **No frontend calls `/close`.** Returns rely on OM sync detecting the close. **Test:** does a returned car become Available without waiting for the nightly sync?

### 6. Inspection process — MIXED
- **(a) Hotspot inspection + S3 photos:** `inspections.manage` · `POST /Inspections`. Backend complete; ⚠️ **no frontend consumer** (demo only); presign needs S3. — PARTIAL.
- **(b) Inspection Schedules:** `inspections.manage` · `/InspectionSchedules`. — ✅ WORKS.
- **(c) Readiness / pre-rental:** read perms · `/readiness` + `VehicleReadinessService::evaluate`. — ✅ WORKS.
- **(d) Re-inspection QC:** `maintenance.initiate|delegate` · in-workflow. — ✅ WORKS.

### 7. Damage reporting — ⚠️ NO UI CREATE
- **View:** `maintenance.view` · `DamageAccidents.js` → `GET /Maintenance/incidents` (derived from `maintenances`). — ✅ as viewer.
- **Create:** only via `POST /Inspections` `damage_flagged=true` — **no frontend calls it.** Employee can't file a damage report through the app today.

### 8. Maintenance request (open ticket) — ✅ WORKS (5 intake paths)
- **A. Inspector Pad pickup** (`maintenance.initiate`) → `inspection_pending`.
- **B. Driver request** (`maintenance.logistics`) → `inspection_requested` (alert only).
- **C. Manager direct open** (`maintenance.manage`) → `inspection_diagnostic`.
- **D. Breakdown** (`maintenance.initiate|manage`) → `inspection_pending`, auto `fault_severity=critical`, grounds car **red**. *Simplest day-1 path.*
- **E. Complaint** (`maintenance.manage`) → `complaint_triage`.
- Each writes a `maintenances` row + `vehicle_log_events`; cascade sets `operational_status='maintenance'`.

### 9. Maintenance completion (close) — ✅ WORKS, one gap
- `markReady` → `collectFromGarage` (photo+odometer) → `arriveAtPark` (minor auto-closes; major → `ready_for_reinspection`) → `close` (`maintenance.initiate|delegate`). Cost deferred (`POST /{ticket}/cost`). Cascade frees the car to `available`.
- ⚠️ **B4:** `close()` doesn't reset `condition_grade` — a red car stays red/booking-blocked until re-graded.

### 10. Vehicle history tracking — ✅ WORKS
- `vehicles.view` · VehicleProfile "Activity" tab → `GET /Vehicle/{vehicle}/activity` → `ActivityFeedService::forVehicle` (unions `vehicle_log_events` + `logistics_task_events` + `inspection_records`). Plus Timeline (`maintenances`, incl. imported history) and Service History (`invoice_items`). ⚠️ Activity empty until in-app actions occur.

**UAT bottom line:** scenarios **1, 2, 6b/c/d, 8, 9, 10** are ready to test as-is. Scenarios **3→4→5 (rental round-trip)** and **7 (damage create)** need UI wiring or a documented manual step before employees rely on them.

---

## 3. Production Preparation Checklist

### 3.1 Environment variables (dev → prod)
`.env.example` is the stock Laravel skeleton and **omits every integration key** — expand it into a real deployment template first.

**App / core**
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_URL=https://…` (real host), `APP_FRONTEND_URL=` (SPA URL, used by notification deep-links)
- [ ] `APP_KEY` set (already generated)
- [ ] Explicit `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` with a **non-empty password** (currently commented out → relying on XAMPP root defaults)

**OM API** (secret)
- [ ] `OFFICEMANAGER_API_KEY` set (present in current `.env`)
- [ ] `OFFICEMANAGER_BASE_URL` — currently using hardcoded default `http://81.85.92.150:8080` (plain HTTP). Set explicitly; move to HTTPS/DNS if OM supports it.
- [ ] Keep `OFFICEMANAGER_WEB_SYNC_ENABLED=false` (browser can't trigger sync/wipe)

**Google Sheets**
- [ ] Deploy `storage/app/google/credentials.json` (service-account, gitignored — **sync fully breaks without it**)
- [ ] Grant the service account read access to every spreadsheet; carry over all `GOOGLE_SHEETS_*_ID/GID`

**AWS / media** (secret — required for photo/video flows)
- [ ] `FILESYSTEM_DISK=s3` + `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (bucket currently **empty**)
- [ ] Verify `FFMPEG_PATH`/`FFPROBE_PATH` for the host OS (defaults are Linux paths)

**Mail** (only if `NOTIFY_MAIL_ENABLED=true`)
- [ ] Real `MAIL_MAILER` + host/credentials + `MAIL_FROM_ADDRESS` (currently `log`)

**Frontend** (⚠️ build-time, not env at runtime)
- [ ] Add `frontend/.env.production` with the real `REACT_APP_API_URL` (fixes **B1**), then rebuild
- [ ] Feature flags in `frontend/src/config/features.js` (`SHOW_FINANCIALS`, `SHOW_VIDEO_REVIEW`, `DEMO_MODE`) are baked into the build — flipping requires a rebuild

### 3.2 Database migration status
- [x] All 95 migrations applied (verified via `migrate:status`) — **no pending migrations**
- [ ] On the prod host: `php artisan migrate --force`
- [ ] `php artisan db:seed` (roles/permissions + diagnostic libraries + bootstrap admin) — idempotent

### 3.3 Storage / files
- [ ] `php artisan storage:link` (public disk fallback served via `public/storage`)
- [ ] Provision `storage/app/google/credentials.json`
- [ ] Confirm upload landing zones exist on the chosen disk: `maintenance-invoices/`, `garage-invoices/`, `maintenance-videos/`, inspection photos, odometer photos
- ⚠️ Latent: `media-library.php` references a `media` disk not defined in `filesystems.php` — dormant (no `addMedia()` used) but would fail if spatie media is ever enabled

### 3.4 API integrations
- [ ] OM API reachable from the prod host + key valid — verify with `php artisan om:sync --contracts --from=… --to=… --dry-run --skip-backup`
- [ ] Google Sheets reachable + service account authorized on all sheets
- [ ] S3 bucket reachable + credentials valid (test an inspection photo presign)
- [ ] Publish a restrictive `config/cors.php` — currently the framework default `allowed_origins: ['*']`; lock to the SPA origin

### 3.5 Queues / jobs
- **No queued jobs exist** (zero `ShouldQueue`). Notifications use the durable `database` channel + frontend polling.
- [ ] `QUEUE_CONNECTION=database` (or `sync`) is fine — **a `queue:work` worker is not required** for current functionality
- [ ] Consider Redis for cache/session/queue under load (dashboard uses a versioned aggregate cache)

### 3.6 Scheduled tasks
Two mechanisms exist — confirm both are set up **and not double-running the sync**:
1. **Laravel scheduler** (`routes/console.php`) — fired by `run-scheduler.vbs` → `php artisan schedule:run` every minute via a Windows Task. Schedules: `om:sync` (03:00), `import:maintenance-sheet` (02:30), `mileage:scan` (03:45), `service:sync-reminders` (04:00), `inspections:generate-tasks` (07:30), `notifications:scan` (every 10 min + 03:10 + 08:00), `invoices:scan-overdue` (08:05), `fleet:check-expiry` (02:00), `trips:warm` (every 10 min), `maintenance:link-reasons` (02:50).
- [ ] Register the "FleetView Scheduler" Windows Task — run `backend\scripts\register-scheduler-task.cmd` from an **elevated (Administrator)** prompt. Runs the SYSTEM account so it survives reboots and doesn't depend on any user being logged in; NOT run automatically by this audit/tooling since it's a persistent, privileged OS change requiring explicit human sign-off. (Linux equivalent: cron `* * * * * php artisan schedule:run`.) `inspections:generate-tasks` — the canonical, continuously-running source of system-generated inspection requests — now also logs every scan cycle (start/complete/failures) to `storage/logs/laravel.log`, so a missed run is diagnosable without an attached terminal.
2. **Sync runner** (`sync-fleet.cmd`/`.sh`, per `DEPLOYMENT.md`) — heavier, retrying, single-instance-locked sync invoked by Task Scheduler/cron.
- ⚠️ **`om:sync` is scheduled in BOTH** (console.php 03:00 *and* the sync-fleet runner) — pick one primary path or ensure staggered times so they don't overlap. `withoutOverlapping()` guards the Laravel one but not across the two mechanisms.

### 3.7 Notifications
- In-app bell: DB notifications + polling; `notifications:scan` detectors (overdue rentals, maintenance overruns, expiring docs, service-due, pending approvals).
- Email is **parked** behind `NOTIFY_MAIL_ENABLED=false`; `invoice_overdue_alerts` parked. No broadcaster wired (`BROADCAST_CONNECTION=log`) — acceptable.
- [ ] Confirm the scan is scheduled (see 3.6) — the bell goes stale without it

### 3.8 Error logging
- [ ] `APP_DEBUG=false` (stops stack-trace leaks; `ResponseHelper` already suppresses 5xx detail in prod)
- [ ] `LOG_LEVEL=warning`/`error`; consider `LOG_STACK` daily + a Slack/Papertrail channel (`LOG_SLACK_WEBHOOK_URL` supported, unset)

### 3.9 Backup strategy
- [ ] `php artisan db:backup` proves write access — schedule it (the sync runner prunes backups after 14 days; ~45 MB each)
- [ ] Ensure disk headroom on `storage/app/backups`
- [ ] For a full rebuild only (manual, backs up first): `php artisan fleet:refresh --wipe` — **never** in the runner or web UI

### 3.10 Final prod caching
- [ ] `php artisan config:cache route:cache event:cache`
- [ ] Confirm `.env` is gitignored (holds the live OM key)

---

## 4. Prioritized Go-Live Blocker List

**Must fix before employees log in:**
1. **B1** — add `frontend/.env.production` with the real API URL (otherwise the whole app is dead in prod).
2. **S3** — rotate the bootstrap admin password; disable/lock down public signup (**S1/S3**).
3. **Deploy config** — `APP_ENV=production`, `APP_DEBUG=false`, real `DB_*`, `FILESYSTEM_DISK=s3` + `AWS_*`, deploy Google `credentials.json`.
4. **Scheduler** — register the Windows Task (or cron) so the bell and sync actually run; resolve the double-`om:sync` overlap (3.6).

**Fix during UAT (or document as manual steps):**
5. **B3 / rental lifecycle** — decide: wire the contract form to flip `operational_status` (and to call `/close` on return), or run reconcile frequently. This is the single biggest operational gap.
6. **B4** — reset `condition_grade` on maintenance close (or add a manual "return to service" step to the UAT playbook).
7. **B2** — harden the Trip Dashboard cold-cache path against an unreachable sheet.
8. **Damage-report UI (scenario 7)** and **inspection-photo capture (6a)** — either wire the frontend or document the workaround.

**Harden opportunistically:**
9. **S2** — enforce `status` in login (reject suspended users).
10. **V1–V5** — add cross-field validation on odometer/date ordering and required FK on contracts (confirm V4 money negatives are intentional first).
11. Restrict `config/cors.php` from `*` to the SPA origin.

---

## 5. Explicitly Out of Scope (Phase 2)

Per the current direction, **none** of the following are part of getting the system live:
- New intelligence tables, engines, or measurement architecture
- Maintenance prediction / foresight logic beyond what already exists
- New migrations

These wait until real employees have used the current system and generated real usage data. This document is the baseline against which Phase 2 ("Transform FleetView into an Intelligence Platform") will be planned.
