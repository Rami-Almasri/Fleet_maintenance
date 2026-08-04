# Intelligence Rebuild — Operational Requirement

**Status:** PRODUCTION REQUIREMENT, not a nice-to-have
**Owner:** Fleet Intelligence · **Applies from:** recurrence contract v2.0.0 (2026-08-04)

---

## 1. Why this is a requirement now

Before convergence, six services each computed recurrence from raw `maintenance_signatures`. If one
broke, five kept working and the failure was visible as a disagreement between pages.

After convergence, **every recurrence figure on the platform reads two derived tables**:

```
fault_recurrence_pairs   ← the fleet baseline, every garage score, Repair Intelligence,
                           outcome learning, the Executive dashboard
repair_visits            ← every per-repair metric
```

That is the correct trade — a consistently wrong number is detectable, six quietly inconsistent ones
are not — but it changes what failure looks like.

### The failure has no symptom

If the nightly rebuild stops:

- the tables keep serving
- every page renders
- every figure still carries its sample size, its coverage badge and its `as_of`
- **nothing appears broken**

and the entire platform answers out of a corpus that stopped growing on whatever night the scheduler
died. The numbers do not become wrong in a way anyone can see. They become **old**, while continuing
to look exactly as authoritative as they did the day before.

> ⚠ **The scheduler is dead on developer machines and was unverified on the server at the time of
> writing.** This is not hypothetical.

---

## 2. What must run, and when

| Command | Schedule | Typical runtime | Depends on |
|---|---|---:|---|
| `intelligence:rebuild-visits` | daily **04:20** | ~2.5s | `maintenances` |
| `intelligence:rebuild-recurrence` | daily **04:35** | ~3.0s | `maintenance_signatures`, must run **after** visits |

Both are registered in `routes/console.php` with `withoutOverlapping()`. Verify with:

```
php artisan schedule:list | grep intelligence:rebuild
```

### Sequencing matters

They sit after the existing nightly chain — `om:sync` (03:00), `import:vehicle-status` (03:15),
`intelligence:rebuild-signatures` (03:05) — so they read the day's rows and the day's fault labels.
Running recurrence *before* visits would mix one table's view of the corpus with the other's.

---

## 3. Monitoring

```
php artisan intelligence:rebuild-health            # inspect
php artisan intelligence:rebuild-health --alert    # exit 1 when stale — for cron/monitoring
```

Every run, success or failure, writes a row to `intelligence_rebuild_runs` recording duration, rows
read and written, the corpus edge it saw, and the metric contract version that produced it.

| Field | Meaning |
|---|---|
| `last_rebuilt_at` | when the last **successful** rebuild finished |
| `age_hours` | how old the derived data is |
| `is_stale` | `age_hours > 36`, **or no successful run at all** |
| `corpus_max_date` | the observation horizon this build used — what `as_of` resolves to |
| `metric_version` | which governed definition produced these rows |
| `failures_since_success` | consecutive failures — a rising number is the early warning |

**"Never rebuilt" counts as stale.** Treating an absent record as healthy is exactly how a dead
scheduler stays invisible.

### Recommended alert

```
0 6 * * *  cd /path/to/backend && php artisan intelligence:rebuild-health --alert
```

Fires at 06:00, ninety minutes after the rebuild window, so a failed or skipped night is caught
before anyone opens a dashboard.

---

## 4. What a failure does NOT do

The rebuilds are **staging → validate → atomic swap**:

1. rows are built into a staging table
2. validation runs (row conservation, no sub-day gaps, censoring stamped, plausible censored share)
3. only then does an atomic `RENAME` swap it into place

**A failed validation aborts before the swap, so the previous known-good table keeps serving.** A
stale number is recoverable; a silently wrong one is not. This is why a failure shows up as ageing
data rather than as corrupted data.

Both commands are idempotent — verified by rebuilding twice and comparing checksums — so re-running
after a failure is always safe.

---

## 5. Recovery

```
cd backend
php artisan intelligence:rebuild-visits
php artisan intelligence:rebuild-recurrence
php artisan intelligence:rebuild-health          # confirm fresh
php artisan intelligence:convergence-audit       # confirm surfaces still agree
```

Then find out **why** the scheduler stopped. A manual rebuild fixes today's number and not tomorrow's.

---

## 6. Outstanding — staleness is not yet surfaced in the UI

**`RebuildLedger` is not wired to any user-facing surface.** Staleness is currently visible only
through the CLI.

The requirement is that a stale corpus should be announced on the pages that depend on it, rather
than those pages rendering old analytics as current. Every backend piece exists — the ledger, the
health API, and `as_of` already travelling on every `Kpi` and on the scorecard payload — but the
frontend does not yet read them.

**Until that lands, the CLI alert in §3 is the only protection.** It should be scheduled before this
is considered operationally complete.

| Piece | Status |
|---|---|
| Ledger records every run | ✅ |
| `health()` / `anyStale()` API | ✅ |
| CLI inspection + `--alert` exit code | ✅ |
| `as_of` on every KPI and on the scorecard payload | ✅ |
| **Cron alert scheduled on the server** | ❌ **do this** |
| **Data Health page shows `built_at` + staleness banner** | ❌ **outstanding** |
| **Dashboards banner when the corpus is stale** | ❌ **outstanding** |
