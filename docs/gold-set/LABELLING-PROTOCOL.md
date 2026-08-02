# Concept Bridge — Gold Set Labelling Protocol

**File to label:** `concept-bridge-goldset-v2.csv` (210 rows) · **Built:** 2026-08-01
**Who should do this:** someone who understands workshop language and vehicle systems — a supervisor or senior
technician. Not a data person. The point is human judgement about cars.
**Estimated time:** 3–4 hours (v2 adds two judgement columns).

This file becomes the **permanent benchmark** for the Automotive Intelligence Platform, not a one-off test. Every
future change to segmentation, matching or the ontology gets re-scored against it. Label it accordingly.

---

## 1. The one rule that matters

> **Judge each row using ONLY the text in `segment_text`. Do not open the ticket. Do not use `v1_signature`
> to decide.**

`v1_signature` is the ticket's old single label, shown as context only. Our earlier evaluation treated it as the right
answer and that was wrong — a ticket labelled `COOLING` can legitimately contain a steering complaint, a scratch and
an oil change. **A concept is correct if the segment text supports it. Nothing else.**

Real row from the file:

| segment_text | v1_signature | pred1 |
|---|---|---|
| "impact mark on the front bumper" | `COOLING` | Bumper damage (79) |

**Correct.** The old label says COOLING; the sentence says bumper. The sentence wins.

---

## 2. Do this first — blind pass

**Read `segment_text`, write what concepts it contains into `LABEL_missing_concepts`, and only then look at the
prediction columns.**

If you read our predictions first you will tend to agree with them, and we'd end up measuring *agreement with the
model* instead of *correctness*. Hide columns G–O in Excel for a first pass; unhide them to fill in the rest.

---

## 3. The seven columns you fill in

### 3.1 `LABEL_segment_type` — what kind of statement is this?

| Value | Meaning | Example |
|---|---|---|
| `fault` | a problem, symptom or damage | *"there is a noise from the engine"* |
| `action` | work performed | *"replacing the car alternator"*, *"rims painted"* |
| `part` | a part named, no fault or action | *"4 rims"*, *"new wipers"* |
| `procedure` | an inspection or check | *"ABS checking"* |
| `operational` | status, logistics, people, admin | *"car is ready"* |
| `unclear` | genuinely cannot tell | *"fading"* |

This column tests a specific suspicion: that the matcher turns **actions** into **faults**. Please be strict about
that distinction.

### 3.2 `LABEL_pred1_verdict` — ⭐ NEW — is the top prediction right, and is it specific enough?

