# Concept Bridge — Error Analysis

**Date:** 2026-08-01 · **Read-only, nothing written.**
**Sample:** 1,500 tickets → **6,470 segments**, each text field segmented and resolved independently.

---

## 0. ⚠️ Read this before the tables: my false-positive proxy is largely measuring something else

I used *"the resolved concept's category is implausible for the ticket's v1 signature"* as a false-positive proxy.
**Running it exposed that the proxy is mostly invalid**, and the reason matters more than the tables it produced.

Top "false positives" by volume:

```
189  "scratches"    -> Scratch          flagged under ENGINE_MECH, TYRE, INTERIOR
 91  "front bumper" -> Bumper damage    flagged under COOLING, INTERIOR, ENGINE_MECH
```

**These matches are correct.** A ticket whose v1 signature is `ENGINE_MECH` and whose notes also say *"scratches on
body"* genuinely contains a Scratch. The v1 signature is a **single label on a multi-fault ticket** — it names the
primary system and discards the rest.

So the proxy conflates *"wrong match"* with *"correct match on a secondary fault"* — which is **precisely the thing
multi-label was built to discover**. The mismatch rates in §5 (Scratch 78%, Bumper damage 73%, Wheel alignment 87%)
are not error rates; they are **secondary-fault discovery rates**.

**Two conclusions:**

1. **The v1 signature cannot serve as ground truth for precision.** Human labelling (your Phase 2) is now
   *definitively required*, not a nice-to-have. No automated proxy over existing labels can substitute.
2. **Read positively, this quantifies the bridge's value:** roughly **60–70% of resolved concepts are faults the v1
   signature never recorded.** That is the resolution gain, measured.

The tables below are still useful — but only when read for *patterns*, not for *rates*. The genuine error classes in
§1 were found by inspecting them, not by trusting the proxy.

---

## 1. The three real error classes

### ⭐ Class A — Repair ACTIONS resolving to FAULT concepts (the significant one)

| Pattern | n |
|---|---:|
| `"painting"` → Paint damage | 35 |
| `"new dashboard"` → Dashboard fault | 19 |
| `"rims painted"` → Damaged rim | 12 |
| `"interior upholstery"` → Seat / upholstery damage | 9 |
| `"needs bleeding"` → Soft / spongy pedal | 6 |
| `"coolant change"` / `"flushed the system"` → Coolant service | 3 |
| `"headlight polishing"` → Foggy / dim lights | 1 |
| `"collecting interior parts"` → Interior trim damage | 2 |
| `"software update needed"` → Infotainment / screen issue | 2 |

**Root cause:** `MatchPipeline` resolves against `FindingKeyword` — the **fault** vocabulary — only. The ontology
already has `OntologyNode::TYPE_REPAIR`, `TYPE_PROCEDURE` and `TYPE_PART` nodes (92 repairs, 274 procedures), but the
matcher cannot reach them. So every action verb in a workshop note lands on the nearest *fault*.

**Why this matters beyond precision:** *"collecting interior parts"* is not a fault. Counting it as one **inflates
fault counts and corrupts reliability scoring.** Worse, it discards signal: an action is evidence of **what was
done** — which is exactly the Outcome world that is otherwise empty (`maintenance_task_actions` = 0).

**Recommendation — and I think this is the most valuable single change to come out of this analysis:** the bridge
should classify each segment as **fault | action | part | procedure | operational**, matching against the
corresponding node type. Actions extracted from 26,839 legacy tickets become the **first real Outcome-world dataset**
— derived, clearly labelled as such, but real. That partially unblocks the `contradicts` category *without* waiting
for repair-capture adoption.

### Class B — A bare system/component noun resolving to a specific fault of that system

| Pattern | n | Problem |
|---|---:|---|
| `"light is on"` → Check-engine light | 35 | under `LIGHTS` this is a bulb, not the MIL |
| `"4 rims"` → Damaged rim | 48 | a quantity/part reference, not damage |
| `"ABS"` → ABS warning light | 32 | "ABS" alone is a system |
| `"AC"` → A/C not cooling | 15 | "AC" alone is a system |

