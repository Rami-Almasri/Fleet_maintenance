# FleetView Documentation — Index

You have been sent this folder because you are joining or integrating with **FleetView**, the fleet operations platform for Faster Cars.

There are 84 documents here. **Do not read them in order.** Read two, then use this index to find the rest as you need them.

---

## Read these two first

| Document | What it gives you |
|---|---|
| **[FleetView-System-Overview.md](FleetView-System-Overview.md)** | The whole system in one document — what it does, the stack, all 110 models grouped by domain, the maintenance state machine, the financial rules, the known traps. **Start here.** |
| **[../ARCHITECTURE-CONVENTIONS.md](../ARCHITECTURE-CONVENTIONS.md)** | Coding conventions and layering rules. Read before writing any PHP in this repo. |

If you are working on **Odoo integration specifically**, read [Odoo-Integration-Handoff.md](Odoo-Integration-Handoff.md) third.

---

## Then, by topic

### Setting up and deploying
- `../backend/README.md`, `../frontend/README.md` — local setup
- `../DEPLOYMENT.md` — production deploy, server layout, permissions
- `../secrets/README.md` — credential handling
- `issues/migrations-cannot-build-fresh-database.md` — ⚠️ a fresh deploy from an empty database currently fails
- `FleetView-Production-Readiness-Audit.md`, `QA-System-Validation-2026-08-01.md`

### The maintenance workflow (the core of the system)
- `Maintenance-Workflow-Audit.md` — the most complete account
- `Maintenance-Workflow-UseCases.md` — the use cases the state machine implements
- `Maintenance-Deletion-Model.md`
- `Rev6-Vulnerability-Analysis.md`

### Money, cost and invoicing
- `Cost-Source-of-Truth.md` — which cost number is authoritative
- `Financial-Source-of-Truth.md` — the as-built financial picture
- `maintenance-financial-architecture.md`
- `Financial-Workflow-Architecture.md`, `Financial-Layer-Redesign.md`
- `Vehicle-Expense-Provider.md` — the single seam all expense data flows through
- `Invoice-Precision-Drift.md` — ⚠️ decimal precision problems
- `Invoice-As-Financial-Source-of-Truth.md` — a **design**, not fully-built code

### Schema and API
- `Backend-Review-Schema-API-Architecture.md`

### Parts, procurement, warranty
- `Parts-Purchase-Repair-Intelligence-Design.md`
- `Parts-Warranty-Architecture.md`
- `Maintenance-Procurement-Platform-Architecture.md`

### Faults, services and the domain boundary
- `Service-vs-Fault-Domain-Separation.md`, `Service-Fault-Damage-Domain.md`, `Service-Fault-Separation-Audit.md`, `Service-vs-Fault-Implementation-Plan.md`

### Inspections and reminders
- `inspections-reminders.md`

### Data pipeline and sync
- `data-pipeline-roadmap.md` — ⚠️ read before refactoring any odometer or cost writer

### External integration
- `Odoo-Integration-Handoff.md` — the Odoo surface: export payload, expense seam, connection facts, open blockers

### For non-developers / understanding intent
- `FleetView-Employee-Operations-Manual.md` — how staff actually use the system
- `Demo-Live-Walkthrough-AR.md`, `Demo-Meeting-Script-AR.md` — Arabic walkthroughs
- `Maintenance-Workflow-UseCases-AR.html`

### The intelligence layer
Read the **evaluation before the architecture**, so you know which parts are real. Internal audits found significant portions ungrounded (seeded rather than learned data, hardcoded confidence, weak backtest lift).
- `Automotive-Knowledge-Platform-Evaluation.md` ← start here
- `Intelligence-Platform-As-Built.md`
- `Repair-Intelligence-Architecture.md`, `Fleet-Knowledge-Engine-Architecture.md`, `Fleet-Knowledge-Engine-P0-Plan.md`, `Fleet-Knowledge-Engine-Discovery-Log.md`
- `Maintenance-Intelligence-Capability-Design.md`, `Maintenance-Intelligence-Product-Blueprint.md`, `Maintenance-Intelligence-Success-Framework.md`
- `Historical-Maintenance-Knowledge-Opportunities.md`, `Automotive-Intelligence-Platform-Roadmap.md`
- `Vehicle-Intelligence-Architecture.md`, `Operational-Intelligence-Layer-Spec.md`, `Evidence-Artifacts.md`
- Recurrence metric work: `Metric-Specification-Recurrence.md`, `Recurrence-Metric-Convergence.md`, `Recurrence-Convergence-Matrix.md`, `Recurrence-Convergence-Implementation-Plan.md`, `Recurrence-Convergence-Definition-of-Done.md`
- Concept Bridge (the human-labelled gold set): `Concept-Bridge-Coverage-Report.md`, `Concept-Bridge-Dry-Run.md`, `Concept-Bridge-Error-Analysis.md`, `gold-set/README.md`, `gold-set/LABELLING-PROTOCOL.md`
- `Intelligence-Rebuild-Operations.md`, `Fleet-Maintenance-Intelligence-Study.md`

### The asset layer (a self-contained subsystem)
- `Asset-Layer-Architecture.md`, `Asset-Layer-Database-Design.md`, `Asset-Layer-Implementation-Plan.md`, `Asset-Layer-Test-Strategy.md`, `Asset-Layer-Code-Audit.md`, `Asset-Layer-PreMerge-Review.md`, `Asset-Layer-Shadow-Launch-Plan.md`, `Asset-Layer-Shadow-Log.md`, `Asset-Layer-Phase2-Workflow-Design.md`, `Asset-Layer-Phase2-Final-Review.md`, `Asset-Layer-Final-Engineering-Review.md`, `FleetView-Asset-Layer-Handoff.md`

### Roadmaps and plans
⚠️ These are **aspirational**. Check them against the code before relying on them — several describe features that were later changed, retired, or never built.
- `Implementation-Backlog.md` — the canonical plan
- `Phase1-Implementation-Plan.md`
- `Fleet-Intelligence-Execution-Plan.md`, `Fleet-Intelligence-Engineering-Blueprint.md`, `Fleet-Intelligence-Implementation-Roadmap.md`, `Fleet-Intelligence-UX-Specification.md`
- `Integrated-Fleet-Lifecycle-Plan.md`

---

## Two standing warnings

1. **Docblocks beat documents.** Many classes in `backend/app/Services/` carry long comments explaining *why* the code is the way it is. Those are usually more current than anything in this folder. When a document and the code disagree, the code is right.

2. **A document describing a feature does not prove the feature exists, works, or is trusted.** Several documents here describe designs that were partially built, later reversed, or retired. The System Overview flags the main cases.
