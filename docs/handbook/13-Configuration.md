# Configuration Reference

Every environment variable and every config file.

---

## Part 1 — Environment variables

Two `.env` files exist in production: a root one for Docker infrastructure and `backend/.env` for the application. See [12-Operations-Runbook.md](12-Operations-Runbook.md).

**Nothing sensitive is committed.** `backend/.env.example` documents the shape; `secrets/README.md` covers credential handling.

### Application

| Variable | Purpose |
|---|---|
| `APP_NAME` | Display name |
| `APP_ENV` | `local` / `production` |
| `APP_KEY` | Encryption key. Generated **once** with `php artisan key:generate`, then persists. |
| `APP_DEBUG` | **Must be `false` in production.** |
| `APP_URL` | Backend base URL |
| `APP_FRONTEND_URL` | SPA URL — used for links in notifications |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | The app is bilingual EN/AR |
| `APP_MAINTENANCE_DRIVER` | Laravel maintenance mode |
| `BCRYPT_ROUNDS` | Password hashing cost |

### Database

| Variable | Purpose |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Connection. **In Docker these are injected by compose from the root `.env` `MYSQL_*` values — do not set them in `backend/.env`.** |

Local development database is named `laravel`. Test databases: `laravel_test`, `fleet_test` (both with caveats — see [15-Known-Issues.md](15-Known-Issues.md)).

### Sessions, cache, queue, broadcasting

`SESSION_DRIVER` · `SESSION_LIFETIME` · `SESSION_ENCRYPT` · `SESSION_PATH` · `SESSION_DOMAIN` · `CACHE_STORE` · `QUEUE_CONNECTION` · `BROADCAST_CONNECTION` · `MEMCACHED_HOST` · `REDIS_CLIENT` · `REDIS_HOST` · `REDIS_PASSWORD` · `REDIS_PORT`

### Logging

`LOG_CHANNEL` · `LOG_STACK` · `LOG_DEPRECATIONS_CHANNEL` · `LOG_LEVEL` — production uses `LOG_LEVEL=warning`, `LOG_STACK=daily`.

### Mail & notifications

`MAIL_MAILER` · `MAIL_SCHEME` · `MAIL_HOST` · `MAIL_PORT` · `MAIL_USERNAME` · `MAIL_PASSWORD` · `MAIL_FROM_ADDRESS` · `MAIL_FROM_NAME` · `NOTIFY_MAIL_ENABLED`

### Filesystem / media

`FILESYSTEM_DISK` (`local`, or `s3` to offload media) · `AWS_ACCESS_KEY_ID` · `AWS_SECRET_ACCESS_KEY` · `AWS_DEFAULT_REGION` · `AWS_BUCKET` · `AWS_USE_PATH_STYLE_ENDPOINT`

### OfficeManager — **required**

| Variable | Purpose |
|---|---|
| **`OFFICEMANAGER_API_KEY`** | **Required.** `OfficeManagerClient` throws on construction without it. |
| `OFFICEMANAGER_BASE_URL` | API base |
| `OFFICEMANAGER_WEB_SYNC_ENABLED` | Set `false` in production — sync runs from the scheduler/runner, not from web requests |

Further tuning lives in `config/officemanager.php`: `page_size`, `timeout`, `connect_timeout`, `retries`, `retry_sleep_ms`, and the interactive variants `interactive_timeout` / `interactive_connect_timeout`. See [11-Integrations.md](11-Integrations.md) for why there are two profiles.

### Google Sheets

`GOOGLE_SHEETS_CREDENTIALS` (service-account key path) plus per-sheet ids and tab gids:

`GOOGLE_SHEETS_MAINTENANCE_ID` · `GOOGLE_SHEETS_CARS_ID` / `_GID` / `_HEADER_ROW` · `GOOGLE_SHEETS_CONTRACTS_ID` / `_GID` · `GOOGLE_SHEETS_CUSTOMERS_ID` / `_GID` · `GOOGLE_SHEETS_REGISTRATIONS_ID` / `_GID` · `GOOGLE_SHEETS_INSURANCE_ID` / `_GID` · `GOOGLE_SHEETS_OIL_CHANGE_GID` · `GOOGLE_SHEETS_ASSET_ID` / `_GID` · `GOOGLE_SHEETS_EVENTS_ID` / `_TAB`

### Power BI

`POWERBI_ENABLED` · `POWERBI_CLIENT_ID` · `POWERBI_CLIENT_SECRET` · `POWERBI_TENANT_ID` · `POWERBI_WORKSPACE_ID` · `POWERBI_DATASET_ID`

