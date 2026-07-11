# CRUD & Notification Smoke Suite

A live, end-to-end test suite that boots the **real** Laravel app (real routes,
middleware, Sanctum auth, Spatie permissions) against an **isolated MySQL schema**
`laravel_test` — the same engine as production (MariaDB via XAMPP), so it exercises
exactly what the demo will show, **without ever touching the live `laravel` data**.

## Run it

```cmd
cd backend
run-crud-tests.cmd                     REM whole suite
run-crud-tests.cmd NotificationTest    REM just one class
```

or directly:

```cmd
vendor\bin\phpunit -c phpunit.crud.xml
```

## Why a separate MySQL schema (not sqlite)?

The default `phpunit.xml` uses sqlite `:memory:` for pure unit tests. But one data
backfill migration uses MySQL's `NOW()`, which sqlite lacks — and more importantly,
the demo runs on MySQL, so the tests should too. `phpunit.crud.xml` points at
`laravel_test`; `RefreshDatabase` migrates it once and wraps each test in a
rolled-back transaction.

## What's covered

| Area | File | Highlights |
|------|------|-----------|
| Harness | `HarnessSmokeTest` | auth wall (401), super-admin bypass |
| Core CRUD | `CoreCrudTest` | Vendors, Drivers, Customers, Vehicles, Contracts, Invoices, Payments — full create→read→update→delete + validation guards |
| **Notifications** | `NotificationTest` | bell CRUD (list/poll/read/read-all/dismiss/clear), demo pipeline, admin Test Console (all 4 triggers, real + forced), broadcast fan-out, per-user isolation, `notifications:scan` |
| Reminders | `RemindersAndSchedulesTest` | service reminders (+complete/notify), contact reminders, inspection schedules (+complete) |
| Read sweep | `ReadEndpointsSmokeTest` | every param-less GET endpoint (~70) asserted to never 5xx |
| Lifecycles | `OperationsWorkflowTest` | start/close a movement, logistics one-way + garage round trip (odometer + photo gate), complaint → maintenance ticket |

**119 tests / 244 assertions — all green.**