Judge **`pred1_concept` only** (that's what precision is computed on). Pick one:

| Value | Meaning |
|---|---|
| `correct_specific` | Right, and at the right level of detail |
| `correct_broad` | **Directionally right but too general** — the text is more specific than the concept |
| `incorrect` | Not supported by the text |
| `none_predicted` | Use for `unresolved` rows (no prediction was made) |

**`correct_broad` is the important new one.** Your example:

```
text:       "replace thermostat"
prediction: "Cooling System"        →  correct_broad
            (right system, but the text names the exact component)
```

More from the file:

| segment_text | pred1 | Verdict | Why |
|---|---|---|---|
| "airbag light solved" | Airbag warning | `correct_specific` | exactly right |
| "checking the fog light and front sensor need checking" | Check-engine light | `incorrect` | a fog light is not the engine warning light |
| "impact mark on the front bumper" | Bumper damage | `correct_specific` | right |
| "rims scratches" | Scratch | `correct_broad` | true, but "Damaged rim" is the more specific reading |

**Why this label matters beyond accuracy:** OEM and Bosch documentation talks about *thermostats* and *water pumps*,
not *"cooling system"*. If a large share of our matches are `correct_broad`, fleet history cannot meet automotive
knowledge at the same resolution — and that changes what we build next. This column measures exactly that.

### 3.3 `LABEL_evidence_quality` — ⭐ NEW — how strongly does the text support it?

Only fill this when the verdict is `correct_specific` or `correct_broad`. Leave blank otherwise.

| Value | Meaning | Example |
|---|---|---|
| `strong` | Names the fault/component explicitly, often with a reason | *"replaced thermostat due to overheating"* |
| `medium` | Names the system and that something is wrong | *"cooling issue checked"* |
| `weak` | Vague — a person could reasonably read it several ways | *"engine problem"* |

This feeds confidence scoring later: we want to know whether our numeric score actually tracks how strong the human
thinks the evidence is. **If a `weak` row scores 85, our confidence model is wrong** — and that is worth knowing
before it drives anything.

### 3.4 `LABEL_valid_concepts` / `LABEL_invalid_concepts`

Across **all three** predictions, which are supported and which are not. Separate with `;`, write `none` if empty.

- *"rims scratches"* → Scratch, Damaged rim → both supported → valid: `Scratch; Damaged rim`
- *"4 rims"* → Damaged rim → text says there are four rims, **not that they're damaged** → invalid: `Damaged rim`

### 3.5 `LABEL_missing_concepts`

What the text contains that was **not** predicted. Plain words — **you are not restricted to our concept list.**
If the text says *"drums skimmed"*, write `brake drum machining`. This column is how we find what the ontology
lacks, so be generous.

### 3.6 `LABEL_notes`

Especially: *"ambiguous, could be either"*, *"needs the full ticket"*, *"Arabic text"*.

---

## 4. Judgement calls, decided in advance

1. **An action does not imply its fault.** *"replacing the car alternator"* → `action`. Is "Alternator / charging
   fault" valid? **No** — we aren't told it failed; it may be preventive. Put `alternator replacement` in
   `missing_concepts`. *(This is the single most valuable distinction in the exercise.)*
2. **A part name alone is not damage.** *"new wipers"* → `part`.
3. **A system name alone is not that system's fault.** *"ABS"* → not "ABS warning light" unless a problem is stated.
4. **Cosmetic counts as a fault.** Scratches and dents are real faults, just cosmetic. Mark `fault`; the
   mechanical/cosmetic split is handled separately.
5. **Close enough is `correct_specific`.** *"AC not blowing cold"* → "A/C not cooling" is exactly right, not broad.
6. **Multiple concepts in one segment is normal.** List them all.
7. **Genuine ambiguity is an answer.** *"vehicle does not start"* legitimately spans battery / starter / immobiliser.
   If several predictions are all plausible, mark them all valid and note it. **We are not trying to force one
   answer** — multiple concepts with confidence is a correct output.
8. **If you can't tell, use `unclear`.** A guessed label is worse than a missing one.

---

## 5. What the analysis will produce

| # | Output | Computed from |
|---|---|---|
| 1 | **Precision by confidence band** | `band_60_79` vs `control_80_plus` verdicts |
| 2 | **Precision by system** | `system_*` strata |
| 3 | **Precision by matching method** | `pred1_primary_stage` × verdict |
| 4 | **Specificity rate** | share `correct_specific` vs `correct_broad` |
| 5 | **Confidence calibration** | `LABEL_evidence_quality` vs `pred1_score` |
| 6 | **Missing concepts ranked by frequency** | `LABEL_missing_concepts` + `unresolved` stratum |
| 7 | **False-positive patterns** | `incorrect` verdicts grouped by `pred1_matched_term` |
| 8 | **Ambiguity patterns** | `ambiguous` stratum — how often are both valid? |
| 9 | **Action-as-fault rate** | `action_like` rows: type=`action` but a fault predicted |

### ⚠️ Caveat on output 3 (precision by matching method)

The 210 rows carry this primary-stage mix:

| Stage | n |
|---|---:|
| token | 150 |
| alias | 15 |
| phrase | 13 |
| semantic | 9 |
| fuzzy | 7 |
| exact | 1 |

**Only `token` will yield a solid precision figure.** `alias` and `phrase` will be indicative; `semantic`, `fuzzy`
and `exact` are too thin to measure.

That is acceptable — the mix **mirrors production** (token carried 97.7% of top matches in the full run), so we are
measuring the path that actually matters. But we should not quote a precision number for `exact` or `fuzzy` off this
file, and if we later want one, it needs a purpose-built stage-stratified sample.

---

## 6. Composition of the 210 rows

| Stratum | n | Why |
|---|---:|---|
| `band_60_79` | 65 | **The main question** — worth ~20 points of coverage |
| `ambiguous` | 35 | Two strong candidates within 10 points |
| `system_*` (Cooling, Engine, Electrical, Brakes, Suspension) | 50 | The five systems that drive health scoring |
| `action_like` | 20 | Tests the action→fault theory |
| `bare_system_noun` | 10 | Tests the system-noun theory |
| `unresolved` | 15 | Real vocabulary gap, or noise? |
| `control_80_plus` | 15 | Control group — needed for an overall figure and for regression detection |

Rows are shuffled so strata aren't clustered.

---

## 7. Pre-registered decision rules

Agreeing these **before** seeing results is what stops us rationalising whatever number comes back. Proposed — please
confirm or adjust:

| Result | Decision |
|---|---|
| Precision ≥ **85%** on `control_80_plus` + `system_*` | **Proceed to full backfill** |
| Precision **70–85%** | Backfill blocked; **expand ontology + apply the phrase-first rule**, then re-score |
| Precision < **70%** | **Fix segmentation/matching first** — do not backfill |
| `band_60_79` precision ≥ **75%** | **Lower the threshold** toward 60 — worth ~20 points of coverage |
| `band_60_79` precision < **60%** | Keep the threshold at 70; that band is noise |
| `correct_broad` > **30%** of correct verdicts | Ontology needs **component-level concepts** before knowledge ingestion |
| Action-as-fault > **50%** of `action_like` | **Build the fault/action/part/procedure split** before backfill |

---

## 8. Two things worth doing if capacity allows

* **Double-label the first 30 rows with a second reviewer.** Disagreement tells us the *concept definitions* are
  unclear — a different problem from the matcher being wrong, and one we'd otherwise misdiagnose.
* **Note recurring patterns as you go.** Human pattern-spotting during labelling routinely finds things the aggregate
  statistics miss.

---

## 9. Honest limits

* 210 rows sees **big** effects, not small ones. On a 65-row stratum, ~50% precision carries roughly a ±12-point
  confidence interval. It will tell us **whether** to move the threshold, not whether to move it to 62 or 65.
* Strata are **not proportional to real traffic**, so results must be reported per stratum. **There is no meaningful
  single "the bridge is X% accurate" number from this file** and we should resist quoting one.
* Segments are judged without ticket context by design. The few that genuinely need it get excluded from the metrics
  via `LABEL_notes` rather than guessed at.
* Drawn from 1,500 random tickets, so rare systems are thinly represented.
* `LABEL_evidence_quality` is a new, subjective scale. Expect the first 20 rows to be calibration; it's worth
  re-reading them at the end once your sense of the scale has settled.
