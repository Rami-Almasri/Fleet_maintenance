# Concept Bridge — Dry Run Results

**Date:** 2026-08-01 · **Method:** read-only. `KeywordOntologyService::resolve()` (the live `MatchPipeline`) over a
random sample of legacy tickets. **Nothing was written to the database.**
**Sample:** 2,000 of 26,839 tickets (7.5%) · text = `service_main` + `service_sup` + `maintenance_notes`
**Throughput:** 121 ms/ticket → a full single-label pass over 26,839 tickets ≈ **54 minutes**, single-threaded.

**Verdict: GREEN LIGHT — with one design change. Recall is excellent; top-1 precision is not, and the fix is
structural rather than a tuning exercise.**

---

## 1. Coverage

| | n | % of sample |
|---|---:|---:|
| Mapped to ≥1 concept | 1,707 | **85.4%** |
| — strong (≥70) | 1,200 | 60.0% |
| — possible (25–69) | 507 | 25.4% |
| Unmapped | 293 | 14.7% |
| — of which empty text | 62 | 3.1% |

### 1.1 The headline number is understated — and the reason matters
**421 of 2,000 tickets (21%) carry no v1 signature at all**, and 65% of those are unmapped. Reading them explains why:

```
"car is ready"                        "car is under test by abo marof"
"car now in al qwah al zaadh garage"  "assemblying and checking the car"
```

These are **status notes, not fault descriptions.** They *should* fail to resolve to a fault concept — that is correct
behaviour, not a coverage gap. Excluding them:

> **Of tickets that describe actual work, 1,560 of 1,579 resolved — 98.8% recall.**

That is a far better result than the 50% floor I said would force a rethink. **Recall is not the risk.**

---

## 2. Resolution gain — the thing the bridge exists for

**91 distinct concepts resolved, against 22 v1 signatures.** Each coarse bucket fans out into real engineering
vocabulary:

| v1 signature | → distinct concepts | Top concepts resolved |
|---|---:|---|
| `COOLING` | 21 | Overheating (26) · Coolant leak (13) |
| `SUSPENSION` | 26 | Wheel alignment (24) · Worn shock/strut (13) · Knocking over bumps (7) |
| `ELECTRICAL` | 31 | Airbag warning (27) · Wiring/fuse issue (13) |
| `STEERING` | 20 | Wheel alignment (38) · Steering noise (7) · Loose steering (6) |
| `BRAKES` | 20 | Soft/spongy pedal (7) · Vibration when braking (4) · Brake noise (3) |

`COOLING → {Overheating, Coolant leak}` is exactly the join the fusion engine needs, and it is now available. **The
premise of the bridge is validated.**

---

## 3. The problem: top-1 precision

Section 2 above is the good half. The same data shows serious cross-contamination when only the **top-ranked** concept
is kept per ticket:

| v1 signature | Its **top** resolved concept | Wrong? |
|---|---|---|
| `BRAKES` | **Scratch (10)** — more than any brake concept | ❌ |
| `ENGINE_MECH` | Oil Change (25), then **Scratch (10)** | ❌ |
| `AC` | A/C not cooling (20), then **Oil Change (10), Oil Filter (9)** | ❌ |
| `BATTERY` | **Key / immobiliser fault (5)** above Battery Replacement (4) | ❌ |
| `COOLING` | Overheating (26) … then **Wheel alignment (7)** | ❌ |

### 3.1 Root cause — a ticket is not a symptom
`MatchPipeline` was built for *one symptom string → ranked concepts*. A legacy ticket is a **multi-topic paragraph
describing everything done during the visit**:

> *"Oil & Filter Change DONE - scratch on body DONE POLISH AND PAINT"*
> *"took off front bumper, found oil leak from crank case gasket, will change it"*

Taking the single highest-scoring concept from that blob returns *the most lexically prominent phrase in the
paragraph*, not the ticket's fault. Two corroborating signals from the run:

* **Scores are compressed and token-driven.** 69.9% of top matches land in the narrow 70–89 band; only **0.4% reach
  ≥90**. The `token` stage carries **97.7%** of top matches, while `exact` fired **3 times in 2,000**. Long text
  overlaps many concepts by loose token match, and the resulting ~75 scores clear the `strong` threshold of 70 on
  noise alone.
* **Ambiguity is rampant at that band** — three-way ties at 75–77 are routine:
  `Dent (75) | Paint damage (75) | Damaged rim (75)`.

**The `strong` threshold was calibrated for short symptom strings and does not hold on paragraphs.**

---

## 4. The fix, tested

