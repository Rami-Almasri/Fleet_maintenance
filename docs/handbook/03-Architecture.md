# Architecture & Conventions

How the backend is put together, and how you are expected to add to it.

The authoritative short version is the root document **`ARCHITECTURE-CONVENTIONS.md`** ("How We Build the Backend"). This document expands on it and describes the parts of the system that grew beyond it.

---

## 1. The layers

```
HTTP request
   |
   v
routes/api.php            grouped by prefix, permission-gated by middleware
   |
   v
FormRequest               validation + authorization        (app/Http/Requests, 33 files)
   |
   v
Controller                THIN - no business logic          (app/Http/Controllers, 87 files)
   |
   v
Service                   ALL business logic + persistence  (app/Services, 198 files)
   |
   v
Model                     relationships, scopes, accessors  (app/Models, 110 files)
   |
   v
Resource                  shapes the output JSON            (app/Http/Resources, 33 files)
   |
   v
ResponseHelper            the single response envelope      (app/Helpers)
```

**The rule that matters most: business logic lives in services.** A controller validates, calls one service method, and wraps the result. If you find yourself writing a query or a conditional in a controller, it belongs in a service.

---

## 2. What lives where

### `backend/app/`

| Directory | Files | What it holds |
|---|---|---|
| `Services/` | **198** | **All business logic.** The real map of the system — see [08-Service-Catalog.md](08-Service-Catalog.md). |
| `Models/` | **110** | Eloquent models: relationships, scopes, status constants, derived accessors. |
| `Http/Controllers/` | **87** | Thin HTTP entry points. |
| `Http/Requests/` | 33 | FormRequest validation classes. |
| `Http/Resources/` | 33 | JSON output shaping. |
| `Http/Middleware/` | 2 | `EnsureUserActive`, `RecordUserAction` (the write audit trail). |
| `Console/Commands/` | 96 | Imports, syncs, audits, backfills, rebuilds — see [07-Artisan-Commands.md](07-Artisan-Commands.md). |
| `Ontology/` | 36 | The fault/finding knowledge graph. |
| `Intelligence/` | 17 | Repair-intelligence engines and analysers. |
| `Events/`, `Listeners/`, `Observers/` | 10 / 3 / 3 | Domain events and model hooks. |
| `Contracts/` | 1 | `VehicleExpenseProvider` — the swap-in seam for expense data. |
| `Evidence/`, `Kpi/`, `Support/` | 3 / 2 / 8 | Evidence-layer plumbing, KPI calculators, shared helpers. |
| `Notifications/`, `Policies/`, `Exceptions/` | 1 / 1 / 3 | Includes `WorkflowTransitionException`. |
| `Helpers/` | 1 | `ResponseHelper` — the uniform response envelope. |
| `Providers/` | 3 | `AppServiceProvider`, `EventServiceProvider`, `KnowledgePlatformServiceProvider`. |

### Elsewhere

| Path | What it holds |
|---|---|
| `backend/routes/api.php` | All **493** API routes, one flat file grouped by prefix. |
| `backend/routes/console.php` | The **scheduler** — every recurring job. See [12-Operations-Runbook.md](12-Operations-Runbook.md). |
| `backend/config/` | **44 config files**, most of them project-specific. See [13-Configuration.md](13-Configuration.md). |
| `backend/database/migrations/` | 287 migrations. |
| `frontend/src/` | React app — see [09-Frontend-Inventory.md](09-Frontend-Inventory.md). |

---

## 3. The patterns you will meet

### Thin controller, fat service

```php
class OdooExportController extends Controller
{
    public function __construct(private OdooExportService $export) {}

    public function ticket(Maintenance $ticket)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->export->forTicket($ticket),
                'Odoo export payload ready (ticket)',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
```

That is the whole shape: constructor-inject the service, call one method, wrap, catch. Copy it.

### The uniform response envelope

Every endpoint returns through `ResponseHelper`. Success and error shapes are identical across all 493 endpoints, and `ResponseHelper::fromException($e)` is the standard catch. A global exception handler in `bootstrap/app.php` is the backstop.

### Permission-gated route groups

```php
Route::middleware(['auth:sanctum', 'permission:maintenance.manage'])
    ->prefix('odoo-export')
    ->group(function () {
        Route::get('/ticket/{ticket}', [OdooExportController::class, 'ticket']);
    });
```

Permissions are `resource.action` strings enforced by `spatie/laravel-permission` middleware. A `super-admin` bypasses all of them. Full matrix in [10-Business-Rules.md](10-Business-Rules.md).

