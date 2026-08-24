# Artisan Commands Reference

Every project-specific console command, generated from `php artisan list`. Laravel's own built-in commands (`make:*`, `migrate`, `queue:*`, ...) are excluded.

Run any of them from the `backend/` directory:

```bash
php artisan <command> [options]
php artisan help <command>     # full option list for one command
```

> **Before running anything that writes:** take a backup with `php artisan db:backup`, and read the warning about rehearsals in `12-Operations-Runbook.md`. A "dry run" against the live database has committed real records in this project before.

Total: **96 project commands**.

## Index by namespace

| Namespace | Commands |
|---|---|
| [`(no namespace)`](#nonamespace) | 4 |
| [`checkpoints`](#checkpoints) | 1 |
| [`complaints`](#complaints) | 1 |
| [`components`](#components) | 3 |
| [`conceptbridge`](#conceptbridge) | 1 |
| [`contracts`](#contracts) | 2 |
| [`cost`](#cost) | 2 |
| [`customers`](#customers) | 1 |
| [`expenses`](#expenses) | 2 |
| [`faults`](#faults) | 1 |
| [`findings`](#findings) | 1 |
| [`fleet`](#fleet) | 3 |
| [`garages`](#garages) | 1 |
| [`gate`](#gate) | 1 |
| [`import`](#import) | 5 |
| [`inspections`](#inspections) | 2 |
| [`intelligence`](#intelligence) | 12 |
| [`invoices`](#invoices) | 1 |
| [`knowledge`](#knowledge) | 1 |
| [`kpi`](#kpi) | 1 |
| [`maintenance`](#maintenance) | 9 |
| [`media-library`](#medialibrary) | 3 |
| [`mileage`](#mileage) | 1 |
| [`oil`](#oil) | 2 |
| [`om`](#om) | 1 |
| [`ontology`](#ontology) | 7 |
| [`parts`](#parts) | 3 |
| [`permission`](#permission) | 6 |
| [`plate`](#plate) | 4 |
| [`repair-intel`](#repairintel) | 1 |
| [`review-reminders`](#reviewreminders) | 1 |
| [`service`](#service) | 2 |
| [`sheets`](#sheets) | 1 |
| [`sync`](#sync) | 7 |
| [`trips`](#trips) | 1 |
| [`user`](#user) | 1 |

---

## (no namespace)

| Command | What it does |
|---|---|
| `_complete` | Internal command to provide shell completion suggestions |
| `invoke-serialized-closure` | Invoke the given serialized closure |
| `pail` | Tails the application logs |
| `reload` | Reload running services |

## checkpoints

| Command | What it does |
|---|---|
| `checkpoints:scan` | Chase workshop progress updates: notify responsible users before a car goes overdue |

## complaints

| Command | What it does |
|---|---|
| `complaints:migrate-legacy` | Backfill legacy customer_reported maintenance tickets into the first-class Complaint entity |

## components

| Command | What it does |
|---|---|
| `components:demo-fit` | Generate (or remove) demo installed-component history for specific vehicles |
| `components:shadow-audit` | Audit shadow-mode component writes against the integrity checks; optionally promote clean rows or quarantine bad ones. |
| `components:verify` | Replay component_events and verify the stored installed configuration matches |

## conceptbridge

| Command | What it does |
|---|---|
| `conceptbridge:import` | Import Concept Bridge benchmark samples (and optionally the AI baseline) from CSV |

## contracts

| Command | What it does |
|---|---|
| `contracts:reconcile-discounts` | Backfill contract_discount from the invoice discount sum where OM left the contract rollup empty. |
| `contracts:validate` | Read-only data-quality check: flag "zombie" contracts (open, no return after N days). |

## cost

| Command | What it does |
|---|---|
| `cost:traceability-snapshot` | Measure verified vs legacy vs unverified cost across the fleet and record it |
| `cost:trace-audit` | Verify every ticket cost traces to a source document (invoice, credit note or adjustment) |

## customers

| Command | What it does |
|---|---|
| `customers:recalc` | Reconcile every customer's cached debit/credit/balance from their contracts. |

## expenses

| Command | What it does |
|---|---|
| `expenses:classify` | Re-derive the operational category of every expense line from its remark |
| `expenses:import` | Import the vehicle-expense sheet (the sole source of vehicle expense) into vehicle_expenses |

## faults

| Command | What it does |
|---|---|
| `faults:backfill-attempt-labor` | Copy legacy findings-JSON repair_hours onto each fault's latest attempt stint (fill-if-null, idempotent) |

## findings

| Command | What it does |
|---|---|
| `findings:vocabulary-check` | Verify the findings catalog and the fault ontology share one vocabulary |

## fleet

| Command | What it does |
|---|---|
| `fleet:check-expiry` | Alert on cars still in the rentable pool whose registration or insurance has expired |
| `fleet:reconcile-status` | Re-derive every car's operational_status (rented / in maintenance / available) from its currently-open contracts. |
| `fleet:refresh` | Fresh full sync: sheets + OfficeManager API in the right order (status from the API). Backs up first. |

## garages

| Command | What it does |
|---|---|
| `garages:sync` | Import garages & used-parts shops from the garages sheet into vendors |

## gate

| Command | What it does |
|---|---|
| `gate:demo` | Arm (or reset) an overdue-oil condition on a car to test the Proactive Diagnostic Monitor |

## import

| Command | What it does |
|---|---|
| `import:customer-cases` | Import the Customer Cases sheet tab into the maintenances table (origin=customer-sheet) |
| `import:garage-locations` | Import the N-Location sheet tab (Car / Garage) into vehicle_garage_locations |
| `import:maintenance-reasons` | Import the maintenance reason->status vocabulary from the "Main reason" sheet tab |
| `import:maintenance-sheet` | Import the N-Maintenance & Repair sheet log into the maintenances table (standalone rows, origin=sheet) |
| `import:vehicle-status` | Overlay vehicle status from the fleet Status sheet (Sold/Personal/Office/For-sale); Active cars keep the live om:sync status. Runs last, after the sync. |

## inspections

| Command | What it does |
|---|---|
| `inspections:clean-suggested-findings` | Strip post-downtime safety-checklist chips (not actually due) from the suggested_findings snapshot of open inspection tickets, keeping every data-driven suggestion |
| `inspections:generate-tasks` | Proactively raise "Needs Test Drive" requests for cars due for a routine/check-up (oil, tyres, battery, long idle), skipping implausible odometer readings |

## intelligence

| Command | What it does |
|---|---|
| `intelligence:backtest-recurrence` | Score the repeat-fault predictor against what actually happened: base rate, the live Due-now rule, and whether a calibrated probability is earnable (Brier + reliability) |
| `intelligence:convergence-audit` | Prove every reporting surface reads the canonical recurrence definition |
| `intelligence:decision-learning` | Recommendation acceptance, override reasons, and where the weights disagree with the operation |
| `intelligence:evidence-health` | Evidence readiness KPI for every intelligence capability, and the promotion gate |
| `intelligence:forecast-calibration` | Compare predicted repair outcomes against reality and report calibration |
| `intelligence:rebuild-health` | Report freshness of the derived intelligence tables (recurrence, visits) |
| `intelligence:rebuild-recurrence` | Rebuild fault_recurrence_pairs (deduplicated fault events + when each fault next returned) |
| `intelligence:rebuild-signatures` | Rebuild the canonical repair-signature projection from maintenance history |
| `intelligence:rebuild-visits` | Rebuild the repair_visits table (collapses maintenance events into real garage visits) |
| `intelligence:record-outcomes` | Judge past recommendations against what actually happened (closes the learning loop) |
| `intelligence:recurrence-compare` | Compare the legacy raw-signature recurrence with the canonical deduplicated metric, per garage |
| `intelligence:validate-classifier` | Measure the repair-signature classifier against human service_main labels |

## invoices

| Command | What it does |
|---|---|
| `invoices:scan-overdue` | Flag maintenance tickets whose invoice is overdue (awaiting_invoice > SLA days) |

## knowledge

| Command | What it does |
|---|---|
| `knowledge:ingest` | Ingest a technical document into the knowledge corpus for grounded enrichment |

## kpi

| Command | What it does |
|---|---|
| `kpi:snapshot` | Report the operational KPIs, with sample sizes and what is not yet measurable |

## maintenance

| Command | What it does |
|---|---|
| `maintenance:backfill-tasks` | Explode historical findings JSON into maintenance_tasks (+ stints, + cost re-attribution) |
| `maintenance:fault-extraction-audit` | Audit the free-text fault-category extractor on live maintenance history |
| `maintenance:link-reasons` | Categorize maintenance rows against the Maintenance Reason vocabulary (MAIN column, SUP exact refine) |
| `maintenance:relink-history` | Re-link historical maintenance rows to the correct vehicle via the plate_assignments timeline (vehicle_id only; additive/reversible report) |
| `maintenance:reload` | Wipe ONLY the maintenances table and re-import it exactly from the sheet (backup-gated). |
| `maintenance:reset-workflow` | Wipe maintenance-workflow tickets and return their cars to normal status (test reset) |
| `maintenance:route-suggest` | Dry-run the garage routing engine for a ticket and show the scoring breakdown |
| `maintenance:seed-workflow` | Seed representative maintenance-workflow tickets across every lifecycle stage (test data) |
| `maintenance:snapshot-ticket` | Snapshot a maintenance ticket to JSON and restore it verbatim later (real-state safety net) |

## media-library

| Command | What it does |
|---|---|
| `media-library:clean` | Clean deprecated conversions and files without related model. |
| `media-library:clear` | Delete all items in a media collection. |
| `media-library:regenerate` | Regenerate the derived images of media |

## mileage

| Command | What it does |
|---|---|
| `mileage:scan` | Anchor each car to its earliest contract reading, validate the mileage chain, and heal the odometer from history. |

## oil

| Command | What it does |
|---|---|
| `oil:chase-returns` | Remind the fleet to hand back cars whose oil change is done but which are still with us |
| `oil:settle-returns` | Open the owed oil-change ticket for rentals that have come back (decided mid-rental, or simply returned past the limit) |

## om

| Command | What it does |
|---|---|
| `om:sync` | Sync from the OfficeManager API (source of truth): link cars, import contracts/invoices, enrich, etc. |

## ontology

| Command | What it does |
|---|---|
| `ontology:build-graph` | Project the fault vocabulary into a typed knowledge graph |
| `ontology:coverage` | Measure what share of real fleet text the ontology already understands |
| `ontology:duplicates` | Detect duplicate, overlapping or indistinguishable fault concepts |
| `ontology:import-causal-knowledge` | Import the approved fault_causes catalogue into the ontology graph as causal edges |
| `ontology:learn-from-fleet` | Turn our own maintenance history into weighted knowledge-graph edges |
| `ontology:reconcile` | Compare the ontology in the database against the source files and report drift |
| `ontology:weak-matches` | Show which concepts match weakly, and the real wording that fell short |

## parts

| Command | What it does |
|---|---|
| `parts:demo-approval` | Seed (or --clean) part requests that trip the approve-time duplicate warning. |
| `parts:demo-seed` | Seed (or --clean) realistic demo data for the Parts Purchase workflow on the Ford Mustang. |
| `parts:link-required` | Report required-part lines with no catalog reference, and optionally link the unambiguous ones |

## permission

| Command | What it does |
|---|---|
| `permission:assign-role` | Assign a role to a user |
| `permission:cache-reset` | Reset the permission cache |
| `permission:create-permission` | Create a permission |
| `permission:create-role` | Create a role |
| `permission:setup-teams` | Setup the teams feature by generating the associated migration. |
| `permission:show` | Show a table of roles and permissions per guard |

## plate

| Command | What it does |
|---|---|
| `plate:build-history` | Backfill vehicles.plate_key + the plate_assignments timeline (additive, idempotent) |
| `plate:import-codes` | Import the plate-code → letter dictionary (idempotent) |
| `plate:review-ambiguous` | Read-only report of ambiguous reused plates (>1 active car) for manual review before backfill |
| `plate:sync-codes` | Fill vehicles.plate_code from OM PlateColorNo (matched by VIN + plate digits) |

## repair-intel

| Command | What it does |
|---|---|
| `repair-intel:backfill-reasons` | Auditable backfill of maintenance_reason_id on uncategorized rows (Repair Intelligence substrate) |

## review-reminders

| Command | What it does |
|---|---|
| `review-reminders:dispatch` | Push the due "remind me later" reminders set on the Inspection Review Queue |

## service

| Command | What it does |
|---|---|
| `service:check` | Flag vehicles whose service is due (strict km rule: odometer - last service >= interval) |
| `service:sync-reminders` | Auto-derive oil-change reminders from sheet data + seed tire rotation/change reminders fleet-wide |

## sheets

| Command | What it does |
|---|---|
| `sheets:peek` | Peek at a Google Sheet: list its tabs, or print a tab's header row + first rows |

## sync

| Command | What it does |
|---|---|
| `sync:contracts` | Import rental contracts from the rich RA "Contracts" sheet (linked to customers by CustomerNo, cars by VIN). |
| `sync:customers` | Import customer details from the Google Sheet "New OM" tab (matched by CustomerNo). |
| `sync:insurance` | Import insurer + insurance/Mulkiya expiry from the "F Insurance" tab into vehicle_registrations. |
| `sync:oil-change` | Import last-service odometer + service interval from the "Oil Change" tab into vehicles. |
| `sync:registrations` | Import vehicle RTA registration + fines from the "F RTA" tab (linked to cars by VIN). |
| `sync:vehicles` | Import/sync vehicles from the Google Sheet "Faster" master tab (enriched with FASTER Asset prices). |
| `sync:vendors-insurance` | Add the distinct insurance companies from the "F Insurance" tab into the vendors table (type=insurance). |

## trips

| Command | What it does |
|---|---|
| `trips:warm` | Refresh the cached Trip Dashboard payload (Delivery Command / Orders board) |

## user

| Command | What it does |
|---|---|
| `user:role` | Assign a Spatie role to a user by email |