⚠️ Power BI is **not** a permitted source of any expense figure.

### AI keyword enrichment

`ANTHROPIC_API_KEY` · `KEYWORD_AI_MODEL` · `KEYWORD_AI_EFFORT` — optional. The shipped ontology is seeded, so the system runs without a key.

### Oil projection

`OIL_PROJECTION_USER_IDS` · `OIL_PROJECTION_RATE_KM` · `OIL_PROJECTION_GRACE_KM` · `OIL_PROJECTION_ALLOWANCE_KM`

### Feature flags / rollout switches

| Variable | Gates |
|---|---|
| `SHOW_FINANCIALS` | All money UI |
| `ASSET_LAYER_MODE` | Asset-layer rollout stage |
| `EVENT_KIND_MODE` | Event-classification rollout stage |

### Frontend

`VITE_APP_NAME` — note the frontend is actually Create React App; this key is vestigial.

---

## Part 2 — Config files

**44 files in `backend/config/`.** Most are project-specific: this application keeps its domain vocabularies and tuning knobs in config rather than hardcoded in services, which is why there are so many.

### Laravel framework defaults

`app` · `auth` · `broadcasting` · `cache` · `database` · `filesystems` · `logging` · `mail` · `queue` · `session` · `services` · `permission` (spatie) · `sanctum` · `cors` · `media-library`

`cors` is **not** default — the framework allows every origin; this file restricts it.

### Domain vocabularies — the canonical menus

These files are the authoritative lists the application works from. Changing them changes what the business can say.

| File | Holds |
|---|---|
| `fault_catalog` | The canonical menu of unplanned **failures / defects** (`kind = fault`) |
| `service_catalog` | The canonical menu of **planned / preventive** work (`kind = service`) |
| `damage_catalog` | The authoritative list of "something was **done to** this car" |
| `inspection_types` | The canonical menu of **checks** (`kind = inspection`) |
| `component_catalog` | The seed for the fleet's **parts vocabulary** |
| `maintenance_findings` | The central **Findings** library for the workflow |
| `vehicle_locations` | **The one answer to "where on the car is it?"** |
| `sheet_label_kinds` | Which legacy sheet labels describe a service, a context, or a fault |
| `fault_causes` | Seed for the Symptom → Root-Cause knowledge base |

The service / fault / damage split is a hard boundary in this codebase. Do not blur it.

### Engine tuning surfaces

| File | Tunes |
|---|---|
| `garage_recommendation` | `GarageRecommendationService` |
| `garage_routing` | The Smart Routing Engine |
| `garage_scorecard` | Garage performance scoring |
| `repair_intelligence` | Repair intelligence / ETA prediction |
| `parts_intelligence` | Parts classifier, duplicate and recurrence detection |
| `fault_extraction` | Conservative free-text → fault-category extraction |
| `knowledge` | Fleet Knowledge Engine |
| `knowledge_platform` | Knowledge Platform wiring |
| `keyword_ai` | The automotive ontology engine |
| `severity_impact` | Explainability copy for the Diagnostic Review / QC page |

### Integration and domain settings

| File | Holds |
|---|---|
| `officemanager` | OM API base, key, paging, both timeout profiles |
| `google` | Sheets credentials and ids |
| `expenses` | Expense source label and classification settings |
| `vehicle_status` | The workbook + tab holding the per-car Status column |
| `maintenance` | Maintenance workflow settings |
| `maintenance_handover` | Thresholds and vocabulary for the pause/resume custody handover |
| `fleet` | Fleet-wide settings |
| `parts` | Parts settings |
| `depreciation` | Depreciation settings |
| `features` | Feature flags |
| `schema` | Schema health / drift checking |

---

## Part 3 — Where to change what

| You want to... | Change |
|---|---|
| Add a fault type users can select | `config/fault_catalog.php` |
| Add a location on the car | `config/vehicle_locations.php` |
| Change how a garage is scored | `config/garage_scorecard.php` |
| Change expense categorisation | `ExpenseCategoryClassifier` rules, then re-run `expenses:classify` |
| Turn money UI on/off | `SHOW_FINANCIALS` |
| Make OM calls fail faster in the UI | `officemanager.interactive_*`, and pass `interactive: true` |
| Point at a different sheet | The matching `GOOGLE_SHEETS_*_ID` / `_GID` |

**Rule of thumb: if it is a business vocabulary or a tuning number, it belongs in `config/`, not in a service.** That is why there are 44 files.
