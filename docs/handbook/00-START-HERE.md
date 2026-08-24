# FleetView — Complete Project Handbook

**Everything about this project, in one folder.**

This handbook was written to be handed to someone who has never seen FleetView before. It covers the business, the architecture, every database table, every API endpoint, every service class, every console command, the frontend, the integrations, the deployment procedure, and the known problems.

Generated 2026-08-18 against the live codebase and database. Where a number appears (134 tables, 493 endpoints, and so on), it was **counted**, not estimated.

---

## Read in this order

| # | Document | Read it when | Size |
|---|---|---|---|
| **01** | [System Overview](01-System-Overview.md) | **First. Always.** What FleetView is, the business it runs on, the people, the stack, the whole architecture in narrative form. | Long |
| **02** | [Getting Started](02-Getting-Started.md) | You want it running on your machine. | Short |
| **03** | [Architecture & Conventions](03-Architecture.md) | Before you write any code. Layering, patterns, how a request flows. | Medium |
| **06** | [Domain Model](06-Domain-Model.md) | You need to know what the entities are and how they relate. | Medium |
| **10** | [Business Rules & Workflow](10-Business-Rules.md) | **The most important document after 01.** The maintenance state machine, the financial rules, the rules you must not break. | Long |
| **11** | [Integrations](11-Integrations.md) | You touch OfficeManager, Google Sheets, or Odoo. | Medium |
| **12** | [Operations Runbook](12-Operations-Runbook.md) | You deploy, run commands, or debug production. | Medium |
| **15** | [Known Issues & Hazards](15-Known-Issues.md) | **Before you run anything destructive.** Every item is a real observed failure. | Medium |

## Reference material — look things up, don't read cover to cover

| # | Document | Contains |
|---|---|---|
| **04** | [Database Schema](04-Database-Schema.md) | All **134 tables**, every column, type, nullability, default, foreign key and index. Generated from the live database. |
| **05** | [API Reference](05-API-Reference.md) | All **493 endpoints** in **77 groups**, with handler and required permission. Generated from `route:list`. |
| **07** | [Artisan Commands](07-Artisan-Commands.md) | All **96 project console commands**, grouped by namespace. |
| **08** | [Service Catalog](08-Service-Catalog.md) | All **198 service classes** and what each one is for. |
| **09** | [Frontend Inventory](09-Frontend-Inventory.md) | All **104 pages** and **204 components**, plus how the frontend is organised. |
| **13** | [Configuration Reference](13-Configuration.md) | Every environment variable and every config file. |
| **14** | [Glossary](14-Glossary.md) | Terms, roles, status values, acronyms. Look here when a word doesn't make sense. |
| **16** | [Odoo Integration](16-Odoo-Integration.md) | The existing Odoo export surface, the expense-provider seam, connection facts, open blockers. |

---

## The five-minute version

If you read nothing else, read this.

**What it is.** FleetView is the fleet operations platform for **Faster Cars**, a car-rental company in the UAE. It owns the *physical and operational* life of each vehicle: condition, faults, which garage is repairing it, what that cost, who moved it where, and whether it can be rented.

**What it is not.** It is not a booking system and not an accounting system. **OfficeManager** (external) owns rental contracts and customers. The master list of which cars exist is the fleet's own **"Faster" sheet tab** (since 2026-08-19) — OfficeManager refreshes those cars but never adds one. **Odoo** (external) owns accounting. FleetView is the operational layer between them.

**What it replaced.** A WhatsApp group. Inspectors, drivers, controllers and garages used to coordinate repairs by message, so every state lived in someone's head. FleetView turns that into a guarded server-side state machine where each transition records who did it and when, and automatically notifies whoever is next.

**How it is built.** Laravel 12 (PHP 8.2) API + React 19 SPA, MySQL 8. 110 models, 198 services, 87 controllers, 493 API endpoints, 287 migrations, 96 console commands, 104 frontend pages.

**Where the logic is.** `backend/app/Services/`. Controllers are thin — validate, call one service, wrap the response. **Class docblocks in the service layer are the best documentation in this project**; many run 20+ lines explaining *why*. When a design document and a docblock disagree, the docblock is more current, and the code is more current still.

**The centre of the system.** `MaintenanceWorkflowService` plus the `Maintenance::WF_*` constants — the maintenance ticket lifecycle. Understand that and you understand FleetView. It is explained in document 10.

**The rules that matter most.**
1. Never fabricate a number. Unknown is `null`, never `0`.
2. One source of truth per fact, declared explicitly.
3. One write path per entity — there is a test that enforces this for money.
4. Nothing is dropped silently; if a total excludes something, the UI must be able to say what and why.
5. Every page shows its data origin. No black boxes.

**Before running anything destructive:** read document 15. A "dry run" against the live database in this project once committed 56 real records.

---

## What is NOT in this handbook

- **Credentials.** Nothing sensitive is committed. See `backend/.env.example` for the shape and `secrets/README.md` for how credentials are handled.
- **The older design documents.** The parent `docs/` folder holds ~70 further documents: original design specs, audits, roadmaps and evaluations. They are historical and some describe features that were later changed or retired. `docs/README.md` indexes them. **This handbook is the current, verified account; those are the archive.**
- **Vendor code.** `node_modules/` and `vendor/` are excluded.

---

## A standing warning about documentation in this project

FleetView was built quickly and evolved fast. Several documents in the parent `docs/` folder describe features that were **partially built, later reversed, or retired**. This handbook flags the cases known at the time of writing.

The reliability order is: **running code > service docblocks > this handbook > the archive in `docs/`.**

If you find something here that the code contradicts, the code wins — please correct the file.
