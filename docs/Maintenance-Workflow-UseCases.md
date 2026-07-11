# Fleet Maintenance Workflow — Use Cases

**Prepared for:** Management review
**Module:** Digital Maintenance Workflow (replaces the WhatsApp maintenance relay)
**Date:** 28 June 2026
**Status:** Live in the system (backend + screens shipped)

---

## 1. The Problem We Solved

Today a car that needs the workshop is handled over WhatsApp messages between the inspector,
the drivers, and the workshop controllers. That means:

- No record of **who** asked for what, or **when**.
- Cars sit in the workshop and nobody can say at a glance **how many** or **for how long**.
- Costs and downtime are not tied back to the car's history.
- A car can be sent to the workshop while it is **still rented** — with no warning.

We replaced that chat thread with a **single, enforced digital workflow**. Every car that needs
attention now moves through clear stages, each handled by the right person, with a permanent record.

---

## 2. The People Involved (Roles)

| Role | Real person / team | What they do |
|------|--------------------|--------------|
| **Inspector** | Abu Maroof | Test-drives cars, diagnoses problems, does the final sign-off |
| **Logistics / Driver** | Drivers | Request inspections, move cars to/from the garage |
| **Controller / Manager** | Workshop controllers | Oversee repairs and approve closing |

The system only lets each role do **their** part of the process — a driver cannot close a repair,
a garage cannot overwrite the inspector's findings, and so on.

---

## 3. The Journey of a Car (End to End)

```
   Driver            Inspector           Inspector          Logistics          Garage            Inspector
  request    →     diagnose      →     file report   →     dispatch    →     repair      →      sign-off
 inspection       (test drive)       (needs work?)       (to garage)      (fix + notes)        (close)
     │                  │                  │                   │                 │                  │
  REQUESTED  →     DIAGNOSTIC   →    PENDING/CLEARED  →   IN TRANSIT   →   UNDER REPAIR  →        READY → CLOSED
```

At every arrow, the **next responsible person is automatically notified** in the app — no more
chasing people on WhatsApp.

---

## 4. Use Cases

### UC-1 — Driver requests an inspection
**Who:** Driver
**Goal:** Flag a car that seems to have a problem.

A driver opens the app, picks the car, and submits an inspection request with a short note
(e.g. "pulling to the left when braking"). The request lands in the Inspector's queue and
**Abu Maroof is notified immediately**. The car is **not** yet counted as "in the workshop" — it
is only a request.

> **Business value:** every problem report is now logged with who raised it and when, instead of a chat message that gets lost.

---

### UC-2 — Inspector runs a diagnostic (test drive)
**Who:** Inspector
**Goal:** Decide whether the car actually needs the workshop.

The inspector picks up the request (or starts one directly) and runs a **test drive**. He records
his findings using a **ready-made checklist of common issues** (engine, brakes, tyres, electrical,
A/C, bodywork…) plus any custom note.

> **Business value:** a diagnostic step **before** a workshop ticket means healthy cars never get sent to the garage unnecessarily — saving downtime and cost.

---

### UC-3 — Inspector files the report (needs work, or all clear)
**Who:** Inspector
**Goal:** Turn a diagnosis into a decision.

- **Needs maintenance** → a **maintenance ticket is created** and the Logistics team is notified to move the car.
- **No maintenance needed** → the car is **cleared** and released. No ticket, no downtime.

Either way the report is **saved as a permanent record**, and the **driver who first requested it
is told the result** automatically.

> **Business value:** a clear, auditable yes/no decision — and the person who raised the issue always hears back.

---

### UC-4 — Logistics dispatches the car to the garage
**Who:** Logistics / Driver
**Goal:** Physically move the car to the workshop, on the record.

The driver records the **odometer reading**, attaches an **odometer photo** (taken on the phone),
and selects the **garage/vendor**. Only now is the car officially marked **"in the workshop"**, and
the workshop controllers are notified.

The system also **automatically links the car's open maintenance contract** to the ticket.

> **Business value:** mileage and a photo are captured at hand-over, so there is no dispute about the car's condition or reading when it went in.

---

