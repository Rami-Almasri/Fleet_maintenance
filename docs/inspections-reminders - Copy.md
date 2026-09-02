# Inspections & Reminders — API & Technical Reference

Fleetio-style **Inspections** and **Reminders** modules. Two sidebar sections, four pages,
three new tables, backed by the endpoints below. Shipped on `ui-overhaul-v1` (2026-07-05).

- **Base URL:** `/api` (dev: `http://127.0.0.1:8000/api`)
- **Auth:** Laravel Sanctum — every request needs `Authorization: Bearer <token>` and `Accept: application/json`.
- **Writes use POST**, not PUT (project convention). Model-bound `{id}` params.
- **Response envelope:** all responses are wrapped as `{ "data": <payload>, "message": "…" }`.
- **Permissions** (Spatie): `inspections.view/manage` for inspections; `reminders.view/manage` for reminders.
  `super-admin` bypasses all checks.

---

## Data model

| Table | Purpose | Key columns |
|---|---|---|
| `inspection_schedules` | Recurring safety/ops inspection **plans** | `vehicle_id, name, pillar, interval_type(time\|meter\|both), interval_days, interval_km, last_inspected_at, last_inspected_odometer, next_due_at, next_due_odometer, assigned_to, active` |
| `service_reminders` | Recurring **technical maintenance** due points | `vehicle_id, service_type, interval_km, interval_days, last_service_odometer, last_service_at, next_due_odometer, next_due_at, source(auto\|manual), is_muted, active` — unique `(vehicle_id, service_type)` |
| `contact_reminders` | "Call {vendor} about {subject}" follow-ups | `vendor_id, subject, body, due_at, status(open\|done\|snoozed), invoice_id, maintenance_id, created_by, assigned_to, completed_at, completed_by` |

**Derived status** (returned on every read, computed live from the car's current odometer/clock):
`overdue | due_soon | ok | no_data`. Fields: `status`, `status_label`, `km_remaining`, `days_remaining`.
Service-reminder km maths mirror `Vehicle::serviceStatus()` so the numbers always agree.

---

## Inspection Schedules — `inspections.view` / `inspections.manage`

| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/InspectionSchedules` | view | List. Filters: `?vehicle_id` `?status=overdue\|due_soon\|ok` `?active=0\|1` |
| POST | `/InspectionSchedules` | manage | Create |
| GET | `/InspectionSchedules/{id}` | view | Show |
| POST | `/InspectionSchedules/{id}/complete` | manage | Log a completed inspection; rolls `next_due_*` forward. Body: `{ "odometer": 84210 }` (optional; defaults to the car's current odometer) |
| POST | `/InspectionSchedules/{id}` | manage | Update (partial) |
| DELETE | `/InspectionSchedules/{id}` | manage | Delete |

**Create body:**
```json
{
  "vehicle_id": 1595,
  "name": "Weekly Safety Walk-around",
  "pillar": "safety",
  "interval_type": "time",
  "interval_days": 7,
  "interval_km": null,
  "assigned_to": 4,
  "notes": "Tyres, lights, fluids"
}
```

---

## Service Reminders — `reminders.view` / `reminders.manage`

| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/ServiceReminders` | view | List. Filters: `?vehicle_id` `?source=auto\|manual` `?status` `?active` |
| POST | `/ServiceReminders` | manage | Create (manual). One per `(vehicle_id, service_type)` |
| GET | `/ServiceReminders/{id}` | view | Show |
| POST | `/ServiceReminders/{id}/complete` | manage | Log the service; rolls `next_due_*` forward. Body: `{ "odometer": 220100 }` (optional) |
| POST | `/ServiceReminders/{id}` | manage | Update — **flips `source` to `manual`** so the auto-seed never overwrites it |
| DELETE | `/ServiceReminders/{id}` | manage | Delete |

**Create body:**
```json
{
  "vehicle_id": 1595,
  "service_type": "brake_pads",
  "name": "Brake Pads",
  "interval_km": 40000,
  "last_service_odometer": 180000
}
```

**Auto-derivation:** the `oil_change` reminder for each car is created/refreshed by
`php artisan service:sync-reminders` (scheduled daily 04:00) from the Oil Change sheet data
(`vehicles.last_service_odometer` + `service_interval_km`). Manual edits are protected (`source=manual`).

---

## Contact Reminders — `reminders.view` / `reminders.manage`

| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/ContactReminders` | view | List. Filters: `?vendor_id` `?status=open\|done\|snoozed` `?assigned_to` |
| POST | `/ContactReminders` | manage | Create |
| GET | `/ContactReminders/{id}` | view | Show |
| POST | `/ContactReminders/{id}` | manage | Update. Setting `status=done` stamps `completed_at`/`completed_by`; re-opening clears them |
| DELETE | `/ContactReminders/{id}` | manage | Delete |

**Create body** (`vendor_id` required; `invoice_id`/`maintenance_id` optional click-through targets):
```json
{
  "vendor_id": 12,
  "subject": "Chase invoice for ticket #482",
  "body": "Garage hasn't sent the itemised invoice yet.",
  "due_at": "2026-07-10T09:00:00",
  "maintenance_id": 482,
  "invoice_id": null,
  "assigned_to": 4
}
```
`is_overdue` (bool) is returned on each row = `status=open` AND `due_at` in the past.
The resource also embeds `vendor{name,phone}`, `invoice{invoice_no,contract_id}`, `maintenance{car_label,plate}`
so a client can render who-to-call and jump straight to the record.

---

## Inspection History (reuses the existing Inspections endpoint)

| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/Inspections` | `inspections.view` | List submitted inspection records. Filters: `?vehicle_id` `?contract_id`. Returns newest-first, capped 500 |

Reads the `inspection_records` table (condition photos + manual damage findings). Photo URLs are
short-lived signed links.

---

## Scheduler

The only OS-level requirement is the task that runs `php artisan schedule:run` every minute
(the same one driving `om:sync`, `notifications:scan`, etc.). Registered jobs relevant here
(`routes/console.php`):

- `service:sync-reminders` — daily **04:00** — keeps auto oil-change reminders in step with the sheet.
- `notifications:scan` — every 10 min — raises in-app bell alerts, incl. overdue service/contact reminders.

Note: the overdue/due-soon **status is computed live on every read**, so it is never stale between runs.
