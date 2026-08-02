# Concept Bridge — Gold Set

The human benchmark that decides whether we may enrich 26,839 legacy tickets with ontology concepts.

**Labelling now happens inside FleetView, not in a spreadsheet:**

> **Maintenance Intelligence → Concept Bridge Review**
> `/concept-bridge-review` · permission `maintenance.manage`

The standalone `label.html` was removed on purpose. Two labelling surfaces would split the answers
across two stores and quietly break the benchmark.

---

## Two tracks, never blended

| Track | What | Where it lives |
|---|---|---|
| **Human — the benchmark** | ~90 rows answered by a technician in the app | `concept_bridge_labels` where `source = human` |
| **AI baseline — indicative** | all 210 rows labelled by Claude | `source = ai` |

Human labels are the number of record. The AI baseline only (a) gives an early directional read and
(b) — on rows a human also answered — measures how far machine labels can be trusted elsewhere.
**The decision rules read Track A only, and stay `pending` until at least 15 rows are labelled.**

## Data model

```
concept_bridge_samples   the frozen question set (210 rows), with what the matcher predicted at
                         capture time. `human_review` flags the ~90 a person is asked to answer.
concept_bridge_labels    the answers. `source` = human | ai — the column the whole split rests on.
```

Samples are immutable by intent: a new matcher means a **new `sample_set`**, never a rewrite, so a
score always refers to a fixed set of questions.

## Loading a set

```bash
# the questions a technician answers
php artisan conceptbridge:import docs/gold-set/concept-bridge-TECHNICIAN-SET.csv --set=v2 --human-review

# the machine baseline over the full 210
php artisan conceptbridge:import docs/gold-set/concept-bridge-AI-BASELINE.csv --set=v2 --labels=ai
```

`--labels=human` is rejected by the command. Human ground truth can only be typed by a person in the
review page — that is the entire point of the exercise.

## Reading the results

```
GET /api/concept-bridge/results
```

Returns Track A (official), Track B (AI-vs-human agreement + optimism check) and Track C (AI-only,
indicative), plus the pre-registered decision rules.

The offline `score-goldset.php` still works on exported CSVs and is kept for reproducibility, but the
app is now the source of truth.

## What the reviewer does

One real maintenance line at a time, six short questions, mostly button clicks. **The matcher's
predictions stay hidden until questions 1 and 2 are answered** — a reviewer who sees our answer first
tends to agree with it, and the benchmark would then measure agreement instead of correctness. The
sampling stratum and the old ticket signature are never sent to the browser for the same reason.

Keyboard: `1`–`6` pick · `q` `w` `e` strong/medium/weak · `space` reveal then advance · `←` `→` move.
Answers save per row, so it can be done across several sittings.

Read **`LABELLING-PROTOCOL.md`** before starting — especially §4, the pre-decided judgement calls.

## How the ~90 were chosen

| Stratum | n | Why |
|---|---:|---|
| `band_60_79` | 40 | The threshold question — worth ~20 points of coverage |
| `action_like` | 20 | Action-vs-fault: the biggest suspected error class |
| `ambiguous` | 18 | Real bundling vs real confusion |
| `unresolved` | 12 | Genuine vocabulary gap vs noise |

The other 120 rows (control, per-system, bare nouns) keep their AI labels and report as Track C.

## Files

| File | What |
|---|---|
| `concept-bridge-goldset-v2.csv` | The full 210-row stratified sample |
| `concept-bridge-TECHNICIAN-SET.csv` | The ~90 rows a human answers |
| `concept-bridge-AI-BASELINE.csv` | All 210 rows, **AI-labelled — not ground truth** |
| `LABELLING-PROTOCOL.md` | Definitions, judgement rules, pre-registered decisions |
| `score-goldset.php` | Offline scorer for exported CSVs |