### Route-model binding

`{ticket}`, `{contract}`, `{vehicle}` resolve to models before your controller runs, so a bad id is a 404 you never have to write.

### Guarded state machines

The maintenance lifecycle is not a status column you set. It is a transition table with guards. An illegal move throws `WorkflowTransitionException`, which the API maps to **422**. Every transition stamps who and when, and fires the notification to the next role. See document 10.

### Seams for data sources that will change

Where a data source is known to be temporary, the code depends on an **interface**, not the source. `App\Contracts\VehicleExpenseProvider` is the example: today an Excel implementation, tomorrow an Odoo one, and nothing downstream changes. If you are about to hard-wire a data source that might move, add a contract instead.

### Choke points

Several subsystems declare a **single write path** and enforce it. `ComponentService` is "THE single write choke point for `vehicle_components` / `component_events`". Money has one too, guarded by `NoFinancialBypassTest`. Do not add a second door.

### Documentation lives in docblocks

Service classes carry long docblocks explaining *why* — the constraint that forced the design, the alternative that was rejected, the trap it avoids. These are the most reliable documentation in the project. **Write them when you add a service, and read them before you change one.**

---

## 4. Adding a new endpoint — the checklist

From `ARCHITECTURE-CONVENTIONS.md`, expanded:

1. **Route** in `routes/api.php`, inside a prefix group, with `auth:sanctum` and the right `permission:` middleware.
2. **FormRequest** for validation and authorization — not inline `$request->validate()`.
3. **Controller method** — thin. Inject the service, call one method, wrap in `ResponseHelper`, catch `\Throwable`.
4. **Service method** — all logic and persistence here. Add a docblock saying *why*, not just *what*.
5. **Resource** if the output is a model or collection.
6. **Permission** — if you introduce a new one, it must be seeded and assigned to roles, or nobody can call your endpoint.
7. **Frontend** — any new UI string goes through the `tf()` / `tp()` i18n helpers. Run `npm run check` before committing.
8. **Tests** — especially if you touched money, the workflow, or anything with a declared single write path.

---

## 5. Cross-cutting concerns

| Concern | How it works |
|---|---|
| **Auth** | Sanctum bearer tokens. `EnsureUserActive` middleware blocks deactivated users. |
| **Authorization** | spatie permissions, `resource.action`, enforced in route middleware. |
| **Audit** | `RecordUserAction` middleware writes to `user_activity_events`. Domain events go to `domain_events`. |
| **Caching** | Dashboard aggregates are cached; the cache store is configured in `.env`. |
| **Notifications** | Generated by workflow transitions, never typed by a user. Alerts route to the next role automatically. |
| **Feature flags** | `config/features.php` plus env — e.g. `SHOW_FINANCIALS` gates all money UI; `ASSET_LAYER_MODE` and `EVENT_KIND_MODE` gate subsystem rollouts. |
| **i18n** | English + Arabic. `tf()` / `tp()` helpers, phrase files in `frontend/src/i18n/`. Enforced by `npm run check:i18n`. Rollout is incomplete. |
| **Soft deletes** | Used on several models including `Maintenance`. **Raw SQL bypasses them** — see document 15. |

---

## 6. Frontend architecture

- **Create React App** (`react-scripts`), React 19, React Router 7, Tailwind 3, Axios.
- `src/App.js` holds the route table — the fastest map of the UI.
- `src/config/moduleRegistry.js` drives the App Launcher / module tiles.
- Design system: an internal **Cockpit+** system with an "Aurora" command-center theme, rolled out page by page. Not every page has been converted.
- **Filtering must happen server-side.** A recurring bug class here: lanes and filters implemented client-side over a *paginated* feed only filter the loaded page, so a busy feed makes a lane report "empty" when it is not. This has shipped and been fixed at least once.

---

## 7. Where the architecture is inconsistent

Being honest about this saves you time:

- **Not every page follows Cockpit+.** The design-system rollout is partial.
- **i18n coverage is partial** — roughly 200 files still hold English-only strings.
- **Some documented features were retired but their services still run** (several intelligence surfaces). A route existing does not prove a feature is live, and a page being deleted does not prove its service stopped.
- **The intelligence layer is partly ungrounded** — seeded rather than learned data, hardcoded confidence values. Treat its outputs as provisional. See document 01 §10 and document 15.
- **The scheduler is not fully deployed** in production, so some imports that look automated are not running. See document 12.