### UC-5 — Garage repairs the car and adds findings
**Who:** Garage (via Logistics)
**Goal:** Record what was actually done.

The car is marked **under repair**. The garage can **add its own findings** on top of the
inspector's — but it **cannot edit or re-report** what the inspector already filed (those entries are
locked). Inspector findings and garage findings are kept in **two clearly-labelled buckets**.

> **Business value:** a clean, tamper-proof trail of who-found-what — useful for warranty claims and for spotting recurring faults.

---

### UC-6 — Car is ready; inspector signs off and closes
**Who:** Inspector
**Goal:** Confirm the repair and return the car to service.

When the garage marks the car **ready**, it must capture a **final odometer reading + photo**
(the "after" picture matching the "before"). The inspector then re-inspects and **closes** the ticket
— which requires the **cost and vendor** to be recorded. The car is automatically returned to
**available** status.

> **Business value:** no car is returned to the fleet without a documented cost, a final reading, and a sign-off.

---

### UC-7 — Follow-ups while a car is in the workshop
**Who:** Driver / Logistics
**Goal:** Keep a conversation attached to the car, not in WhatsApp.

While a car is in transit or under repair, drivers can post **follow-up notes** on the ticket
(e.g. "garage says part arrives Sunday"). The controllers are notified.

> **Business value:** the status conversation lives **on the car's record**, visible to everyone, forever.

---

### UC-8 — The live workshop board
**Who:** Everyone
**Goal:** See the whole workshop at a glance.

A single **pipeline board** shows every car in its current lane:

```
 Requested │ Diagnostic │ Pending │ In Transit │ Under Repair │ Ready
    (2)     │    (1)     │   (3)   │    (1)     │     (4)      │  (1)
```

It refreshes automatically and highlights the **busiest lane (bottleneck)**.

> **Business value:** management can answer "how many cars are in the workshop and where are they stuck?" in one look.

---

### UC-9 — "My Queue" — each person sees only their own work
**Who:** Inspector / Driver
**Goal:** Know what *I* need to do next.

- **Inspector** sees: pending inspections + cars awaiting final sign-off.
- **Driver** sees: active dispatches + cars waiting for a follow-up.

> **Business value:** no one has to scan the whole board to find their tasks.

---

### UC-10 — Full history on the car's profile
**Who:** Management / Anyone
**Goal:** See everything that ever happened to a car.

Each vehicle profile shows a **unified timeline**: every workflow step (tagged Test-Drive vs Repair),
every follow-up note, and a **before/after photo gallery** of odometer and condition shots, plus a
**health banner** (in workshop / service due / healthy).

> **Business value:** the complete maintenance story of any car, on one screen — for resale, audits, or disputes.

---

### UC-11 — Guardrail: no workshop while rented
**Who:** System (automatic)
**Goal:** Protect revenue.

If a car is rented, opening a maintenance ticket **never steals its "rented" status**, and any
rental↔maintenance overlap is **flagged as a violation** on the operations dashboard.

> **Business value:** the system actively prevents the costly mistake of pulling a car that a customer is still paying for.

---

## 5. What the Business Gets

| Before (WhatsApp) | After (this workflow) |
|-------------------|------------------------|
| Requests lost in chat | Every request logged with author + time |
| No idea how many cars in workshop | Live board with counts and bottleneck |
| No proof of mileage/condition at hand-over | Odometer reading + photo, before and after |
| Costs not tied to the car | Cost + vendor required to close, on the car's record |
| Anyone could overwrite anyone | Role-enforced steps; findings are tamper-proof |
| Cars pulled while rented | Automatic guardrail blocks/flags it |
| No history | Full per-car timeline with photos |

---

## 6. Status

- **Backend (rules & data):** built and verified.
- **Screens:** Workshop board, action forms, My Queue, and the vehicle timeline — all shipped.
- **Notifications:** in-app alerts route to the next responsible role automatically.
- **Next (optional):** instant push notifications instead of the current 6-second auto-refresh, and
  an automated inspection-comparison engine.

---

*This document describes functionality already implemented in the Fleet system. It is intended as a
plain-language overview for management; the technical design lives in the engineering notes.*
