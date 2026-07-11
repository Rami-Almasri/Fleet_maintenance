# Integrated Fleet Lifecycle — Implementation Plan (Rev. 16 — sign-off ready)

**For:** Omar (sign-off) · **From:** Engineering · **Date:** 2026-07-04
**Basis:** A locked-down, start-to-finish rental process — the Omar Protocol reconciled with the Rental-First strategy.

> **Rev. 16 — Lean cleanup & testing logic.** Trimmed to the core — **data-driven garage selection** + **odometer / damage integrity**. **Removed:** the automated **Stability Cycle Engine** (5-day cycle · 1-hour Safety Block · 3-cycle graduation — never shipped) and the **+5% mileage buffer**. **Added (new Rev. 16 section):** **Baseline Testing** (each Test vs. the last Test's history), a **Preventive Testing Policy** — smart-trigger, not check-everything: a **15-day Periodic Safety Refresh** (even idle cars), a **Deep Inspection after any 20+ day rental**, a **7-day anti-redundancy buffer**, and **Ready-State Retention** — plus **Labor & Time Tracking** (every Test/repair time-stamped + cost-accounted → per-garage efficiency), and **Data-Driven Garage Selection** (price a primary rank, backed by Cost-Intelligence). **Rev. 15 — Roles & the Check/Test split; Yellow policy reconciled.** Terminology corrected to match operations: **Abdullah & Waleed are Supervisors** (they *review & approve* damage — not company managers), and **Abu Marouf is the Technical Inspector & Tester** (*execution only* — he tests & verifies, he does not decide liability / cost / scope). A clean **Check vs. Test** split: a **Check** (visual / damage, on client return) is a **Supervisor** decision — a flagged scratch auto-notifies them to review & approve; a **Test** (technical / performance, after maintenance or when a Check flags a mechanical issue) is **Abu Marouf's** execution. **New escalation:** a Check that reveals a *potential mechanical* problem (e.g. impact that may have hit the steering) **auto-escalates to a mechanical Test** for Abu Marouf. Also reconciled here: the **Yellow-grade reversal** — Yellow is **non-rentable, treated like Red** (already shipped in `rentBlockedByCondition()`), correcting the stale Rev. 7 wording. **Rev. 14 — Operational Integrity Gate (the Bulletproof-Lifecycle capstone).** The final hardening: every physical movement of a car leaves a verifiable record captured **at the moment it happens**, not forced at an artificial status change. Three refinements layered onto Phases 3/5/11 — **(IG-1) event-driven odometer capture:** an always-visible *"Add Odometer Reading"* action logs {reading + photo + event_type} at any time; `Mark Ready` becomes a **Final Verification** ("are the pickup/arrival/exit events captured?"), no longer the forced photo point (supersedes 11A's per-transition hard-gate and 11D's Ready-coupling). **(IG-2) Garage Arrival Event:** when the driver physically hands the car to the shop, a dedicated step captures **garage confirm/override** (dispatch = the Supervisor's *plan*; arrival = the *official decision*, so a driver can divert if the shop is full — audited against the plan), **mandatory arrival odometer (photo + reading)**, **intake condition / scratch flag** (→ Phase-2 Damage Assessment), and an **ETA**. **(IG-3) Maintenance Recurrence flag ("Came Back Broken"):** not a stage — a QC trigger that links a same-fault return within a short window back to the original ticket and raises a `maintenance_recurrence` audit flag. **Rev. 13 — Odometer Integrity Gate folded in.** Phase 11 gains **11D · Shop-Movement Integrity Gate** — a garage-side counterpart to the renter odometer hard-gate (11A). The two readings that already bracket a maintenance stint — **garage-in** at arrival and **garage-out** at the *Ready* re-inspection — now yield a **Net Shop Distance**; any run beyond a **configurable threshold (default 20 km)** stamps an **`excessive_shop_movement`** flag into `odometer_flags` and fires an **immediate Admin alert** — proof of whether a shop drove the car beyond a legitimate test-drive or used it for errands. Wired onto the *existing* Ready capture (no new capture point) + one Admin setting (`shop_movement_threshold_km`); non-blocking on close. **Rev. 12 — the ready-to-sign package.** All 12 phases detailed; the Unified Summary and Scenario storyboards are synced to match. Latest sign-off decisions locked: mileage buffer **5%**, odometer Data-Integrity override **Admin-only**, and **Phase 11B (GPS/geo-fence) deferred** until a live telematics feed is connected (build 11A + the 11C dashboard scaffolding now). Rev. 11 added **Usage Monitoring & Mileage Enforcement** (**Phase 11** — mandatory odometer photo + entry at every status change with a hard "Data Integrity Error" block + Admin override on rollback; GPS-vs-odometer distance policing + auto `excess_mileage` tag & Admin alert past allowed +5%; optional geo-fence; a green/yellow/red Usage Dashboard in the Command Center). *The odometer half hardens existing services and ships now; the GPS half needs a telematics feed.* Rev. 10 added **Cost Intelligence** (**Phase 9** — history-driven price prediction + garage cost comparison + variance/High-Cost warning, hooked into Smart-Routing; a `garage_cost_stats` snapshot refreshed at Close from `actual_cost`) and the **Accident-to-Garage Communication Chain** (**Phase 10** — an automated, receipted notification flow: new-damage/accident → high-priority ping to Abdullah & Waleed → Submit-Assessment → auto note-carrying task to Abu Marouf, whose dashboard can't see the ticket until assessed; non-dismissible notifications + `notified_at/viewed_at/actioned_at` receipts). Rev. 9 hardened Phase 6's short-cycle into the **Stability Cycle Engine** — a strict, system-enforced regime for post-accident cars: auto-triggered on Under-Repair→Available, a 5-day `stability_check_ticket` that can't be closed without **3 fresh photos + an explicit Pass**, a **Safety Block** (highest priority) + instant Admin alert if the deadline slips by even 1 hour, auto-graduation after **3 clean cycles (15 days)**, and a permanent **`auto_maintenance_audits`** trail. *(This Stability Cycle Engine was **removed in Rev. 16**.)* Rev. 8 added the **Command Center** transparency layer (**Phase 8**): a real-time Activity Feed (who-did-what), a Vehicle Live-Status tracker (where is the car + its owner/assignee/garage/supervisor), and a Management Overview (Yellow-in-field, pending approvals, stale tasks). Built lean — one thin `activity_log` table for the feed; the tracker + overview are read views over the existing state machine, `livePosition()`, and `last_state_change_at`. Rev. 7 **resolved two open decisions**: (1) **Yellow grade** — *later reversed in Rev. 14*: Yellow is now **non-rentable, treated like Red** → routed to maintenance; only Green & Orange rent (Orange logs a condition ack). See Locked Decisions. (2) **Valuation ≠ Approval** — a manager *valuates* the cost (`valuated_by`), but *approval* is governed by the Approval Matrix (`approved_by`); above the manager's own tier limit the ticket transitions to `pending_approval_hierarchy`. Rev. 6 added the **Safety-Decision Audit** layer (**Phase 7**): the system scans the inspector's description for risk keywords, suggests a severity, and — when the final call contradicts that suggestion (e.g. a high-severity fault marked rentable) — flags the record **Audit Required**, pushes an instant alert to the Admin, and forces a written override reason. Judgment stays with the human; the trail is transparent. Rev. 5 finalizes the **operational roles**: Driver flags a scratch → **notify Abdullah & Waleed** → managers assess / approve parts / assign cost / **confirm** → **auto status In-Repair** + **auto-task to Abu Marouf's queue**. Role-scoped task queues are formalized (each role its own screen). Rev. 4 folds in **Abu Marouf's Inspector Logic**: the **Three Testing Gates** (Pre-Rental / Check-In / Incident), the Check-In scratch-marking → **auto Damage-Evaluation** trigger, the **Incident Support Flow** (phone → on-site → swap), and **Short-Cycle Maintenance** for post-accident cars — a new automated recurring task (**Phase 6**). Rev. 3 had added Phase 2.5 (Customer & Deposit Protection); Rev. 2 had pulled documentation, signature & damage evaluation forward. HTML copies (EN + AR) accompany this file: `Integrated-Fleet-Lifecycle-Plan.html`, `Integrated-Fleet-Lifecycle-Plan-AR.html`.

## Locked decisions carried into this plan
1. **Rental-First stands** — a booking is blocked by a **Red *or* Yellow grade**, or an **open safety-critical fault**. **Only Green & Orange rent** (Orange logs a recorded acknowledgment). **(Rev. 14 — reverses the Rev. 7 decision):** **Yellow is now NON-rentable**, treated **like Red** — the car is pulled from the pool and **routed to maintenance** (it is showing symptoms). *Already live in the shipped booking gate:* `Vehicle::rentBlockedByCondition()` returns true for **red + yellow**, and `requiresConditionAcknowledgement()` is now **orange-only**.
2. **Financials un-decoupled** — full tiered Approval Matrix. **(Rev. 7)** **Assessment ≠ Final Approval**: a supervisor *valuates* (sets cost/scope); *approval* is strictly governed by the matrix (by amount). `valuated_by` and `approved_by` are explicitly separate signatories.
3. **Severity re-mapped** to 4 levels (Minor / Medium / Major / **Safety-Critical**); Safety-Critical is derived from one hard-coded safety-fault list — the same list drives the booking block, the damage grading, and the road-test trigger.

**Already shipped (baseline for Phase 1):** the booking gate — **Red *and* Yellow block** (pulled from the pool → maintenance); **Orange** rents only after a recorded customer-condition acknowledgment (`condition_ack_*`, migration `2026_07_02_090000`); Green rents freely. *(Yellow-blocks-like-Red is live in `Vehicle::rentBlockedByCondition()`.)*

**Rev. 2 re-prioritization (per Omar):**
- Check-Out/In + Customer Signature → **Phase 1**
- Damage Evaluation Form + 9-category incidents → **Phase 2**

**Rev. 3 addition (bottom-line protection):**
- Deposit ledger + post-return fine hold → **Phase 2.5 (new)**

**Rev. 4 addition (Abu Marouf's Inspector Logic):**
- Three Testing Gates (Pre-Rental / Check-In / Incident) → woven into **Phases 1 & 2**
- Incident Support Flow (phone → on-site → swap) → **Phase 2C (new)**
- Short-Cycle Maintenance for post-accident cars (automated recurring) → **Phase 6 (new)**

**Rev. 5 addition (finalized operational roles + role-scoped queues):**
- Driver flags a scratch → **notify Abdullah & Waleed** immediately
- Managers (Abdullah / Waleed) → **assess / approve parts / assign cost / confirm** screen (new `damage.assess` permission)
- Manager **confirm** → **auto status → In-Repair** + **auto-create task in Abu Marouf's queue**
- Role-scoped task queues formalized (each role its own screen)

**Rev. 6 addition (Safety-Decision Audit — the accountability control):**
- Keyword scan of the inspector's description → **suggested severity** (non-binding)
- Final call contradicts the suggestion → **Audit Required** flag + **instant Admin push** + **forced override reason** → **Phase 7 (new)**

---

## Operational roles & the damage-to-repair chain (Rev. 5)

The finalized division of authority — **who does what, and what the system does automatically between them.** **(Roles refined Rev. 15.)**

| Actor | Role / permission | Does | System auto-action on their input |
|---|---|---|---|
| **Driver** | `maintenance.logistics` | Check-In / Check-Out; **flags** a new scratch (documents only) | on flag → **notify Abdullah & Waleed** + auto-draft Damage Evaluation |
| **Supervisors — Abdullah / Waleed** | `maintenance.delegate` + **new `damage.assess`** | **review & approve**: assess the scratch, approve parts, assign cost, **confirm** the damage assessment — they *decide*, they do not execute | on confirm → **vehicle status → `In-Repair`** + **auto-create a Test/Maintenance task in Abu Marouf's queue** |
| **Abu Marouf — Technical Inspector & Tester** | `maintenance.initiate` | **strictly execution**: runs the technical **Test** and **verifies** the car is fit for service — he tests and confirms, he does **not** decide liability, cost, or scope | works it from his own queue (`inspected_by` scoped) |

**The chain, end to end:** Driver flags → 🔔 Supervisors (Abdullah & Waleed) → (review · approve parts · assign cost · confirm) → **auto `In-Repair`** + **auto-task** → Abu Marouf's queue → he **executes the Test & verifies** → QA (Phase 5) → release.

> **Role definitions (Rev. 15).** **Supervisors (Abdullah & Waleed)** *review and approve* — they are **not** company managers; their authority is scoped to reviewing the damage and approving the repair (`damage.assess` + `maintenance.delegate`). **Abu Marouf is the Technical Inspector & Tester** — his role is **execution only** (testing & verifying), never decision-making. Rev. 4 had the evaluator (Abu Marouf) decide liability & cost; Rev. 5 moved **assessment + parts + cost to the Supervisors**, leaving Abu Marouf the **executor/tester**. The old `damage.evaluate` role is superseded by `damage.assess` (Supervisors) + task assignment to the Inspector-Tester.

### Role-scoped task queues (does the data model support it? — yes)

Screens are permission-gated (Spatie `resource.action`) and each queue is scoped to the work that role owns — so **Abu Marouf sees a different screen than the Drivers** by construction:

| Screen | Permission | Scope (filter) | Status |
|---|---|---|---|
| Driver logistics + Check-In capture | `maintenance.logistics` | tasks assigned to the driver | ✅ exists |
| **Supervisors' damage-assessment screen** | **`damage.assess`** | Damage Evaluations `pending_assessment` | 🆕 new |
| Abu Marouf "My Maintenance Queue" | `maintenance.initiate` | tickets where `inspected_by` = him | ✅ exists |

Already in the data model: 7-role RBAC; per-user "My Queue" screens; the scoping fields the queues filter on (`assigned_driver_id`, `inspected_by`, `workflow_status`, `last_state_change_at`); role-targeted notifications; and the manual-event → `operational_status` cascade (the hook the auto `In-Repair` reuses). ⚠️ These are from the system's feature record — verify `MyMaintenanceQueue` + `inspected_by` scoping against the live code before building.

---

## The Three Testing Gates (mandatory triggers)

Every inspection in the system is spawned by one of three gates — no car is tested "randomly," and none skips a gate:

| Gate | When | Where it lives | Spawns |
|---|---|---|---|
| **Pre-Rental Gate** | before handing a car to a new customer | Phase 1 (Readiness + validation test) | a readiness / validation inspection |
| **Check-In Gate** | when a car returns | Phase 1 (Check-In) → Phase 2 | receiver marks new scratches → **auto Damage Evaluation** |
| **Incident Gate** | a fault reported mid-rental | Phase 2 (Incident) + Support Flow | an incident inspection + the support escalation |

---

## Check vs. Test — two distinct inspection types *(Rev. 15)*

The system separates **visual/damage inspection** from **technical/performance testing** — different triggers, different owners, different authority. A *Check* is a judgment call (Supervisors); a *Test* is execution (Abu Marouf).

| | **Check** (Visual / Damage) | **Test** (Technical / Performance) |
|---|---|---|
| **Triggered when** | a car **returns from a client** (Check-In Gate) | **after maintenance**, or when a **Check flags a mechanical issue** |
| **Owner** | **Supervisors (Abdullah / Waleed)** — *decision* | **Abu Marouf, Technical Inspector & Tester** — *execution* |
| **What happens** | driver flags a new scratch → the system **auto-notifies the Supervisor** to **review & approve** the damage assessment | Abu Marouf runs the **performance test** and **verifies** the car is fit for service |
| **Authority** | review & approve (liability / cost / scope) | test & verify only — **no** decision on liability or cost |

**Escalation — Check → Test (Rev. 15).** If a **Check (Visual)** reveals a **potential mechanical problem** — e.g. impact damage that might have affected the steering, suspension, or drivetrain — the system **automatically escalates** it to a **Test (Mechanical) task for Abu Marouf**. Visual damage that could hide a mechanical fault never closes on a visual pass alone: it spawns a technical Test so the car is verified fit-for-service before release. (Reuses the Foundation safety-fault list to decide when visible damage implies a mechanical Test — e.g. an impact near a steering/suspension component.)

---

## Foundation — Severity re-map + safety-fault list

**Why first:** the 4-level severity scale and the one hard-coded safety-fault list are shared by the booking block (P1), damage grading (P2), incident badging (P2), and the road-test trigger (P5). Build once, reuse everywhere.

- Re-map `fault_severity`: `routine→Minor`, `moderate→Medium`, `critical→Major`; introduce **Safety-Critical** (assigned only by the safety rule — never a blanket promotion).
- **NEW** `config/safety_critical_faults.php` — brakes, steering, suspension, gearbox, engine, overheating, leak, warning light, tyre, accident.
- Update severity chips / filters / validation (`Maintenance::FAULT_SEVERITIES`, `meta.js`, board + contract form).

---

## Phase 1 — Locked-Down Handover *(Readiness + Documentation + Signature)*

**Operational outcome:** a car cannot leave the branch until it passes the readiness panel, the driver has captured the full mandatory photo set, and the customer has signed off. The same photo discipline runs at return. This is our legal protection — the car's exact condition, tied to a contract, time, location, and driver, with the customer's confirmation on record.

### 1A · Readiness & Eligibility gate (the 9 checks) — **the Pre-Rental Gate**
One service, `VehicleReadinessService::evaluate(vehicle, out_date, in_date)` → `{ blockers, warnings, acknowledgments }`. Each check is **block · warn · ack**. This *is* the **Pre-Rental Gate**: the mandatory validation before a new customer gets the car — no handover starts until it passes.

| # | Check | Verdict (Rental-First) |
|---|---|---|
| 1 | Vehicle available | **block** if sold / disposed / suspended / out-of-order |
| 2 | Open damage | **warn** — **block** if safety-critical |
| 3 | Open Maintenance Order | **not a block by itself** — block only if Red grade or safety-critical fault |
| 4 | Reg / insurance valid **through the return date** | **block** if it lapses before return |
| 5 | Service due in window or 500 km | **warn / ack** |
| 6 | GPS working (**new** `gps_last_seen_at`) | **warn** (block for luxury / sports) |
| 7 | Last check-in closed | **block** — fed by 1B |
| 8 | Cleaning done (**new** `cleaning_status`) | **ack** |
| 9 | Idle-days readiness | drives the readiness level (Phase 5) |

- **Endpoint:** `GET /Vehicle/{id}/readiness?out_date=&in_date=`, called live by the New Contract form.
- **Migration:** `add_gps_and_cleaning_to_vehicles`.

### 1B · Check-Out / Check-In documentation (16-item mandatory photo list) — **the Check-In Gate**
**Principle: the driver / receiver documents only — never decides.** A session opens per contract at handover (`check_out`) and return (`check_in`); the backend refuses to complete a session until every mandatory zone is captured. At Check-In the app shows the Check-Out photos side-by-side for auto-compare.

**The Check-In Gate:** the employee *receiving* the car marks any new scratch on the vehicle diagram. Each new flag (a scratch absent from the Check-Out photos) **automatically triggers a Damage Evaluation** — see Phase 2A's accountability chain. The receiver never judges liability; they only mark.

Mandatory zones: Front · Rear · Right side · Left side · Four corners · Four rims · Front & rear glass · Interior seats · Dashboard & screen · Odometer · Fuel gauge · Pre-existing scratches · Car key · Spare / emergency kit · **360° video (luxury / sports)**.

- **NEW** `config/inspection_checklist.php` — the mandatory zone set (+ luxury 360° rule).
- Promote the client-side `InspectionPrototype` to a real flow on the existing S3-backed `inspection_records` store; replace free-text `body_part` with enumerated zones + a server-side completeness gate.
- **Migration** `create_inspection_sessions`: `contract_id`, `phase` (check_out / check_in), `completed_at`, coverage state.
- A completed Check-In session flips readiness check #7 to pass.

### 1C · Customer signature / OTP
At handover the customer confirms the documented condition — by drawn signature **or** an OTP / confirmation link. Nothing is a valid handover without it.

- **Migration** `add_customer_confirmation_to_inspection_sessions`: `signature_s3_key`, `otp_code`, `otp_verified_at`, `confirmed_at`, `confirmed_via` (signature | otp | link).
- **Endpoints:** `POST /Inspections/session` (open, tied to contract) · `POST /Inspections/{session}/photo` (presign + store to a zone) · `POST /Inspections/{session}/complete` (blocked until coverage met) · `POST /Inspections/{session}/sign` (signature or OTP verify).
- **Permissions:** driver capture reuses `inspections.manage`. Every photo is tied to **contract + vehicle + time + location + driver**.

**Done when:** the contract form shows the live 9-check panel; a handover cannot complete without the full mandatory photo set **and** a customer signature/OTP; Check-In shows before/after compare and closes the loop back to readiness #7.

---

## Phase 2 — Damage Evaluation & Incident Capture *(before the money layer)*

**Operational outcome:** when a car returns with damage, or a fault is reported during a rental, the **driver documents** it — then **Abdullah & Waleed (Supervisors)** assess it, approve parts, and assign cost; their confirm auto-flips the car to In-Repair and drops the execution task into **Abu Marouf's** queue. That record is the single source that opens the Maintenance Order and feeds the Financial Approval Matrix.

### 2A · Damage Evaluation Form
A real structured record (`damage_evaluations`), linked to the contract + the Check-In session photos.

| Field | Options |
|---|---|
| Damage type | Body / Paint / Rim / Tyre / Glass / Interior / Mechanical / Electrical / Accident |
| Severity | Minor / Medium / Major / **Safety-Critical** (reuses the Foundation scale) |
| Rentable? | Yes / No |
| Cause | Customer / Wear & Tear / Previous Damage / Garage Fault / Unknown |
| Liable party *(determined by the **police report / insurance policy** — not the Supervisor)* | Customer / Company / Insurance / Warranty / Pending |
| Needs garage? | Yes / No → opens a Maintenance Order |
| Needs replacement car? | Yes / No |
| Police report? | Yes / No |
| Preliminary estimate | AED — feeds the approval tier in Phase 4 |
| Supporting photos | **Mandatory** |
| Decision | Charge / No Charge / Hold Deposit / Insurance / Maintenance |

**Golden rule (hard-coded):** any Safety-Critical damage (brakes, tyres, steering, warning light, suspension, overheating, leak) → the car **cannot** return to Available, even if a booking is waiting. Reuses the Foundation safety-fault list.

**Check-In auto-trigger (the accountability chain — Rev. 5 roles):** the **driver / receiver** marks new scratches on the vehicle diagram at Check-In (Phase 1B) — they only **document**, never decide. Each new flag (a scratch absent from the Check-Out photos):
1. **Auto-creates a draft Damage Evaluation** (`damage_evaluations`, status `pending_assessment`) linked to the session + marked flags — nothing can be silently dropped.
2. **Immediately notifies Abdullah & Waleed** (the Supervisors) — they own the review & approval.
3. **Supervisors assess** on their screen: confirm the damage, approve parts, assign a cost + scope, and the repair decision (the form above). **The liable party — who bears the cost (customer / company / insurance / warranty) — is determined by the police report / insurance policy, *not* the Supervisor: they record that determination, they don't make it.**
4. On Supervisor **confirm** → the system **auto-sets vehicle status to `In-Repair`** and **auto-creates a Test/Maintenance task in Abu Marouf's queue** to execute.

So the receiver flags, the Supervisors decide & cost, and Abu Marouf executes the Test — three roles, two automatic hops between them (→ Phase 2.5 deposit draws on the assigned cost).

### 2B · 9-category incident taxonomy (during-rental fault tickets)
A fault reported mid-rental opens an Incident/Fault ticket classified to Omar's nine categories, with severity-driven high-priority badging (Safety-Critical → red badge).

Categories: Mechanical issue · Accident · Warning light · Tyre issue · AC issue · Battery · Customer abuse · Normal wear · Emergency replacement.

- **NEW** `config/incident_categories.php` — the 9 categories, each mapped to an internal fault category and flagged safety-critical or not.
- Extend the existing `openComplaint` intake with `incident_category`; high-priority badge on the board / command view.

### 2C · Incident Support Flow (the Incident Gate)
A fault reported mid-rental (Sales ↔ Ops ↔ Abu Marouf) escalates through three rungs, each logged on the incident ticket:
1. **Phone troubleshooting (remote)** — first attempt; outcome recorded.
2. **On-site repair** — Abu Marouf visits the customer (creates a logistics visit); if it clears, close.
3. **Vehicle swap** — if remote + on-site both fail, hand the customer a replacement (ties into the existing Maintenance Swap board).

- **Field:** `resolution_path` (phone / onsite / swap) + escalation state on the incident ticket; each attempt time-stamped with who + outcome.
- Reuses complaint intake (trigger), logistics (the on-site visit), and the swap board (the replacement) — no new subsystems, just the ladder that connects them.

### Migrations & models
- **NEW** `damage_evaluations` (`contract_id, vehicle_id, inspection_session_id, damage_type, severity, is_rentable, cause, liable_party, needs_garage, needs_replacement, needs_police_report, estimate_amount, decision, notes`, + workflow: `status` (`pending_assessment` → `assessed` → `task_created`), `valuated_by` (Abdullah/Waleed — the Supervisor who set cost/scope), `valuated_at`, `parts_approved` (bool/list), `assigned_cost`) + a photos relation. **Note (Rev. 7):** `valuated_by` is the *valuation* signatory, distinct from the Phase-4 `approved_by` (matrix approval).
- `add_incident_category_and_resolution_to_maintenances` (the 9-category enum + `resolution_path` phone/onsite/swap + escalation state on the incident ticket).

### Endpoints & state-machine
- **Auto-create + notify:** completing a Check-In session with new driver-marked flags fires an observer → inserts one `damage_evaluations` draft per flag (`pending_assessment`) and **notifies Abdullah & Waleed**.
- **NEW** `POST /Damage-Evaluations/{id}/assess` (Supervisors, `damage.assess`) — confirm damage, approve parts, assign cost → on confirm the system **sets `vehicle.operational_status = In-Repair`** (reusing the manual-event cascade) and **auto-creates the inspection/maintenance task in Abu Marouf's queue** (`inspected_by` = Abu Marouf, at `inspection_pending`), carrying severity + assigned cost.
- `POST /maintenance-tickets/{ticket}/support-attempt` (record a phone/onsite/swap rung + outcome) · `GET /Damage-Evaluations?status=pending_assessment` (Supervisors' queue) · `GET /contracts/{id}/damage-evaluations`.

### Permissions
- **NEW** `damage.assess` — the Supervisors (Abdullah / Waleed) assess, approve parts, and assign cost. The driver (`inspections.manage` / `maintenance.logistics`) only documents; Abu Marouf (`maintenance.initiate`, Technical Inspector & Tester) executes the resulting Test/maintenance task. (Supersedes Rev. 4's single `damage.evaluate`.)

**Done when:** a driver-flagged scratch notifies Abdullah & Waleed, their confirm auto-flips the car to In-Repair and drops a task into Abu Marouf's queue, and the assigned cost flows to the Phase-2.5 deposit — all without a manual hand-off step.

---

## Phase 2.5 — Customer & Deposit Protection *(the cash-flow & risk layer)*

**Operational outcome:** the money side a car-centric protocol misses. A deposit you can actually charge against, and deposits held until UAE tolls/fines settle. This is where the fleet protects its cash — deliberately lean: two parts, nothing more.

### 2.5A · Deposit ledger
Turns the existing deposit fields (`contract_deposit`, `deposit_debit/credit`) into a real ledger the Phase-2 damage decision can post against — without it, "Charge / Hold Deposit" is toothless.
- **Lifecycle:** held at handover → charged (damage / fine / fuel) → released / refunded balance.
- **NEW** `deposit_ledger_entries` (`contract_id, customer_id, type` hold/charge/refund/release, `amount, reason, source` [damage_evaluation_id / fine / fuel / manual], `created_by, created_at`); `deposit_status` on the contract (held / partially_charged / released).
- **Endpoints:** `POST /contracts/{id}/deposit/hold` · `/charge` · `/release` · `GET /contracts/{id}/deposit`.
- Refund balance can flow to the customer wallet (existing carried-forward credit).

### 2.5B · Post-return fine / toll hold
UAE Salik + traffic fines land *after* the car is back — so the deposit must not be refunded until they settle.
- On Check-In the contract enters **`pending_charges`**; deposit release is blocked until the fines window clears or ops settles it manually.
- **NEW** `fine_holds` (`contract_id, type` salik/fine, `amount, status` pending/settled/cleared, `settled_at`); config `fines_hold_days` (e.g. 14–30).
- **Endpoints:** `POST /contracts/{id}/fines` (record a landed fine → draws down the deposit) · `POST /contracts/{id}/fines/settle`.
- **Alerts:** "deposit ready to release" once holds clear; "unsettled fines aging" reminder (reuses the notification scanner).

> **Removed (Rev. 16):** the **Customer Risk Flag** (2.5C — the per-customer watch / high-deposit / blacklist level feeding the eligibility gate) is **dropped** from the plan. *(Note: the per-incident "Customer abuse" category in Phase 2B stays — that's an incident tag, not a customer-level flag.)*

**Permissions:** deposit + fines reuse `billing.manage`.

**Done when:** a damage/fine charge draws down a real deposit; and a deposit cannot be refunded while fines are pending.

---

## Phase 3 — Garage Routing Bridge

**Operational outcome:** the system recommends the best garage (top 3, scored); the coordinator accepts it or overrides — and an override **must** carry a written reason and a manager sign-off.

**The gap:** the scoring engine (`GarageRoutingService::suggest()`) already ranks garages, but nothing calls it — dispatch today is a manual pick with no reason captured.

- **Expose the engine:** `GET /maintenance-tickets/{ticket}/garage-suggestions` → ranked list + top pick + per-garage score, reasons, warnings. Dispatch shows the **top 3**.
- **Capture the override:** in `assignDispatch`, when the pick ≠ the suggestion, **require `override_reason`** + a manager approval (shared with Phase 4).
- **Complete the 8 criteria:** add the 5 missing signals (completion time, price, parts availability, proximity, warranty).

**Migrations**
- `add_routing_decision_to_maintenances`: `suggested_vendor_id, routing_overridden, routing_override_reason, routing_score`.
- `add_routing_signals_to_vendors`: `avg_completion_days, price_index, parts_availability, zone` (+ lat/long), `workmanship_warranty_months`; new weights in `config/garage_routing.php` (25/20/15/15/10/5/5/5).
- **Plus:** the missing `GarageRoutingRuleController` (in-app CRUD for specialty rules).

**Done when:** dispatch shows the ranked suggestion, an off-suggestion pick is blocked without a reason + sign-off, and all 8 criteria score.

---

## Phase 4 — Financials (Approval Matrix into the Maintenance Order)

**Operational outcome:** every repair gets a garage quotation first; the cost — anchored to the Phase-2 damage estimate — is routed automatically to the right approver by amount, with luxury cars and accidents bumped a level. No work starts, and no car moves to the garage, until the right person approves.

**Valuation ≠ Approval (Rev. 7).** The supervisor who assesses a damage/repair **valuates** it (sets cost + scope) — stamped `valuated_by` / `valuated_at`. That is *not* final approval. The system then checks the amount against the **valuator's own tier limit**:
- **Within his limit** → he is also the authorized signatory; auto-approve (`approved_by` = the supervisor), proceed to dispatch.
- **Above his limit** → transition to **`pending_approval_hierarchy`**, route to the correct tier per the matrix; `approved_by` is stamped only when that signatory signs.

`valuated_by` (the supervisor) and `approved_by` (the matrix signatory) are stored as **separate** fields — never conflated. Note: the vehicle can be grounded (`operational_status = In-Repair`) at valuation, but **garage dispatch / work-start waits for `approved_by`**.

**States inside the workflow:**
```
inspection_pending → awaiting_quotation → (valuate) →
      ├─ cost ≤ valuator tier   → auto-approved → awaiting_dispatch → under_repair
      └─ cost > valuator tier   → pending_approval_hierarchy → (matrix signs) → awaiting_dispatch → under_repair
                              ↑______________ rejected / re-quote ______________↓
under_repair → additional_quotation → pending_approval_hierarchy → under_repair   (extra-work loop)
```

**The Approval Matrix**

| Amount (AED) | Approver | Permission |
|---|---|---|
| 0 – 500 | Maintenance Supervisor | `maintenance.approve.t1` |
| 501 – 2,000 | Ops / Fleet Manager | `maintenance.approve.t2` |
| 2,001 – 5,000 | Branch Manager | `maintenance.approve.t3` |
| > 5,000 | Owner / GM | `maintenance.approve.t4` |
| Accident / insurance | Claims + Manager | `maintenance.approve.claims` |
| **Luxury / sports car** | one tier higher than the amount implies | (reads `vehicle_class`) |

**Migrations, endpoints & permissions**
- **NEW** `maintenance_quotations` and `maintenance_approvals` tables; the flat AED-500 gate in `evaluateApproval` replaced by a tier resolver. Approval row carries `valuated_by`, `valuated_at`, `approved_by`, `approved_at`, `amount`, `tier`, `pending_hierarchy` (bool).
- `POST /maintenance-tickets/{ticket}/quotation` (garage) · `POST /maintenance-tickets/{ticket}/valuate` (supervisor sets cost → auto-approve or `pending_approval_hierarchy`) · `POST /maintenance-tickets/{ticket}/approval` (matrix signatory approves / rejects).
- Add `maintenance.approve.t1..t4` + `.claims` in the roles seeder; each tier's ceiling is the "valuator limit" checked at valuation.
- **Sell / Replace alert** (Omar §9): 12-month maintenance spend **> 40% of car value** (`purchase_price`) → Sell / Replace / Stop-Renting notification.
- **Un-decoupling:** flip `SHOW_FINANCIALS` on (maintenance / approval surface first).

**Done when:** a quotation drives an amount-routed approval to the correct role; overrides, luxury, and accidents route correctly; the cost-to-value alert fires.

---

## Phase 5 — QA & Road Test (mandatory itemized checklist)

**Operational outcome:** no car returns to rental without passing a fixed QA checklist, every time — not just engine jobs. Safety repairs also require a documented road test. A single failed item sends the car back to the garage.

**The QA checklist** (per ticket — recommend a `maintenance_qa_items` table for per-item audit):

| Item | Result | Required | Evidence |
|---|---|---|---|
| Repair matches the order | pass / fail | always | — |
| Warning lights cleared | pass / fail | always | dashboard photo |
| AC / climate works | pass / fail | always | — |
| Fluids & leaks | pass / fail | always | — |
| Tyres & rims | pass / fail | always | — |
| Brakes | pass / fail | when safety-relevant | via road test |
| Odometer recorded | value + photo | always | odometer photo |
| Cleaning done | pass / fail | always | — |
| **Road test** | pass / fail / n-a | conditional (rule below) | tester + time |
| Status updated to Ready | auto | always | — |

**Road-test rule:** required when a fault is in the Foundation safety-fault list, or severity is Safety-Critical, or the car sat idle > 7 days, or it's luxury / sports. QA cannot pass until the road test passes.

**Change + alerts**
- Remove the engine/electrical/transmission-only QA short-circuit → **every** ticket routes through QA; `approveQa` blocks close until all required items pass.
- **4-day-in-garage alert** — a detector reads `last_state_change_at` (already stamped) and alerts management when a car sits in the garage **more than 4 days** without closing.
- **Idle-days readiness matrix** feeds Phase 1 check #9 (<48h quick · 3–7d safety · >7d readiness+road test · >14d battery/tyres/fluids · >30d full technical).

**Done when:** no ticket closes without a fully-passed itemized QA (+ road test where required), and management receives the 4-day-in-garage and idle-readiness alerts.

---

## Phase 6 — Inspector Protocols *(engine-driven, not memory)*

**Operational outcome:** Abu Marouf's inspection rules become engine-driven recurring tasks, triggered by clear rules rather than anyone's memory.

### 6A · Inspector Protocol Library
- **Routine** — oil / filter by mileage limits (already live via the Oil-Change interval + service-due detectors).
- **Battery** — health + replacement interval. **NEW** `vehicles.battery_installed_at`, `battery_health`, `battery_interval_months`; a "battery due" check.
- Config-driven: **NEW** `config/inspector_protocols.php` defining each protocol's trigger + checklist.

> **Removed (Rev. 16) — the Stability Cycle Engine.** The automated post-accident **5-day Stability Cycle**, its **missed-by-1-hour → `safety_block`**, and its **3-clean-cycles graduation** are **dropped entirely** from the plan — along with `stability_check_ticket`, `auto_maintenance_audits`, `stability:scan`, and the `safety_block` / `clean_cycles` / `next_stability_due` machinery. *(None of it was in the shipped code — nothing to remove there.)* Post-accident follow-up is handled by the ordinary **Check / Test** triggers (see *Check vs. Test*) + the Inspector Protocol Library above — no separate enforced cycle.

**Done when:** the Inspector Protocol Library drives routine + battery checks by config-driven rule, reusing the existing service-due detectors — with no automated stability machinery.

---

## Phase 7 — Safety-Decision Audit *(the accountability control)*

**Operational outcome:** the system becomes a **safety guardian** without overruling Abu Marouf's judgment. When his final severity / rentable call disagrees with what the keywords in his own description imply, the record is flagged, the Admin is alerted instantly, and he must justify it in writing. Every block-or-clear against system logic leaves a transparent trail.

**Reuses what already exists:** the **severity-mapping table is already built** — `finding_keywords` (Keyword Risk Library) maps each keyword to a risk on the same scale; the findings-catalog endpoint already serves the `keyword_risk` map; the notification bus (in-app bell, broadcast-ready) already exists; and the **override-reason + read-only audit-history pattern** is already used for garage routing and the rental-first policy. This layer wires them together — it is not a new paradigm.

### 7A · Suggestion at entry
- As the inspector types the **Description**, the system scans it against `finding_keywords` and computes a **Suggested Severity** = the highest-risk matched keyword (e.g. "Brakes", "Engine" → 🔴; "Paint", "Interior" → 🟢).
- The suggestion is displayed as a non-binding label. Abu Marouf still makes the final choice.

### 7B · Conflict rule (2×2 threshold)
| System suggests | He marks | Result |
|---|---|---|
| 🔴 Major / Safety-Critical | **Rentable** | ⚠️ **Conflict → Audit Required** (a dangerous car cleared) |
| 🟢 Minor | **Non-Rentable** | ⚠️ **Conflict → Audit Required** (a trivial car grounded) |
| agrees, or Medium middle-ground | — | ✅ silent pass, no flag |

### 7C · On a conflict, the system MUST
1. **Flag the record `audit_status = audit_required`.**
2. **Block save until an `override_reason` is written** (forced justification).
3. **Send an instant push/alert to the Admin** (super-admin) via the notification bus.
4. **Write an audit-log row** for the Admin's review queue.

### Migrations & models
- **Reuse** `finding_keywords` as the severity map (ensure it maps onto the 4-level scale).
- **NEW** `safety_decision_audits` (`record_id, record_type, suggested_severity, chosen_severity, is_rentable, override_reason, decided_by, admin_notified_at, reviewed_by, reviewed_at`).
- **Add flags** on the maintenance/inspection row: `suggested_severity`, `severity_overridden` (bool), `audit_status` (none / required / reviewed).

### Endpoints & UI
- The severity suggestion is computed on the existing findings/description entry (no new inspector screen).
- **NEW** Admin audit queue: a filtered read-only list of `audit_status = required` (reuses the OverrideAudit history UI), with a "mark reviewed" action.

### Permissions
- Reuses existing permissions for the inspector entry; the Admin alert targets super-admin. (Optional new `audit.review` if you want a dedicated reviewer other than the Admin.)

> **Scope note (updated Rev. 15):** under the Check/Test split the **rentable + severity *decision* is a Check call owned by the Supervisors (Abdullah/Waleed)**; Abu Marouf, the Technical Inspector & Tester, contributes the **technical Test verdict** (fit / not-fit), not the business decision. This audit therefore sits on the **Supervisor's** severity/rentable decision, informed by Abu Marouf's Test result — the keyword-suggestion-vs-final-call conflict rule is unchanged; only *whose* decision it audits moves from the tester to the Supervisor.

**Done when:** typing "brakes" suggests 🔴; if Abu Marouf marks that car rentable, the save is blocked until he writes a reason, the Admin gets an instant alert, and the decision lands in the audit queue with the full before/after trail.

---

## Phase 8 — Command Center *(full-transparency layer)*

**Operational outcome:** Omar and the Admin get one place that answers "who did what, where is every car, and what's stuck?" — in real time. No more silent updates.

**Data-model answer (lean):** we **tap the existing AuditLog + workflow state machine** for almost all of it. Only the global Activity Feed benefits from **one thin new table**; the tracker and overview are **pure read views** — no migration.

### 8A · Real-time Activity Feed (who did what)
Every critical action — cost **valuation**, **approval**, **set-to-ready**, **inspection / damage filing**, **override** — is pushed to a global stream showing **actor · timestamp · the specific change**.
- **Reuses:** `vehicle_log_events` (already logs every workflow transition with actor + time), `sync_changes` (field-level was→now diffs), `maintenance_approvals`, `safety_decision_audits`.
- **NEW (one thin table)** `activity_log` — polymorphic: `actor_id, action, subject_type, subject_id, summary, meta` (JSON), `created_at`. A central `ActivityLogger` writes one row at each critical action (instead of UNION-querying N logs at read time) → a single sortable, paginated stream.
- **Endpoint:** `GET /command-center/activity` (polling now; broadcast-ready via the existing notification bus).

### 8B · Vehicle Live-Status Tracker (where is the car?)
- **NO new table.** Reuses `Maintenance::livePosition()` (already returns 🚚 In Transit / 🔧 In Workshop from `workflow_status` + active garage stint + active logistics move), plus the active `LogisticsTask` (owner/assignee + destination) and the garage stint (vendor).
- Each car shows: **status → current owner/assignee** (Driver / Garage / Dispatcher). If status = Maintenance → **which vendor/garage** has it and **who from our team is the Supervisor** (`inspected_by` / assigned).
- **Endpoint:** `GET /command-center/fleet-status`.

### 8C · Management Overview (Omar + Admin)
- **NO new table** — three queries over existing data:
  - **Yellow-in-field:** `condition_grade = 'yellow'` ∩ an active rental contract (out on rent right now).
  - **Pending approvals:** tickets in `pending_approval_hierarchy` (Phase 4 / Rule 2), grouped by the tier they're waiting on.
  - **Stale tasks:** claimed but not finished within X hours — derived from `last_state_change_at` / claim time vs. the stage SLA (`stageAge()` + `STAGE_SLA_SECONDS` already exist), highlighted red.
- **Endpoint:** `GET /command-center/overview`.

### Permissions
- **NEW** `command_center.view` (Admin + Omar / managers). Reuses the notification bus for any push.

**Done when:** the Activity Feed streams every critical action with actor + time + change; the tracker answers "where is car X and who has it" for the whole fleet; and the overview surfaces Yellow-in-field, hierarchy-pending approvals, and stale claimed tasks at a glance — all without a heavyweight new tracking table.

---

## Phase 9 — Cost Intelligence *(history-driven pricing)*

**Operational outcome:** before you commit a repair to a garage, the system shows what that repair *has cost before* — per garage — and flags when you're likely overpaying. It **never blocks** a premium-garage choice; it just makes the money visible.

**Answer to "hook into Smart-Routing?": yes.** The Phase-3 `GarageRoutingService::suggest()` already ranks garages; we extend each ranked candidate to also carry an **`estimated_price`** for *this ticket's fault category + car model*, drawn from history. Pricing becomes one more signal in the same suggestion payload — no separate engine.

### 9A · Cost Predictor
- On selecting a **Fault Category** (e.g. Transmission), the system queries maintenance history (`maintenances` + `maintenance_line_items`) for **that car model + that fault type** → a predicted cost band (avg + range).
- Serves the estimate into the dispatch UI and into `garage-suggestions`.

### 9B · Comparative View + Variance
- Before committing, the UI shows **"average cost for this repair at Garage A vs Garage B"** side by side.
- **Variance analysis:** if a garage is **>20% above** the historical average for that repair → a **High-Cost Warning** badge (configurable threshold).
- **Non-blocking** (per your constraint): a premium garage is allowed; the warning is informational + logged (reuses the override-reason pattern if you pick the flagged one).

### 9C · Actual vs Estimated + Garage Intelligence snapshot
- **NEW** on the maintenance record: `estimated_cost` (from the quotation / predictor) and `actual_cost`. At **Close**, the system captures the **final payment amount** into `actual_cost`.
- **NEW `garage_cost_stats` table** (the Garage Intelligence snapshot) — per `(vendor_id, fault_category, vehicle_model)`: `avg_cost`, `sample_count`, `last_repair_at`, `updated_at`. On every Close it **auto-updates** this snapshot from `actual_cost` — so the predictor sharpens over time.
- Feeds Phase-3's `price_index` signal with real per-category data instead of a static number.

**Migrations & endpoints**
- `maintenances`: `estimated_cost`, `actual_cost`.
- **NEW** `garage_cost_stats` (snapshot). Refreshed by a `CloseObserver` hook (+ a `garage:recalc-stats` backfill command).
- `GET /maintenance-tickets/{ticket}/garage-suggestions` payload extended: each candidate gains `estimated_price` + `variance_pct` + `high_cost` flag.

**Done when:** picking a fault category shows a predicted cost; the dispatch view compares garages by historical cost with a >20% High-Cost Warning (non-blocking); and each Close writes `actual_cost` and refreshes the `garage_cost_stats` snapshot.

---

## Phase 10 — Accident-to-Garage Communication Chain *(automated, receipted)*

**Operational outcome:** an accident/new-damage report drives a **guaranteed, step-by-step notification chain** — no one can say "I didn't get the notification," and Abu Marouf can't even *see* the ticket until the Supervisors have assessed it. This hardens the Rev. 5 damage→repair chain with a strict hand-off + receipts.

### The chain
- **Trigger:** an inspection filed with `damage_status = NEW`, **or** a maintenance event created for an **Accident**.
- **Step A → Supervisors:** instant **Push + In-App** notification to **Abdullah & Waleed**, flagged **High Priority**, carrying a link to the **damage photos** and the **Liability Party** field.
- **Step B → Inspector-Tester:** once Abdullah *or* Waleed **Submit Assessment** (cost + scope + the Phase-4 approval-matrix check), the system **auto-creates a Test/Maintenance Task** for Abu Marouf — carrying the Supervisors' **assessment notes** so he knows exactly what to test for.
- **Step C — the Guard:** Abu Marouf's dashboard **does not show** the ticket until the assessment is submitted. His queue query is scoped to `status ∈ {assessed, task_created}` — a pending-assessment ticket is invisible to him.

### Notification mechanics
- **Non-dismissible:** the Command-Center notification for the current step **cannot be dismissed** until the next step is taken (Supervisor assessment / Inspector-Tester pickup).
- **Receipts (no "I didn't see it"):** **NEW `notification_receipts` table** — per `(notification, role/recipient)`: `notified_at`, `viewed_at`, `actioned_at`. Every stage stamped, per role. Surfaced in the Command-Center audit.

**Migrations, models & permissions**
- **NEW** `notification_receipts` (`notification_id, recipient_id, role, notified_at, viewed_at, actioned_at`); a `non_dismissible` flag on the notification.
- Reuses the existing notification bus (Push + in-app), the Rev. 5 Supervisor `damage.assess` step, and the Phase-2 auto-task to Abu Marouf.
- The Step-C guard is a **scope filter** on the inspector's queue — no new state, just visibility gated on `status`.

**Done when:** a new-damage/accident report instantly high-priority-pings Abdullah & Waleed with photos + liability; their Submit-Assessment auto-drops a note-carrying task to Abu Marouf; his dashboard never shows a pre-assessment ticket; the step notification can't be dismissed until acted on; and `notified_at/viewed_at/actioned_at` are logged for every role.

---

## Phase 11 — Usage Monitoring & Mileage Enforcement *(asset protection)*

**Operational outcome:** every kilometre is logged and tamper-proof, and contractual mileage limits are policed automatically — so a renter can't quietly run up the odometer or drive out of bounds without it surfacing. The same discipline runs on the **maintenance side (11D)**: a garage can't quietly run up the odometer during a shop visit either.

**What's already built (we harden it):** `OdometerContinuityService` (per-stage verdicts, currently *soft/non-blocking*), rollback/jump detection in `mileage:scan`, and mandatory odometer photos on logistics moves. **What's a dependency:** a **GPS/telematics feed** — not integrated today; the odometer half (11A) works without it, the GPS half (11B) needs a provider first.

### 11A · Mandatory Odometer Logging (hard gate)
- Every status change — **Dispatch, Return, and Mid-Rental Check** — **requires** an **odometer photo + a manual odometer entry** (both mandatory input fields; the request is rejected without them).
- **Data Integrity Error:** if the entered value is **less than the previous record**, the transaction is **blocked** until the **Admin approves the correction** — **Admin-only** (supervisors **cannot** override an odometer integrity flag, per sign-off). A hard gate (upgrades today's soft confirm), reason logged (override-audit pattern).
- Reuses `OdometerContinuityService` for the previous-reading lookup; adds `integrity_override_by` / `integrity_override_reason` on the reading.
- **(Rev. 14) Capture model superseded — see the Operational Integrity Gate (IG-1).** The *"required at every status change"* rule is replaced by **event-driven capture** (an always-on reading button + a verify-at-Ready check). The **rollback → Data-Integrity block + Admin-only override still stands** — only *when/how* the reading is entered changes, not the integrity enforcement.

### 11B · GPS-Fence & Mileage Enforcement *(DEFERRED — awaiting a live GPS feed)*
> **Sign-off decision:** 11B is **deferred** until a live telematics feed is connected. Implementation focuses on **11A** + the **11C dashboard scaffolding** (which shows the odometer-based excess check now, and lights up GPS columns when the feed lands).
- **Distance policing:** compare **GPS-calculated distance** against the **odometer-logged distance**. A large divergence flags **odometer tampering or a GPS gap** (a signal, not just noise). The odometer stays the legal/billing record; GPS corroborates.
- **Excess-mileage alert:** contractual allowed = `miles_allowed_pd` × rental days (already on the contract). If **GPS active km > the contractual allowance**, the system auto-fires a **Policy Violation Alert** to the Admin and tags the rental **`excess_mileage`**. *(This math also runs against the odometer even before GPS lands.)* **(Rev. 16: the +5% buffer is removed — excess is simply over the allowance.)**
- **Geo-fence (optional):** flag any movement **outside the operational boundary** defined on the contract (e.g. leaving the city/region).

### 11C · Usage Dashboard (in the Command Center)
- Per active rental: **real-time GPS distance vs. the contractual limit**.
- A **Mileage Trend bar**: 🟢 Safe · 🟡 Approaching limit · 🔴 Limit exceeded. Extends the Phase-8 overview.

### 11D · Shop-Movement Integrity Gate *(the garage-side odometer audit — Rev. 13)*
**The concern:** 11A stops the *renter* running up the odometer; **11D stops the *garage*** doing the same during a maintenance stint — or using the car for personal errands. Two odometer readings already bracket every stint, so we simply measure how far the car moved *inside the shop* and flag anything past a legitimate test-drive.

- **Entry ("In") reading — already captured.** When the car reaches the garage the arrival odometer is recorded — `receive_odometer` at garage-intake, falling back to `dispatch_odometer` (the pickup reading whose workflow log literally reads *"وصل الكراج / arrived at garage"*). **Hardened:** the garage-in reading becomes **mandatory** for a garage trip (upgrades today's optional field), so Net Distance is always computable — no silent gap.
- **Exit ("Out") reading — the existing *Ready* capture.** At the final test-drive / re-inspection ("Ready", Abu Marouf) the exit odometer (`return_odometer`) is captured with its **already-mandatory photo**. **No new capture point** — the gate rides the reading that already exists at that step.
- **Net Shop Distance = `return_odometer − receive_odometer`** (garage-in → garage-out). Surfaced on the ticket's final report and the mileage timeline (`GET /{ticket}/mileage` already computes per-reading deltas — 11D exposes *this specific bracket* as the shop-movement figure).
- **Configurable threshold.** Default **20 km** for a legitimate shop test-drive, held as an **Admin setting** (`shop_movement_threshold_km`) — tunable without a deploy (an Admin-setting pattern; **not** hard-coded).
- **Trigger — flag + alert, non-blocking.** If Net Shop Distance **>** threshold: (1) stamp **`excessive_shop_movement`** into `odometer_flags` — a new `OdometerContinuityService` verdict at `STAGE_GARAGE_OUT`, distinct from today's soft `test_drive` nudge; and (2) fire an **immediate Admin alert** on the notification bus (reuses the Phase-7 / 11 admin-alert pattern). The car **still returns to service** — the flag + alert are the **audit trail**, not a gate that strands the vehicle.
- **Data integrity (why it lives on the ticket).** Both readings and the verdict persist on the maintenance record (`receive_odometer`, `return_odometer`, `odometer_flags`) — the permanent evidence to verify the shop didn't drive the car unnecessarily. *(Note: there is **no separate `MaintenanceEvent` table** — the workflow ticket **is** the maintenance record; the transition is also logged to `vehicle_log_events`.)*
- **Reuses (almost nothing new):** `OdometerContinuityService` (+ the new verdict + the configurable threshold), the `odometer_flags` store & `recordOdometerFlag()`, the mandatory Ready-step photo, `mileage()`'s existing distance math, and the notification bus. **The only new persisted thing is the `shop_movement_threshold_km` Admin setting — no new table.**
- **(Rev. 14) Capture points re-sourced — see the Operational Integrity Gate.** With event-driven capture, the entry reading is the **IG-2 Garage Arrival event** and the exit reading is the **IG-1 `exit` event** (no longer forced at Ready). **Net Shop Distance = `exit` event − `arrival` event** — same garage-in → garage-out span, same 20 km `shop_movement_threshold_km` rule, same verdict + Admin alert; only the source events change.

**Migrations, endpoints & permissions**
- **NEW** `odometer_readings` (or extend existing capture): `subject` (contract/ticket/move), `stage` (dispatch/return/mid_rental), `value`, `photo_ref`, `prev_value`, `integrity_flag`, `integrity_override_by/reason`, `recorded_by`, `recorded_at`. Hard-block validation lives in the status-change request classes.
- **NEW** `rental_usage` (per contract): `gps_distance_km`, `odometer_distance_km`, `allowed_km`, `excess_flag`, `geofence_breach`, `updated_at`. *(Rev. 16: no `buffer_pct` — excess is a straight over-allowance comparison.)*
- **NEW (11D)** Admin setting **`shop_movement_threshold_km`** (default 20); a new `OdometerContinuityService` verdict **`excessive_shop_movement`** at `STAGE_GARAGE_OUT`; the Ready-step handler computes **Net Shop Distance** and, past threshold, writes the `odometer_flags` entry + fires the Admin alert. The garage-in odometer is upgraded to a **required** field on garage trips so the bracket is always closed.
- **NEW** detector in `NotificationScanner`: excess-mileage + geofence-breach (11B) **and** excessive-shop-movement (11D) → Admin alert.
- Integrity-override gated to Admin; usage dashboard under `command_center.view`.

**Done when:** no status change saves without an odometer photo + entry; a lower-than-previous reading hard-blocks until Admin approves; GPS active km over allowed +5% auto-tags `excess_mileage` and alerts the Admin; the Command Center shows a live green/yellow/red mileage trend per rental; **and (11D)** a maintenance stint whose **garage-in → garage-out** distance exceeds the configurable shop-movement threshold auto-stamps **`excessive_shop_movement`**, shows the **Net Shop Distance** on the final report, and fires an **instant Admin alert** — without blocking the car's return to service. *(11B activates once a GPS feed is connected; 11A, the odometer-based excess check, and 11D ship without it.)*

---

## Operational Integrity Gate — Event-Driven Capture *(the Bulletproof-Lifecycle capstone — Rev. 14)*

**Operational outcome:** every physical movement of a car leaves a verifiable record, captured **at the moment it happens** — not forced at an artificial status flip. This is the capstone that makes the earlier phases tamper-evident end-to-end. It **changes no earlier decision except *when* data is captured**; it layers onto Phases 3 (garage routing), 5 (QA/Ready), and 11 (odometer).

> **Why it exists:** the workflow kept forcing data entry at the wrong moment — the odometer photo only at *Ready*, the garage chosen at *dispatch* before the car had moved. Staff were flipping status artificially just to log a reading. The fix is to make capture **event-driven**, so a reading or a garage decision is recorded when the real-world event occurs, independent of `workflow_status`.

### IG-1 · Event-driven odometer capture *(decouples capture from status)*
- **Decouple the photo from `Ready`.** Today the mandatory odometer photo is forced at the `ready` transition. **That coupling is removed.**
- **Always-on capture.** An **"Add Odometer Reading" action is visible on the ticket at every stage** (any `workflow_status`). Staff log **{reading + photo + `event_type`}** the moment a car moves — **not a status change**, just an event attached to the ticket.
- `event_type ∈ {pickup, arrival, exit, mid_check}`. Each event writes an `odometer_readings` row (the table Phase 11A already defines, now carrying `event_type`) + an `InspectionRecord` photo (reuses `storeOdometerPhoto()`) + a continuity verdict into `odometer_flags`.
- **`Mark Ready` becomes a Final Verification, not a capture point.** On Ready the system checks the **required event set** (`pickup`, `arrival`, `exit`) exists; if any is missing it returns a **"missing captures" prompt** the UI surfaces — instead of blocking the user inside the Ready modal or forcing an inline photo.
- **Supersedes:** Phase 11A's *"every status change requires an odometer entry"* hard-gate and Phase 11D's *"wire onto the Ready capture."* The **rollback → Data-Integrity block + Admin-only override (11A) is unchanged** — only capture timing moves.

### IG-2 · Garage Arrival Event *(the official decision point)*
A dedicated step that fires when the driver **physically hands the car to the shop** (entry into the *At the Garage* stage) — **not** at dispatch. It captures four things:
- **(a) Garage Confirmation** — **confirm or override** the garage the Supervisor pre-assigned at dispatch.
- **(b) Arrival Odometer** — **Photo + Reading, mandatory.** This *is* the IG-1 `arrival` event.
- **(c) Intake Condition** — brief notes **or a Scratch flag**. A scratch flag hands off to the **Phase-2 Damage Assessment** chain (auto-draft Damage Evaluation → notify Abdullah & Waleed) — one intake, two records.
- **(d) Expected Completion** — a simple **ETA date** (feeds the Command-Center stale-task / SLA views).

**Authority model (dispatch = plan, arrival = decision).** The Supervisor's dispatch pick is the **suggestion/baseline** (`suggested_vendor_id`, already from Phase 3). The arrival confirmation is the **official decision** — so a driver can **divert** if the primary shop is full, and the divergence is **auditable**: arrival stamps the confirmed `vendor_id` + a `garage_overridden` flag + reason when it differs from the plan.

### IG-3 · Maintenance Recurrence flag ("Came Back Broken") *(a QC metric, not a stage)*
- **Not a board stage.** A **recurrence trigger**: if a car returns from the garage to **Ready**, and the **same fault** is reported again within a **short, configurable window**, the system **links the new ticket to the original** and raises a **`maintenance_recurrence`** audit flag.
- It answers *"was the repair actually fixed?"* — a **quality-control metric** surfaced in the Command Center / fault-insights, feeding the existing chronic-fault watchdog. Threshold `recurrence_window_days` (Admin setting).
- Reuses the findings/fault model + a parent-ticket link — **no new stage, no workflow change.**

### Migrations, endpoints & permissions
- **IG-1:** reuse `odometer_readings` (Phase 11A) with an added `event_type`; **NEW** `POST /maintenance-tickets/{ticket}/odometer` (standalone event capture, allowed at any status); `markReady` changes from *capture* to *verify* (returns the missing-events list rather than requiring a photo). Frontend: an always-visible **"Add Odometer Reading"** button on the ticket card + the standalone capture modal (reuses `photoTile` + `OdometerContinuityHint`).
- **IG-2:** **NEW** `POST /maintenance-tickets/{ticket}/arrival` — confirm/override garage, record the mandatory arrival odometer event, intake condition (+ optional scratch → Phase-2 draft), ETA. Adds `garage_overridden`, `garage_override_reason`, `expected_completion_date` on the ticket. Capture permission = `maintenance.logistics` (the driver/receiver).
- **IG-3:** **NEW** `maintenance_recurrence` flag + `original_ticket_id` link on the ticket; **NEW** detector (same vehicle + same fault reported within `recurrence_window_days` after Ready → link + flag); reviewed under `maintenance.manage`.

**Done when:** an odometer reading can be logged from the ticket **at any time** via an always-visible button; **`Mark Ready` only verifies** the pickup/arrival/exit set and prompts for anything missing (never forces an inline photo); a **Garage Arrival step** captures confirmed-garage + mandatory arrival odometer + intake condition + ETA when the car reaches the shop; a **driver's garage override is recorded against the Supervisor's plan**; and a **same-fault return within the window** auto-links to the original ticket and raises a `maintenance_recurrence` flag.

---

## Rev. 16 — Testing Logic & Data-Driven Garage Selection *(lean cleanup)*

**Intent:** keep the system **lean, automated, and strictly focused on Data-Driven Garage Selection + Odometer/Damage Integrity.** Rev. 16 *removes* two things — the automated **Stability Cycle Engine** (Phase 6: the 5-day cycle, the 1-hour Safety Block, and the 3-cycle graduation) and the **+5% mileage buffer** — and *adds* a **Preventive Testing Policy** (smart-trigger), baseline testing, **labor & time tracking**, and data-driven garage selection.

### 16.1 · Baseline Testing (Test vs. history)
Every new **Test** is compared against the **last Test's result** for that car — the previous odometer, findings, and pass/fail are the baseline. A Test reads as a *delta* against history, not in isolation, so regressions and repeat faults surface immediately (feeds the recurrence flag, IG-3).

### 16.2 · Preventive Testing Policy *(smart-trigger, not check-everything)*
Tests fire on **time-gaps and usage** — only when they add genuine value, so Abu Marouf (Technical Inspector & Tester) spends time on cars that actually need it while the fleet stays 100% safety-compliant. Four rules that compose:

- **Periodic Safety Refresh (15 days).** The system auto-triggers a **Periodic Test** for **every vehicle in the fleet every 15 days** *(including pre-owned fleet assets)* — so even **idle / unrented** cars stay in optimal condition. This is the safety *floor*: the longest a car may go untested.
- **Active-Fault → Under Test.** Any vehicle with an **active mechanical or electrical fault** is automatically set to **`Under Test`** status until cleared by Abu Marouf (the Technical Inspector & Tester).
- **Long-Term Rental Protocol (> 20 days).** Any rental **exceeding 20 days** auto-flags the car for a **Deep Inspection on return** — extended road use (tyres, oil, mechanical stress) is professionally verified before it re-enters the pool.
- **7-Day Safety Buffer (anti-redundancy).** The system will **not** trigger a new Periodic Test if the car was **already Tested within the last 7 days** and is still **Ready** — preventing redundant workload and wasted garage throughput.
- **Ready-State Retention.** A car that passed a Test and is **Ready keeps its Ready status without re-testing**, as long as it **hasn't been rented or had an incident** since — if it's already verified road-worthy, it stays available for immediate dispatch.
- **Short-Rental Loophole (the "1-Day Return").** If a car returns from a **short rental (≈ 24–48h)** and the visual **Check confirms zero incidents / no new damage**, it does **not** need a new mechanical Test — the system **retains the previous Test's validity**, provided that Test was **within the 7-day safety window**. Prevents needless bottlenecks for cars that saw no significant mechanical stress. (This is the counterpart to the Long-Term Rental Protocol: a *short* clean rental doesn't invalidate a still-valid Test; a *20+ day* one forces a Deep Inspection.)

**How they compose (reconciling the earlier "no redundant re-test"):** a Ready car isn't re-tested on every dispatch (Retention), nor within 7 days of its last Test (Buffer), nor after a short (≤ 48h) incident-free rental (Short-Rental Loophole) — but it **never goes longer than 15 days** without a Periodic Safety Refresh (floor); a return from a **20+ day** rental forces a Deep Inspection regardless. **Configurable intervals** (Admin settings): `periodic_test_days` (15), `long_rental_deep_inspect_days` (20), `test_buffer_days` (7), `short_rental_max_hours` (48). *(This 15-day periodic is a lightweight fleet-wide cadence — **not** a re-add of the removed Stability Cycle Engine: no `safety_block`, no strict 3-photo gate, no graduation.)*

### 16.3 · Labor & Time Tracking (per Test/Repair)
Record, for **every Test and repair**, the **labor cost** and the **time taken** (start → finish; reuses the existing line-items labor + repair-hours data). **Every Test is time-stamped and cost-accounted** — so the system can show, per garage, **which are efficient and which are lagging** (a per-garage efficiency view feeding the Data-Driven Garage Selection below + Phase-9 Cost Intelligence).

### 16.4 · Data-Driven Garage Selection (price a primary rank)
Garage routing (Phase 3) ranks by clear criteria — **price**, completion speed, parts availability, proximity, warranty, specialization — with **price/cost surfaced as a primary selection rank**, backed by the Phase-9 Cost-Intelligence prediction shown at the moment of selection. Selection is by the data, not by relationship; the ranking is the record.

**Done when:** a Test shows its delta vs. the last Test; a Ready car is **not** re-tested on every dispatch nor within 7 days of its last Test, yet **never goes >15 days** without a Periodic Safety Refresh; a **20+ day rental forces a Deep Inspection on return**; every Test/repair logs **labor cost + duration** into a per-garage efficiency view; and garage selection leads with price + the cost prediction.

---

## Revised sequencing

| Order | Phase | Change |
|---|---|---|
| 0 | Foundation — severity re-map + safety-fault list | unchanged |
| 1 | **Locked-Down Handover** — Readiness (Pre-Rental Gate) + **Check-Out/In (Check-In Gate) + Signature** | pulled forward |
| 2 | **Damage Evaluation & Incident Capture** — scratch→notify Supervisors→assess/cost/confirm→auto In-Repair + Abu Marouf task; 9-category; **Support Flow** | pulled forward · roles finalized Rev. 5 |
| 2.5 | **Customer & Deposit Protection** (deposit ledger + fine hold) | new — Rev. 3 · risk-flag removed Rev. 16 |
| 3 | Garage Routing Bridge | was Phase B |
| 4 | Financials & Approval Matrix | was Phase C · now needs P2 |
| 5 | QA / Road Test / Alerts | was Phase D |
| 6 | **Inspector Protocols** (Library only — routine + battery) | Rev. 4 · **Stability Cycle Engine removed Rev. 16** |
| 7 | **Safety-Decision Audit** (keyword suggestion → conflict → Admin audit) | new — Rev. 6 · independent |
| 8 | **Command Center** (activity feed + live tracker + management overview) | new — Rev. 8 · independent |
| 9 | **Cost Intelligence** (price predictor + garage comparison + variance; `garage_cost_stats`) | new — Rev. 10 · extends Phase 3/4 |
| 10 | **Accident-to-Garage Communication Chain** (receipted, guarded, non-dismissible) | new — Rev. 10 · hardens Rev. 5 chain |
| 11 | **Usage Monitoring & Mileage Enforcement** (odometer hard-gate + GPS policing + usage dashboard + **shop-movement integrity gate**) | new — Rev. 11 · GPS half needs a feed · **11D shop-movement gate Rev. 13** |
| 12 | **Operational Integrity Gate** (event-driven odometer + Garage Arrival Event + Maintenance Recurrence flag) | new — Rev. 14 · **cross-cutting, refines P3/P5/P11** |

The lifecycle now reads end-to-end: **Handover (documented + signed) → Return / Incident (structured damage) → Protect the cash (deposit · fines · risk) → Route to garage → Approve cost → QA & release — every car-move captured as a verifiable event.** Nothing deferred.

---

## Sign-off — points for Omar to confirm

1. ~~**Yellow grade:** rentable-with-acknowledgment?~~ ✅ **REVISED (Rev. 14 — reverses Rev. 7):** Yellow is now **NON-rentable**, treated **like Red** (pulled from the pool → maintenance); only Green & Orange rent. Already live in the shipped gate (`rentBlockedByCondition()` covers red + yellow; the ack is orange-only).
2. **Safety-fault list:** confirm the categories that trigger a booking block, Safety-Critical severity/damage, and a road test.
3. **Photo list & signature:** confirm the 15-item mandatory set (+ 360° for luxury) and whether customer sign-off is **signature, OTP, or both**.
4. **Damage form:** confirm the field options (esp. liable-party + decision) match Omar's evaluation sheet.
5. **Approval tiers:** confirm the AED bands and which real managers fill Supervisor / Ops-Fleet / Branch / GM. *(Rev. 7: Valuation ≠ Approval is locked — a supervisor valuates; the matrix approves. Just confirm each supervisor's own tier ceiling = his "valuator limit".)*
6. **Sell / Replace ratio:** the 12-month-cost-to-value threshold that fires the alert — **set to 40%**.
7. **Deposit & fines:** confirm the fine-hold window (e.g. 14 vs 30 days) and whether a refund balance goes to the customer **wallet or cash**.
8. ~~**Stability Cycle (Rev. 9):**~~ ❌ **REMOVED (Rev. 16):** the automated Stability Cycle Engine — the 5-day post-accident cycle, the *missed-by-1-hour → Safety Block*, and the *3-clean-cycles graduation* — is **dropped entirely** (it was never in the shipped code). Post-accident follow-up now runs through the ordinary **Check / Test** triggers + the Phase-6 Inspector Protocol Library. Nothing to confirm.
9. **Roles:** confirm Abdullah & Waleed are the two `damage.assess` **Supervisors** (review / parts / cost / confirm), Abu Marouf (Technical Inspector & Tester) **executes the Test**, and that a Supervisor confirm should auto-flip the car to **In-Repair**.
10. **Safety-Decision Audit:** confirm the conflict thresholds (Major/Safety-Critical marked rentable, and Minor marked non-rentable), and that the instant alert goes to **you (Admin)** — plus whether a dedicated `audit.review` reviewer is wanted.
11. **Command Center:** confirm the **stale-task threshold** (X hours before a claimed-but-unfinished task is flagged), and who gets `command_center.view` (Admin + Omar + managers?).
12. **Cost Intelligence:** confirm the **High-Cost Warning threshold** (>20% over historical average?) and that it stays **non-blocking** (premium garages allowed, just flagged).
13. **Accident chain:** confirm Step-C is a **hard visibility guard** (Abu Marouf can't see the ticket pre-assessment), and that the Supervisor notification is **non-dismissible** until they Submit Assessment.
14. ~~**Usage Monitoring:**~~ ✅ **RESOLVED:** Data-Integrity override is **Admin-only** (supervisors cannot override) · **11B (GPS/geo-fence) deferred** until a live feed is connected — build **11A + the 11C dashboard scaffolding** now. *(Rev. 16: the +5% buffer is removed — excess-mileage = simply over the contractual allowance.)*
15. **Odometer Integrity Gate (11D, Rev. 13):** confirm the **Shop-Movement threshold = 20 km**, configurable in **Admin settings** (`shop_movement_threshold_km`); that **Net Shop Distance** is measured **garage-in → garage-out** (`receive`/`dispatch` → `return`); that a breach is **non-blocking** (stamps `excessive_shop_movement` + fires an instant Admin alert, but the car still releases); and that the **garage-in reading becomes mandatory** so the distance is always computable.
16. **Operational Integrity Gate (Rev. 14):** confirm **(IG-1)** odometer capture becomes **event-driven** — an always-on "Add Odometer Reading" button, and **`Mark Ready` only verifies** the pickup/arrival/exit set (no longer the forced photo point); **(IG-2)** the **Garage Arrival Event** captures garage confirm/override + mandatory arrival odometer + intake condition (scratch → Damage Assessment) + ETA, with **dispatch = the Supervisor's plan and arrival = the official decision** (driver may divert, divergence audited); and **(IG-3)** "Came Back Broken" is a **Maintenance Recurrence QC flag** (same-fault return within `recurrence_window_days` → links to the original ticket), **not a new board stage**. Confirm the **recurrence window** (e.g. 7 / 14 days).
17. **Roles & Check/Test (Rev. 15):** confirm **Abdullah & Waleed = Supervisors** (review & approve — not company managers) and **Abu Marouf = Technical Inspector & Tester** (execution only, no liability / cost / scope decisions); that a **Check** (visual, on return) auto-notifies the Supervisor to review & approve while a **Test** (technical) is Abu Marouf's; and the **Check → Test auto-escalation** (visual impact damage that may be mechanical → auto mechanical Test task for Abu Marouf). *(Ripple: the Phase-7 Safety-Decision Audit now audits the **Supervisor's** severity/rentable decision, not the tester's — confirm that's the intent.)*
18. **Testing logic & garage selection (Rev. 16):** confirm **Baseline Testing** (each Test compared to the last Test's history); the **Preventive Testing Policy** — **15-day Periodic Safety Refresh** (even idle cars) · **Deep Inspection after 20+ day rentals** · **7-day anti-redundancy buffer** · **Ready-State Retention** (confirm the **15 / 20 / 7-day** intervals, all Admin-configurable); **Labor & Time Tracking** on every Test/repair (time-stamped + cost-accounted → per-garage efficiency); and **price as the primary garage-selection rank** (backed by Cost-Intelligence). Also note the **removals**: the Stability Cycle Engine, the +5% mileage buffer, and the Customer Risk Flag are dropped.

---

## Practical Workflow Scenario — Lifecycle of a Repair Ticket

**A concrete example** of how the system bridges a reported fault and the vehicle's return to the fleet. Ticket **#169573**, **Mercedes GLC 300** (fault: dim / foggy lights · severity: 🟡 Moderate). *Times below are illustrative — replace with the ticket's live system data.*

| Stage | What happens | Cumulative downtime | Automated transition |
|---|---|---|---|
| 1. Inspection Requested | Fault logged; the real-time downtime clock starts. | 0:00 | Ticket opened + assigned to an inspector |
| 2. Being Inspected | Test-drive + diagnostic + odometer capture. | ~0:20 | On severity set → Awaiting Dispatch |
| 3. Awaiting Dispatch | Supervisor picks the garage (price-first) + assigns a driver. | ~0:45 | Dispatch confirmed → pickup task to driver |
| 4. Dispatch → At Garage | Driver captures arrival odometer + photo, hands the car to the shop. | ~2:30 | Arrival odometer + garage → **auto "Under Repair"** |
| 5. Under Repair | Garage performs the repair; downtime keeps counting. | ~1 day | Garage "Ready" → re-inspection task |
| 6. Ready for Re-inspection | Abu Marouf runs the Test, verifies readiness + exit odometer. | ~1d 3:00 | Pass → QA |
| 7. Quality Assurance | Full itemized checklist (+ road test if required). | ~1d 5:00 | All items pass → Ready |
| 8. Closed | Vehicle returns to Available; **the downtime clock stops**. | **~1d 6:00 (total)** | Close → vehicle status → Available |

- **Real-time downtime tracking:** a clock starts at the fault report and stops at Close — management sees exactly how long the car was out of service, and *where* the time went (dispatch / repair / QA).
- **Visualized progress:** a stage bar (Dispatch → Repair → Ready); each card shows the current stage + its age, red-flagged past 4 days in the garage.
- **Automated status transitions:** no manual status-pushing — the arrival odometer moves it to Under Repair, the garage "Ready" opens re-inspection, and Close returns it to Available.

**Takeaway (for management):** from fault report to the Mercedes returning to the fleet, the system measures downtime live, moves the ticket between stages automatically, and leaves a full audit trail — turning a "reported fault" into a "ready vehicle" by the shortest, clearest path.