**Rule needed:** a system or component noun **with no fault predicate** should resolve to the *system/component
node*, not to that system's most common fault. This is the single most common way a generic mention becomes a
specific claim.

### Class C — Token collisions producing nonsense

`Door / panel misalignment ↔ Wheel alignment` (12) — pure collision on *"alignment"*.
`Damaged rim ↔ Stalling` (3) · `Check-engine light ↔ Scratch` (3) · `Bumper damage ↔ Check-engine light` (3).

These are exactly what the **phrase-first rule** you specified will remove.

---

## 2. Ambiguity

| Concept | n | Ambiguous | Rate |
|---|---:|---:|---:|
| **Door / panel misalignment** | 12 | 12 | **100.0%** |
| Air Filter | 7 | 4 | 57.1% |
| Oil Change | 130 | 59 | 45.4% |
| Airbag warning | 56 | 25 | 44.6% |
| Bumper damage | 225 | 85 | 37.8% |
| Scratch | 403 | 137 | 34.0% |

**`Door / panel misalignment` never resolves cleanly — 12 of 12 ambiguous.** It is a broken entry in the vocabulary
and should be reviewed or retired.

### Most-confused pairs — and they are not all the same problem

| Pair | n | Diagnosis |
|---|---:|---|
| Damaged rim ↔ Scratch | 149 | **Compound reality** — "rim scratch" genuinely is both. Needs a compound rule, not disambiguation |
| Bumper damage ↔ Scratch | 63 | Same — co-occurring cosmetic damage |
| Oil Change ↔ Oil Filter | 58 | **Not ambiguity — legitimate bundling.** Both are correct; multi-label should keep both |
| Airbag warning ↔ Check-engine light | 25 | **Real confusion**, different systems. Needs disambiguation |
| Door/panel misalignment ↔ Wheel alignment | 12 | **Token collision bug** (Class C) |
| Steering vibration ↔ Wheel balancing | 11 | **Genuine diagnostic ambiguity** — this is what the graph's `confused_with` edge type exists for |

Three different problems wearing one label. Only rows 4–5 need fixing; row 3 needs *accepting*; row 6 should be
**recorded as a `confused_with` edge** — the ontology already models it.

---

## 3. Unresolved phrases — mostly not a vocabulary gap

399 distinct unresolved phrases. Ranked words:

