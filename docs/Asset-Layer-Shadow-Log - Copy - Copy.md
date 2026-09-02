# Asset Layer — Shadow Validation Log

**SHADOW_START: 2026-07-23 10:25 (local)** · Flag: `ASSET_LAYER_MODE=shadow` · Gate target: 2026-08-06 (2 weeks) · Plan: `docs/Asset-Layer-Shadow-Launch-Plan.md` (§2 monitoring, §3 validation, §5 gate, §7 recovery).

## Launch verification (2026-07-23)

| Check (§7.5) | Result |
|---|---|
| Database backup | ✅ `storage/app/backups/laravel-20260723-102022.sql` (74.35 MB) |
| Rollback tested on prod copy (all 6 migrations) | ✅ `migrate:rollback --step=6` on fresh clone — 0 asset tables / 0 columns left |
| Migrations on live | ✅ all six `2026_07_24_1000xx` Ran (5 on 2026-07-23 morning, `100500` at launch) |
| Seeders | ✅ catalog = 21 rows; grant matrix verified (manage: manager/maintenance/supervisor only) |
| Flag-OFF smoke (real install path, rolled back) | ✅ purchase installed, `components_in_txn=0` — byte-identical |
| Flag flip | ✅ `.env` `ASSET_LAYER_MODE=shadow` + `config:clear` |
| Shadow smoke (real install path, rolled back) | ✅ component created: `write_mode=shadow`, `validation=provisional`, `status=active`, 1 event, 1 timeline mirror, `warranty_until` derived (+12m); zero residue after rollback |
| Baseline audit | ✅ `components:shadow-audit` → "No shadow component writes found" (clean start) |
| Test suites | ✅ Asset P1+P2: 31 tests / 193 assertions green; full Crud 191 tests, only the 2 pre-existing (non-asset) failures |
| First REAL workshop install observed | ⬜ pending — record here when the first genuine install lands |

## Daily log

> Template per entry: date · installs today · shadow rows created · M1 gaps (each reconciled against `laravel.log`) · M2/M3/M4 hits (must be 0) · `position_missing` count · notes.

| Date | Installs | Shadow rows | M1 gaps (explained?) | M2/M3/M4 | position_missing | Notes |
|---|---|---|---|---|---|---|
| 2026-07-23 | — launch day — | 0 | 0 | 0/0/0 | 0 | Launch verified (above). |

## Weekly physical spot-checks (§3)

| Date | Vehicle | Checked by | Recorded vs physical | Removed-part whereabouts | Verdict |
|---|---|---|---|---|---|
| | | | | | |

## Corrections & quarantines (§7.2)

| Date | Component(s) | Action (remove/quarantine) | Reason | By |
|---|---|---|---|---|
| | | | | |

## Gate review (target 2026-08-06, §5 — final 7 days)

- [ ] ≥95% coverage, 100% of gaps explained
- [ ] 0 × M2/M3/M4 + invalid-state classes, whole window
- [ ] All spot-checks match; 0 phantoms ever
- [ ] 0 billing/workflow failures from the hook
- [ ] ≥80% warranty coverage; 100% derivation; ≥1 real predecessor flow exercised
- [ ] Transfer/removal event chains correct
- [ ] position_missing + predecessor-gap rates known; workshop briefed on the two prompts that become blocking
- Decision: ______ (enforce / extend / rollback) — then `components:shadow-audit --promote` before any flip.