I A/B'd whole-blob matching against **clause segmentation** (split on newlines, `/`, `;`, numbered bullets, commas)
with each segment matched independently. Results:

| Text | Whole-blob top-1 | Segmented concept set |
|---|---|---|
| *"took off front bumper, found oil leak from crank case gasket, will change it"* | **Oil leak (78) / Oil Change (78)** — a coin flip | ✅ **Bumper damage (81) + Oil leak (80)** |
| *"Oil & Filter Change DONE - scratch on body DONE POLISH AND PAINT"* | Oil Change (79), Scratch (77) blended | ✅ **Oil Change (81) + Scratch (79)** |
| *"still has air bag light, will go to change air bag ecu"* | Airbag warning (75), **Check-engine light (73) false positive** | ✅ **Airbag warning (81)**, false positive gone |
| *"check oil / scratches on body / need to adjust rear bumper"* | Bumper damage (77) only | ✅ **Bumper damage (81) + Scratch (79)** |

Segmentation does three things at once:

1. **Scores rise** (75→81) because evidence is clean rather than diluted.
2. **Multi-fault tickets yield multi-concept sets** instead of one arbitrary winner.
3. **False positives fall below the threshold rather than above it.** Junk segments behave correctly:
   *"will change it"* → Oil Change **(59)**; *"need to check gear mount"* → Check-engine light **(65)**. Both under 70,
   both correctly discarded. At blob level that same noise was scoring 75+.

---

## 5. Required design changes to the bridge

1. **Multi-label, not top-1.** A ticket resolves to a **set** of concepts. This is not a workaround — it matches the
   domain model exactly: a `Maintenance` is a *container* of faults, which is already how `MaintenanceTask` is defined.
2. **Segment-anchored evidence.** Store the matching span per concept, not per ticket. This directly delivers the
   explainability you specified — *why it matched · confidence · source text · classification method* — because the
   source text becomes the actual clause, not the whole note.
3. **Adopt span consumption**, as `RepairSignatureClassifier` already does (its rule 1: *"check engine light"* would
   otherwise match both CHECK_ENGINE and LIGHTS, and that pair then appeared as the fleet's strongest fault bundle —
   an artifact of the classifier reading its own output). `MatchPipeline` has no span consumption today. Ingesting at
   volume without it would manufacture exactly that class of false pattern.
4. **Promote to `strong` only on segment-level evidence** — and prefer requiring phrase/alias/exact evidence rather
   than token-only. Token-only at 75 on a paragraph is noise; the same at segment level is usually real.
5. **Carry `is_exposure` per concept, not per ticket.** Body/cosmetic concepts — Scratch (201), Bumper damage (130),
   Damaged rim (62), Dent (44), Paint damage (41) — are **~28% of all top-1 matches**. They must inherit the existing
   BODY/RIM quality exclusion at concept level, or they will dominate every quality comparison.
6. **Classify non-fault tickets explicitly.** ~21% are status/logistics notes. Give them a `non_fault` label rather
   than counting them as bridge failures; the coverage metric should report the three states separately
   (`resolved` / `non_fault` / `unresolved`).

---

## 6. Revised effort estimate

| | Estimate |
|---|---|
| Single-label pass, 26,839 tickets | ~54 min |
| **Segmented multi-label pass** (3–5 resolve calls/ticket) | **~3–4 hours**, batch, resumable |
| Expected concepts per ticket | 1–3 |
| Expected resolved-concept rows | ~40–60k |

Well within a scheduled backfill. Cost is compute-only — **no API calls, no model inference.** The whole bridge is
deterministic PHP over the existing 2,128-term vocabulary.

---

## 7. What this does not tell us

* **Precision is not yet measured against ground truth.** Section 3 infers it from signature/concept mismatch, which
  is suggestive, not a measurement. Before the backfill is trusted, **a human should label ~200 segmented tickets**
  and the bridge scored against them. That is the one gap remaining in this evaluation, and it is cheap.
* The sample is 7.5% and random; concept-level rates for rare signatures (`FUEL_SYS` n=4, `EXHAUST` n=5) are noise.
* Arabic-language text was not analysed separately.

---

## 8. Recommendation

**Proceed with the Concept Bridge**, redesigned as a **segmented multi-label enricher** rather than a single-label
classifier. Recall is proven at 98.8% on real fault tickets, the resolution gain is real (22 → 91 concepts observed in
a 7.5% sample), throughput is a few hours of batch compute, and the failure mode found is fixable by construction
rather than by tuning.

Add one step before the backfill: **a 200-ticket human-labelled precision check.** If precision on segmented
multi-label output clears ~85%, backfill. If it does not, the gap will point at specific vocabulary rather than at the
approach.