| Operational noise (majority) | n | | Genuine automotive gaps | n |
|---|---:|---|---|---:|
| maintenance | 206 | | drums *(brake drums, "skimmed")* | 12 |
| customer | 59 | | **thermostat** | 8 |
| periodic | 43 | | fans / auxiliary fans | 7 |
| **waleed** *(a person's name)* | 31 | | fender | 6 |
| test | 27 | | condenser | 5 |
| covers | 22 | | axles | 5 |
| working / cleaning / bought | 19/19/15 | | filters | 4 |
| today / staff / going / days | 14/8/10/10 | | wires | 5 |

**The unresolved bucket is dominated by operational language, not missing automotive vocabulary.** That is good news:
it means the fix is mostly *classification* (route to `non_fault`), not *ontology expansion*.

Two specific gaps worth acting on:

* **`periodic maintenance` (43) + `maintenance` (206)** — a routine-service concept that should resolve and does not,
  against 2,982 `OIL_SERVICE` tickets. High-volume, trivially fixable.
* **`thermostat`, `drums`, `condenser`, `axles`, `fans`** — the component-level terms flagged in the previous report,
  now confirmed at frequency. These are the terms the fusion engine needs.

Also present: person names (`waleed`, `abdullah`), Arabic text (`جمبينات خلفية جديدة`), and encoding garbage
(`#fagp#uh#r#`, `\\\\\jn#ay#db#n#`) — all preprocessing concerns.

---

## 4. Source comparison — the most actionable table here

### By text field

| Field | Segments | Resolved | Weak | Operational | Unresolved | Avg score |
|---|---:|---:|---:|---:|---:|---:|
| `service_main` | 596 | **9.7%** | **71.6%** | 3.2% | 15.4% | 82.4 |
| `service_sup` | 661 | 32.2% | 16.2% | 1.1% | **50.5%** | 89.2 |
| `maintenance_notes` | **5,213** | 32.5% | 46.0% | 9.5% | 12.0% | 79.1 |

Three genuinely surprising results:

1. **`service_main` — the *structured* label field — resolves worst (9.7%), with 71.6% falling into the weak band.**
   Its labels are category headers (*"Mechanical Issues Maintenance"*), too generic to hit a specific fault concept.
   The structured field is the *least* useful input to the bridge.
2. **`maintenance_notes` carries 81% of all segments** and has the lowest unresolved rate (12%). The free text is the
   substrate — confirming the v1 classifier's own finding that notes cover 95.4% of events versus 30% for
   `service_main`.
3. **`service_sup` has the highest average score (89.2) but the highest unresolved rate (50.5%)** — bimodal: when it
   matches it matches cleanly, otherwise not at all.

### By origin

| Origin | Segments | Resolved | Operational | Unresolved |
|---|---:|---:|---:|---:|
| `sheet` | 4,244 | 33.1% | 8.1% | 10.5% |
| `customer-sheet` | 2,226 | 25.1% | 8.0% | **27.4%** |

**This reverses the earlier ticket-level finding and is worth flagging.** At *ticket* level customer-sheet looked
better (70.6% fault-related); at *segment* level it is markedly worse (27.4% unresolved vs 10.5%). Both are true:
customer tickets are *more likely to be about a fault*, but customer *language* is *less likely to match the
vocabulary*. **Customer phrasing is a distinct vocabulary-expansion target** — and one that matters, since customer
complaints are the entry point of the whole workflow.

---

## 5. Rules this analysis supports

| # | Rule | Evidence |
|---|---|---|
| 1 | **Match against node TYPE — fault / action / part / procedure — not faults only** | §1 Class A; unlocks Outcome-world data from legacy text |
| 2 | **Bare system nouns → system node, not that system's top fault** | §1 Class B (130 instances in sample) |
| 3 | **Phrase-first; token-only requires a higher bar** | §1 Class C; confirmed from prior report's `phr%` |
| 4 | **Treat co-occurring concepts as bundling, not ambiguity** | §2 — Oil Change ↔ Oil Filter is not an error |
| 5 | **Record genuine diagnostic confusions as `confused_with` edges** | §2 — Steering vibration ↔ Wheel balancing |
| 6 | **Review/retire `Door / panel misalignment`** | §2 — 100% ambiguity |
| 7 | **Preprocess: person names, Arabic, encoding garbage, separators** | §3 |
| 8 | **Add `periodic maintenance` + component terms** (thermostat, drums, condenser, axles, fans) | §3 |
| 9 | **Weight `maintenance_notes` as the primary field; deprioritise `service_main`** | §4 |
| 10 | **Treat customer phrasing as its own vocabulary target** | §4 |

---

## 6. What this report does **not** establish

* **It does not measure precision.** §0 explains why the proxy cannot. The 200-ticket human labelling is now the
  binding constraint on proceeding to backfill — I would not run the enrichment without it.
* The category-plausibility map in the script is my judgement, not a curated artifact.
* Class A/B/C counts come from inspecting the top ~120 patterns; the tail is unexamined.
* Arabic-language segments were not analysed separately, only observed.
* `manual`/`contract` origins produced no segments in this sample.

---

## 7. Suggested labelling design (Phase 2)

Given §0, I would shape the 200-ticket set to answer the questions that are actually open:

| Stratum | n | Question it answers |
|---|---:|---|
| Score 50–69 band | 80 | Should the threshold drop? Worth ~20 points of coverage |
| Score ≥70, category-mismatched | 50 | Are these secondary faults (as §0 argues) or errors? |
| Action-like segments (Class A) | 30 | Confirms the fault/action split |
| Bare system nouns (Class B) | 20 | Confirms the system-vs-fault rule |
| Unresolved, non-operational | 20 | Real vocabulary gaps vs noise |

Deliberately **not** random: random sampling would spend most of its budget re-confirming the easy 80+ band.
