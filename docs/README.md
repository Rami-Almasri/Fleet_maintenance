# FleetView Documentation

## → Start at [`handbook/00-START-HERE.md`](handbook/00-START-HERE.md)

The **`handbook/`** folder is the complete, current, verified documentation for this project. It was written to be handed to someone who has never seen FleetView before, and it covers everything: the business, the architecture, all 134 database tables, all 493 API endpoints, all 198 services, all 96 console commands, the frontend, the integrations, deployment, and the known problems.

**If you are new here, read the handbook and ignore everything else in this folder.**

| Handbook document | Contents |
|---|---|
| [00-START-HERE](handbook/00-START-HERE.md) | Reading order and the five-minute version |
| [01-System-Overview](handbook/01-System-Overview.md) | The whole system in narrative form |
| [02-Getting-Started](handbook/02-Getting-Started.md) | Local setup |
| [03-Architecture](handbook/03-Architecture.md) | Layering, patterns, conventions |
| [04-Database-Schema](handbook/04-Database-Schema.md) | All 134 tables, generated from the live database |
| [05-API-Reference](handbook/05-API-Reference.md) | All 493 endpoints with permissions |
| [06-Domain-Model](handbook/06-Domain-Model.md) | All 110 models and how they relate |
| [07-Artisan-Commands](handbook/07-Artisan-Commands.md) | All 96 console commands |
| [08-Service-Catalog](handbook/08-Service-Catalog.md) | All 198 services and what each does |
| [09-Frontend-Inventory](handbook/09-Frontend-Inventory.md) | 91 routes, 104 pages, 204 components |
| [10-Business-Rules](handbook/10-Business-Rules.md) | The workflow state machine, financial rules, permissions |
| [11-Integrations](handbook/11-Integrations.md) | OfficeManager, Google Sheets, Odoo, Power BI |
| [12-Operations-Runbook](handbook/12-Operations-Runbook.md) | Deploy, scheduler, backups, troubleshooting |
| [13-Configuration](handbook/13-Configuration.md) | Every env var and config file |
| [14-Glossary](handbook/14-Glossary.md) | Terms, roles, status values |
| [15-Known-Issues](handbook/15-Known-Issues.md) | Real observed failures — read before running anything |
| [16-Odoo-Integration](handbook/16-Odoo-Integration.md) | The Odoo surface, seam and blockers |

Also at the repository root: **`ARCHITECTURE-CONVENTIONS.md`** (read before writing PHP) and **`DEPLOYMENT.md`** (the authoritative deploy procedure).

---

# The archive

Everything below is the **historical design record** — ~70 documents written during development. They are kept because they explain *why* decisions were made, but several describe features that were later changed, retired, or never finished.

> ⚠️ **A document here describing a feature does not prove the feature exists, works, or is trusted.** The handbook flags the known cases. Reliability order: **running code > service docblocks > the handbook > this archive.**

### Maintenance workflow
`Maintenance-Workflow-Audit.md` · `Maintenance-Workflow-UseCases.md` · `Maintenance-Workflow-UseCases-AR.html` · `Maintenance-Deletion-Model.md` · `Rev6-Vulnerability-Analysis.md`

### Money and cost
`Cost-Source-of-Truth.md` · `Financial-Source-of-Truth.md` · `maintenance-financial-architecture.md` · `Financial-Workflow-Architecture.md` · `Financial-Layer-Redesign.md` · `Vehicle-Expense-Provider.md` · `Invoice-Precision-Drift.md` · `Invoice-As-Financial-Source-of-Truth.md` *(design only)*

### Schema and API
`Backend-Review-Schema-API-Architecture.md`

### Faults, services, domain boundaries
`Service-vs-Fault-Domain-Separation.md` · `Service-Fault-Damage-Domain.md` · `Service-Fault-Separation-Audit.md` · `Service-vs-Fault-Implementation-Plan.md`

### Parts, procurement, warranty
`Parts-Purchase-Repair-Intelligence-Design.md` · `Parts-Warranty-Architecture.md` · `Maintenance-Procurement-Platform-Architecture.md`

### Inspections, data pipeline
`inspections-reminders.md` · `data-pipeline-roadmap.md`

### For non-developers
`FleetView-Employee-Operations-Manual.md` — how staff actually use the system; genuinely useful for understanding intent.
`Demo-Live-Walkthrough-AR.md` · `Demo-Meeting-Script-AR.md` — Arabic walkthroughs.

### Intelligence layer
Read the **evaluation before the architecture** — audits found significant portions ungrounded.

`Automotive-Knowledge-Platform-Evaluation.md` *(start here)* · `Intelligence-Platform-As-Built.md` · `Repair-Intelligence-Architecture.md` · `Fleet-Knowledge-Engine-Architecture.md` · `Fleet-Knowledge-Engine-P0-Plan.md` · `Fleet-Knowledge-Engine-Discovery-Log.md` · `Maintenance-Intelligence-Capability-Design.md` · `Maintenance-Intelligence-Product-Blueprint.md` · `Maintenance-Intelligence-Success-Framework.md` · `Historical-Maintenance-Knowledge-Opportunities.md` · `Automotive-Intelligence-Platform-Roadmap.md` · `Vehicle-Intelligence-Architecture.md` · `Operational-Intelligence-Layer-Spec.md` · `Evidence-Artifacts.md` · `Intelligence-Rebuild-Operations.md` · `Fleet-Maintenance-Intelligence-Study.md`

Recurrence metric work: `Metric-Specification-Recurrence.md` · `Recurrence-Metric-Convergence.md` · `Recurrence-Convergence-Matrix.md` · `Recurrence-Convergence-Implementation-Plan.md` · `Recurrence-Convergence-Definition-of-Done.md`

Concept Bridge (the human-labelled gold set): `Concept-Bridge-Coverage-Report.md` · `Concept-Bridge-Dry-Run.md` · `Concept-Bridge-Error-Analysis.md` · `gold-set/README.md` · `gold-set/LABELLING-PROTOCOL.md`

### Asset layer
`Asset-Layer-Architecture.md` · `Asset-Layer-Database-Design.md` · `Asset-Layer-Implementation-Plan.md` · `Asset-Layer-Test-Strategy.md` · `Asset-Layer-Code-Audit.md` · `Asset-Layer-PreMerge-Review.md` · `Asset-Layer-Shadow-Launch-Plan.md` · `Asset-Layer-Shadow-Log.md` · `Asset-Layer-Phase2-Workflow-Design.md` · `Asset-Layer-Phase2-Final-Review.md` · `Asset-Layer-Final-Engineering-Review.md` · `FleetView-Asset-Layer-Handoff.md`

### Roadmaps and plans
⚠️ Aspirational — check against the code before relying on them.

`Implementation-Backlog.md` *(the canonical plan)* · `Phase1-Implementation-Plan.md` · `Fleet-Intelligence-Execution-Plan.md` · `Fleet-Intelligence-Engineering-Blueprint.md` · `Fleet-Intelligence-Implementation-Roadmap.md` · `Fleet-Intelligence-UX-Specification.md` · `Integrated-Fleet-Lifecycle-Plan.md`

### Production readiness
`FleetView-Production-Readiness-Audit.md` · `QA-System-Validation-2026-08-01.md` · `issues/migrations-cannot-build-fresh-database.md`
