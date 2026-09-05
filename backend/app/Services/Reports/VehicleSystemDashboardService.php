<?php

namespace App\Services\Reports;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\GarageRecommendationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * VEHICLE SYSTEM DASHBOARD — one car, one system (engine, brakes, transmission …), the whole story.
 *
 * The question this answers is the one that gets asked about a problem car and never has a single
 * place to be answered: *has this system actually been fixed, or have we been paying for the same
 * failure over and over?* It isolates every recorded event that belongs to ONE system, lays them out
 * in order, shows what was replaced and whether the same failure came back afterwards, and states
 * what the record supports — repair, review, or stop.
 *
 * WHAT IS A FACT HERE AND WHAT IS A COUNT (see [[evidence-layer-governance]]):
 *
 *   FACT (recorded by a person, reproduced verbatim)
 *     — every timeline entry: its date, garage, severity, the finding it was logged under, the notes.
 *     — every repair line: the part or work described on the invoice line or the ticket.
 *
 *   DERIVED (arithmetic over those facts, always shown with its inputs)
 *     — the risk score and health index. Each is a sum of five named components, and the report
 *       prints the component, its input count and its contribution. There is no model, no training
 *       and no confidence: re-run the arithmetic by hand and you get the same number.
 *       See [[treat-data-as-source-of-truth]] — nothing here is a prediction.
 *
 *   NEVER PRODUCED
 *     — a probability that the system will fail again. The report says what happened and how often;
 *       whether to spend more is a person's call, and the decision line names the rule it applied.
 */
class VehicleSystemDashboardService
{
    public function __construct(
        private GarageRecommendationService $categories,
        private VehicleSystemEvidenceService $evidence,
        private VehicleSystemPeriodService $period,
    ) {
    }

    /**
     * The systems a dashboard can be built for. Keys are the category keys of
     * config/maintenance_findings.php, so filtering is an equality test against data already
     * classified at intake — never a keyword scan invented here.
     */
    public const SYSTEMS = [
        'engine'       => 'Engine',
        'transmission' => 'Transmission & Drivetrain',
        'brakes'       => 'Brakes',
        'suspension'   => 'Suspension & Steering',
        'electrical'   => 'Electrical',
        'ac'           => 'Climate / A/C',
        'tyres'        => 'Tyres & Wheels',
        'bodywork'     => 'Bodywork & Exterior',
        'fluids'       => 'Fluids & Cooling',
    ];

    /**
     * The five components of the risk score, each with the ceiling it can contribute. They sum to
     * 100. Changing a weight changes every dashboard, so the numbers live here and nowhere else.
     */
    private const RISK_WEIGHTS = [
        'volume'      => 30,  // how many times this system has failed
        'severity'    => 20,  // how many of those were rated Critical
        'recurrence'  => 20,  // the same finding coming back
        'recency'     => 15,  // how recently it last failed
        'post_repair' => 15,  // failures recorded AFTER a major replacement on this system
    ];

    /**
     * Wording that means a MAJOR replacement rather than an adjustment.
     *
     * Deliberately narrow. An earlier draft also matched "installed", which appears in almost every
     * garage note ("bearing installed", "wheel alignment … installed") and flagged 29 of one car's 50
     * entries as major work — inflating the post-replacement risk component to the point where a
     * routine car read as unstable. A token here must be one that only appears when a component was
     * genuinely swapped out.
     */
    private const MAJOR_WORK_TOKENS = ['replace', 'replacement', 'overhaul', 'rebuild', 'new engine', 'engine change'];

    /**
     * Build the dashboard for one vehicle and one system, optionally over a selected period.
     *
     * WHAT THE PERIOD DOES AND DELIBERATELY DOES NOT TOUCH.
     *
     * Every section that reports the RECORD — incidents, problems, the timeline, the work ledger,
     * durability, data quality, the counters — is read over the selected range and describes only
     * that range. The default range is all history, so a reader who sets no filter gets exactly the
     * report that existed before this parameter did.
     *
     * The RISK SCORE does not move with the filter, and this is the one asymmetry on the page. Its
     * arithmetic is all-history by construction: recency counts days from today, the ceiling states
     * what this car's whole record could ever reach, and post-repair failures are measured across the
     * complete sequence. Recomputing it over three summer months would produce a number that looks
     * like the same 0–100 scale and means something entirely different — a car with one May incident
     * would read "recent failure, high recency points" for a period that ended in July. So the score
     * stays historical, is computed from the unfiltered events, and every block that carries it is
     * tagged `scope: history` so the frontend can never print it as a finding about the period.
     * See §"Risk score" of the feature brief and [[treat-data-as-source-of-truth]].
     *
     * @param  Vehicle           $vehicle
     * @param  string            $system  One of self::SYSTEMS
     * @param  ?ReportDateRange  $range   Null and an inactive range are the same thing: all history.
     */
    public function build(Vehicle $vehicle, string $system, ?ReportDateRange $range = null): array
    {
        if (! isset(self::SYSTEMS[$system])) {
            throw new \InvalidArgumentException("Unknown system [{$system}].");
        }

        $range = $range ?: ReportDateRange::allHistory();

        // The period dataset. Narrowed in SQL by vehicle, system and date — never loaded whole and
        // sifted afterwards.
        $events   = $this->events($vehicle, $system, $range);
        $repairs  = $this->repairs($vehicle, $system, $events, $range);
        // The evidence layer decides what the records establish; scoring then runs over ITS incidents
        // rather than over the raw rows. See VehicleSystemEvidenceService for why that distinction is
        // the whole point of this report.
        $analysis = $this->evidence->analyse($events, $system);

        // The historical spine, for the risk score only. When no filter is set the two datasets are
        // the same query, so nothing extra is read for the common case.
        $historyEvents   = $range->isActive() ? $this->events($vehicle, $system) : $events;
        $historyAnalysis = $range->isActive() ? $this->evidence->analyse($historyEvents, $system) : $analysis;
        $risk            = $this->risk($historyEvents, $system, $historyAnalysis);

        $period = $this->period->build($analysis['incidents'], $analysis['durability'], $events);

        return [
            'vehicle'    => $this->vehicleHeader($vehicle),
            'brief'      => $this->vehicleBrief($vehicle),
            'system'     => ['key' => $system, 'label' => self::SYSTEMS[$system]],
            'verdict'    => $this->verdict($risk, $historyEvents, $historyAnalysis),
            'kpis'       => $this->kpis($events, $risk, $analysis),
            'risk'       => $risk + ['scope' => 'history'],

            // ── The selected period ───────────────────────────────────────────────────────────
            // `period` describes the filter; `period_summary` counts inside it; `problems` is the
            // grouped answer to "what went wrong, how often, and what was done".
            'period'        => $range->toArray() + [
                'covered_from' => $events->first()['date'] ?? null,
                'covered_to'   => $events->last()['date'] ?? null,
            ],
            'period_summary'=> $period['summary'] + ['scope' => 'period'],
            'problems'      => $period['problems'],
            'workshop_only' => $period['workshop_only'],
            'period_repairs'=> $period['repairs'],

            // ── The evidence layer, surfaced ──────────────────────────────────────────────────
            'incidents'    => $analysis['incidents'],
            'recurrence'   => $analysis['recurrence'],
            'work'         => $analysis['work'],
            'durability'   => $analysis['durability'],
            'facts'        => $analysis['facts'],
            'confidence'   => $analysis['confidence'],
            // Data quality describes the records the report was BUILT from, so it moves with the
            // filter — and says which it is, because a period figure read as an all-history one is a
            // wrong statement about the fleet's paperwork.
            'data_quality' => $analysis['data_quality'],
            'data_quality_scope' => $range->isActive() ? 'period' : 'history',
            'takeaway'     => $this->takeaway($analysis, $risk, $system),

            'failure_mix'=> $this->failureMix($events),
            'fault_history' => $this->faultHistory($events, $system),
            'timeline'   => $events->values()->all(),
            'repairs'    => $repairs,
            'provenance' => $this->provenance($vehicle, $system, $events, $range),
        ];
    }

    // ── The events ──────────────────────────────────────────────────────────────────────────────

    /**
     * Every recorded failure of this system on this car, oldest first.
     *
     * Two sources, both already classified — no text is guessed at:
     *   • maintenance_tasks with this category_key — the per-fault record (the modern path).
     *   • workshop-log rows whose finding keyword resolves to this category — the sheet history,
     *     which predates per-fault tickets and is the only record for older cars.
     * A workshop row that already has tasks is represented by its tasks, so nothing double-counts.
     *
     * THE DATE FILTER IS APPLIED HERE, IN SQL, and nowhere later. Both tables are already indexed for
     * the vehicle lookup (`maintenances(vehicle_id, out_date)`), so a period narrows to an index range
     * rather than reading a car's whole history and discarding most of it. The imported workshop sheet
     * is not touched at request time at all — this reads the rows the importer already wrote, which is
     * the application's single representation of that history.
     *
     * The collection pass afterwards re-asserts the range against the date each event will actually
     * PRINT (a task's date is a COALESCE across three columns), so the SQL narrowing can never let a
     * row through that the header does not cover. @see ReportDateRange::contains
     */
    private function events(Vehicle $vehicle, string $system, ?ReportDateRange $range = null): Collection
    {
        return $this->eventsBySystem($vehicle, [$system], $range)[$system] ?? collect();
    }

    /**
     * The same reading, for SEVERAL systems at once — three queries instead of three per system.
     *
     * The whole-car report asks every system the question the single-system dashboard asks one of
     * them, and doing that by calling events() nine times re-reads the car's workshop history nine
     * times. So the rows are read ONCE and dispatched, and each system's answer is assembled from the
     * same rows the single-system report would have loaded for it.
     *
     * IT IS NOT A DIFFERENT READING. Two things make that true and both are easy to get wrong:
     *   • the covered-maintenance exclusion stays PER SYSTEM. A workshop row is represented by its
     *     tasks only for the system those tasks were filed under — an engine task does not silence
     *     that row's brake finding.
     *   • a row's categories are computed once and reused, because extractCategories() is the single
     *     sanctioned reader and asking it twice for the same text must not be able to answer twice.
     *
     * @param  array<int, string>  $systems
     * @return array<string, Collection<int, array>>  keyed by system, each oldest-first
     */
    public function eventsBySystem(Vehicle $vehicle, array $systems, ?ReportDateRange $range = null): array
    {
        $systems = array_values(array_filter($systems, fn ($s) => isset(self::SYSTEMS[$s])));

        if ($systems === []) {
            return [];
        }

        $range = $range ?: ReportDateRange::allHistory();

        $tasks = $this->tasksFor($vehicle, $systems, $range);

        // Which workshop rows each system's tasks already represent. Grouped, never flattened: the
        // exclusion is a statement about one system's record, not about the row as a whole.
        $covered = MaintenanceTask::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('category_key', $systems)
            ->whereNotNull('maintenance_id')
            ->get(['maintenance_id', 'category_key'])
            ->groupBy('category_key')
            ->map(fn (Collection $rows) => $rows->pluck('maintenance_id')->unique()->all());

        $sheetRows = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->when($range->isActive(), fn ($q) => $range->applyToColumn($q, 'maintenances.out_date'))
            ->with('vendor:id,name')
            ->get();

        /*
         * WHICH SYSTEMS DOES EACH WORKSHOP ROW BELONG TO — asked once, shared by every system.
         *
         * The sheet writes its OWN vocabulary in service_main / service_sup — "Body & Exterior",
         * "Tires", "Air Conditioning" — as a comma-separated list, and one row can name several
         * systems at once. GarageRecommendationService::extractCategories is the platform's single
         * sanctioned reader of that text (config/fault_extraction.php), already used by the cost
         * estimator and the extraction audit: a row this report counts under Engine is the same row
         * the estimator counts under Engine. It is deliberately conservative — text it does not
         * recognise yields NO category, so such a row appears under no system rather than the wrong one.
         */
        $rowSystems = $sheetRows->mapWithKeys(fn (Maintenance $m) => [
            $m->id => $this->categories->extractCategories($m->service_main, $m->service_sup, $m->findings),
        ]);

        $out = [];

        foreach ($systems as $system) {
            $coveredIds = $covered->get($system, []);

            $out[$system] = $tasks->where('category_key', $system)
                ->map(fn (MaintenanceTask $t) => $this->fromTask($t))
                ->concat(
                    $sheetRows
                        ->filter(fn (Maintenance $m) => in_array($system, $rowSystems[$m->id] ?? [], true)
                            && ! in_array($m->id, $coveredIds, true))
                        ->map(fn (Maintenance $m) => $this->fromSheetRow($m, $system))
                )
                ->filter(fn (array $e) => $e['date'] !== null && $range->contains($e['date']))
                ->pipe(fn (Collection $all) => $this->collapseVisits($all))
                ->sortBy('date')
                ->values();
        }

        return $out;
    }

    /**
     * The per-fault ticket rows for these systems, with the period applied exactly as it always was.
     *
     * @param  array<int, string>  $systems
     * @return Collection<int, MaintenanceTask>
     */
    private function tasksFor(Vehicle $vehicle, array $systems, ReportDateRange $range): Collection
    {
        return MaintenanceTask::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('category_key', $systems)
            ->when($range->isActive(), fn ($q) => $q->where(
                fn ($w) => $w
                    // The date a task is reported under. A row where both columns are null compares as
                    // NULL here — not matched — and is picked up by the fallback branch below.
                    ->where(fn ($x) => $range->applyToExpression(
                        $x, 'COALESCE(maintenance_tasks.identified_at, maintenance_tasks.created_at)'
                    ))
                    ->orWhere(fn ($x) => $x
                        ->whereNull('maintenance_tasks.identified_at')
                        ->whereNull('maintenance_tasks.created_at')
                        ->whereHas('maintenance', fn ($m) => $range->applyToColumn($m, 'maintenances.out_date')))
            ))
            ->with(['maintenance:id,garage,vendor_id,out_date,actual_in_date,maintenance_notes,event_status', 'maintenance.vendor:id,name'])
            ->get();
    }

    /**
     * ONE VISIT IS ONE EVENT.
     *
     * The sheet writes a separate row for each stage of the same trip — OUT, Follow up, IN — all
     * carrying the same date, the same garage and the same finding. Counted raw, a single visit to
     * one garage reads as three engine failures, and the volume and recurrence components of the risk
     * score inflate accordingly (one car showed 50 "failures" for what were far fewer actual trips).
     *
     * Rows identical on date + garage + finding are therefore the same event, and are collapsed to
     * one — keeping the richest of them, so no recorded detail is lost. Two genuinely separate visits
     * to the same garage on the same day for the same fault are indistinguishable in the data from
     * one visit logged twice; collapsing is the safer reading, and the Data Origin block says so.
     */
    private function collapseVisits(Collection $events): Collection
    {
        return $events
            ->groupBy(fn (array $e) => $e['date'] . '|' . mb_strtolower($e['finding']))
            // Within one day and one finding, split by garage — but only by garages that were
            // actually named. Rows whose garage was left blank are the same visit written up in a
            // second stage, not a second failure at a mystery workshop, so they fold into the named
            // garage. Two genuinely DIFFERENT named garages on one day stay two events: that is a car
            // moving between workshops, which is exactly what the report exists to show.
            ->flatMap(function (Collection $sameFinding) {
                $named = $sameFinding->reject(fn (array $e) => $e['garage'] === 'Not recorded');

                if ($named->pluck('garage')->unique()->count() <= 1) {
                    return [$this->mergeEntries($sameFinding)];
                }

                return $named
                    ->groupBy('garage')
                    ->map(fn (Collection $g) => $this->mergeEntries($g))
                    ->values();
            })
            ->values();
    }

    /**
     * Fold several rows of one visit into one entry WITHOUT losing anyone's words.
     *
     * The stages of a visit often carry different text — the OUT row holds the driver's complaint,
     * the follow-up row the garage's diagnosis. Keeping only the longest would silently delete one of
     * them, so the distinct notes are joined instead. The richest attributes (a real garage over a
     * blank one, a ticket's outcome over a sheet row's) win.
     *
     * @param  Collection<int, array>  $group
     */
    private function mergeEntries(Collection $group): array
    {
        $best = $group
            ->sortByDesc(fn (array $e) => [
                $e['source'] === 'ticket' ? 1 : 0,
                $e['garage'] !== 'Not recorded' ? 1 : 0,
                mb_strlen($e['detail']),
            ])
            ->first();

        $details = $group->pluck('detail')
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => $d !== '' && $d !== 'No notes recorded')
            ->unique()
            ->values();

        return array_merge($best, [
            'garage'     => $group->pluck('garage')->reject(fn ($g) => $g === 'Not recorded')->first() ?? 'Not recorded',
            'detail'     => $details->isEmpty() ? 'No notes recorded' : $details->implode("\n"),
            'recurred'   => $group->contains(fn (array $e) => $e['recurred']),
            'major_work' => $group->contains(fn (array $e) => $e['major_work']),
            'entries'    => $group->count(),
            // Every source row that produced this entry, so a derived statement can be traced back to
            // the exact records behind it rather than to one representative id.
            'refs'       => $group->flatMap(fn (array $e) => $e['refs'] ?? [])->unique()->values()->all(),
            'odometer'   => $group->pluck('odometer')->filter()->max(),
            // The richest specificity in the group wins: one row naming the fault is enough to make
            // the merged entry a named fault.
            'finding_specificity' => $group->pluck('finding_specificity')->filter()
                ->sortBy(fn ($s) => ['specific' => 0, 'generic' => 1, 'none' => 2][$s] ?? 3)
                ->first() ?? 'none',
        ]);
    }

    /** A per-fault ticket entry — the richest record: the fault, its outcome, and whether it recurred. */
    private function fromTask(MaintenanceTask $t): array
    {
        $m    = $t->maintenance;
        $date = $t->identified_at ?? $t->created_at ?? optional($m)->out_date;

        return [
            'source'      => 'ticket',
            'ref'         => $t->maintenance_id,
            'refs'        => [$t->maintenance_id],
            'odometer'    => $m ? $this->rowOdometer($m) : null,
            // A ticket's symptom was typed against a chosen category by a person — it is a named fault
            // unless it is literally blank.
            'finding_specificity' => $t->symptom ? 'specific' : 'none',
            'date'        => $date ? Carbon::parse($date)->toDateString() : null,
            'garage'      => optional(optional($m)->vendor)->name ?? (optional($m)->garage ?: 'Not recorded'),
            'severity'    => $this->normaliseSeverity($t->severity),
            'finding'     => $t->symptom ?: 'Fault recorded without a finding',
            'detail'      => collect([$t->notes, $t->resolution_note])->filter()->implode(' · ') ?: 'No notes recorded',
            'outcome'     => $this->taskOutcome($t),
            'outcome_i18n'=> $this->taskOutcomeI18n($t),
            'recurred'    => (bool) $t->recurrence_flagged,
            'major_work'  => $this->looksMajor($t->symptom . ' ' . $t->resolution_note),
        ];
    }

    /** A sheet/manual workshop entry — date, garage, finding and notes, exactly as logged. */
    private function fromSheetRow(Maintenance $m, string $system): array
    {
        $finding = $this->systemFinding($m, $system);

        return [
            'source'      => 'workshop log',
            'ref'         => $m->id,
            'refs'        => [$m->id],
            'odometer'    => $this->rowOdometer($m),
            'finding_specificity' => $finding['specificity'],
            'date'        => optional($m->out_date)->toDateString(),
            'garage'      => optional($m->vendor)->name ?? ($m->garage ?: 'Not recorded'),
            'severity'    => $this->normaliseSeverity($m->fault_severity ?: $m->severity),
            'finding'     => $finding['text'],
            'detail'      => collect([$m->garage_feedback, $m->maintenance_notes, $m->spare_part])->filter()->implode(' · ') ?: 'No notes recorded',
            'outcome'     => $m->actual_in_date ? 'Car returned ' . $m->actual_in_date->toDateString() : 'No return logged',
            'outcome_i18n'=> $m->actual_in_date
                ? $this->tr('reportSystem.outcome.carReturned', ['date' => $m->actual_in_date->toDateString()])
                : $this->tr('reportSystem.outcome.noReturnLogged'),
            'recurred'    => false,
            'major_work'  => $this->looksMajor(($m->service_sup ?? '') . ' ' . ($m->spare_part ?? '') . ' ' . ($m->maintenance_notes ?? '')),
        ];
    }

    /**
     * The part of a sheet row's finding that actually belongs to THIS system.
     *
     * One workshop visit fixes several unrelated things, and the sheet records them as one comma-
     * separated string: "Rim Scratch, Cooling Fan Malfunction, Minor Surface Scratch, Engine Oil
     * Leak". Reproducing that whole string on an Engine dashboard puts rim scratches on it and makes
     * every visit look like a different, unique failure — so nothing ever groups and the failure mix
     * becomes a list of one-offs.
     *
     * So the string is split back into its phrases and only the phrases that resolve to this system
     * are kept. A visit that mentioned engine oil leak and a rim scratch appears here as "Engine Oil
     * Leak" — the rim scratch is the bodywork dashboard's business, not this one's.
     */
    private function systemFinding(Maintenance $m, string $system): array
    {
        // The two columns are not peers. `service_sup` holds the SPECIFIC findings ("Engine Oil
        // Leak"); `service_main` holds the sheet's own CATEGORY word for the visit ("Engine"). Taking
        // both gives "Engine Oil Leak, Engine" — the category restating itself next to the finding it
        // already covers, which then fails to group against the same finding logged without it. So
        // the specific column is asked first, and the category word is used ONLY when the visit
        // recorded no specific finding for this system at all.
        // WHICH COLUMN ANSWERED MATTERS AS MUCH AS THE ANSWER. `service_sup` names a fault;
        // `service_main` names the visit's category. A finding that came from the category column is
        // not evidence of a fault, and everything downstream — recurrence, risk, confidence — has to
        // know the difference, so the specificity is returned with the text rather than re-guessed.
        $phrases = $this->systemPhrases($m->service_sup, $system);
        if ($phrases->isNotEmpty()) {
            return ['text' => $phrases->implode(', '), 'specificity' => 'specific'];
        }

        $phrases = $this->systemPhrases($m->service_main, $system);
        if ($phrases->isNotEmpty()) {
            return ['text' => $phrases->implode(', '), 'specificity' => 'generic'];
        }

        return [
            'text'        => self::SYSTEMS[$system] . ' work (the entry named no specific finding)',
            'specificity' => 'none',
        ];
    }

    /**
     * The best odometer reading this workshop row carries, if any.
     *
     * The table has eight odometer columns for the different stages of a visit. Fleet-wide, at most 51
     * of 21,546 workshop rows carry any of them — so this returns null far more often than not, and
     * every consumer must treat "no reading" as a first-class answer rather than reaching for the
     * vehicle's current odometer, which would date a 2026 reading to a 2024 incident.
     */
    private function rowOdometer(Maintenance $m): ?int
    {
        foreach (['report_odometer', 'intake_odometer', 'receive_odometer', 'dispatch_odometer',
            'test_odometer', 'return_odometer', 'reinspect_odometer', 'park_odometer'] as $col) {
            if (! empty($m->{$col}) && $m->{$col} > 0) {
                return (int) $m->{$col};
            }
        }

        return null;
    }

    /**
     * The comma-separated phrases of one column that belong to the given system, de-duplicated
     * case-insensitively — the sheet writes "Engine Oil leak" and "Engine Oil Leak" for the same
     * thing, and a casing difference must not fork one recurring fault into two.
     *
     * @return Collection<int, string>
     */
    private function systemPhrases(?string $text, string $system): Collection
    {
        if (! is_string($text) || trim($text) === '') {
            return collect();
        }

        return collect(explode(',', $text))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->filter(fn ($p) => in_array($system, $this->categories->extractCategories($p, null, null), true))
            ->unique(fn ($p) => mb_strtolower($p))
            ->values();
    }

    private function taskOutcome(MaintenanceTask $t): string
    {
        return match ($t->status) {
            MaintenanceTask::STATUS_COMPLETED   => 'Repaired' . ($t->resolved_at ? ' ' . Carbon::parse($t->resolved_at)->toDateString() : ''),
            MaintenanceTask::STATUS_NOT_FOUND   => 'Workshop found no fault',
            MaintenanceTask::STATUS_CANCELLED   => 'Ruled not a real fault',
            MaintenanceTask::STATUS_IN_PROGRESS => 'Still being worked',
            MaintenanceTask::STATUS_PENDING     => 'Open, not started',
            default                             => 'Status: ' . ($t->status ?: 'not recorded'),
        };
    }

    /** @see taskOutcome — the same outcomes as catalog keys. */
    private function taskOutcomeI18n(MaintenanceTask $t): array
    {
        $resolved = $t->resolved_at ? Carbon::parse($t->resolved_at)->toDateString() : null;

        return match ($t->status) {
            MaintenanceTask::STATUS_COMPLETED   => $resolved
                ? $this->tr('reportSystem.outcome.repairedOn', ['date' => $resolved])
                : $this->tr('reportSystem.outcome.repaired'),
            MaintenanceTask::STATUS_NOT_FOUND   => $this->tr('reportSystem.outcome.noFaultFound'),
            MaintenanceTask::STATUS_CANCELLED   => $this->tr('reportSystem.outcome.notARealFault'),
            MaintenanceTask::STATUS_IN_PROGRESS => $this->tr('reportSystem.outcome.inProgress'),
            MaintenanceTask::STATUS_PENDING     => $this->tr('reportSystem.outcome.pending'),
            // An unmapped status is raw data, not a phrase we can promise a translation for.
            default                             => $this->raw('Status: ' . ($t->status ?: 'not recorded')),
        };
    }

    private function looksMajor(?string $text): bool
    {
        $text = mb_strtolower((string) $text);

        foreach (self::MAJOR_WORK_TOKENS as $token) {
            if ($text !== '' && str_contains($text, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseSeverity(?string $value): ?string
    {
        $value = $value ? mb_strtolower(trim($value)) : null;

        return in_array($value, Maintenance::FAULT_SEVERITIES, true) ? $value : null;
    }

    // ── Translatable text ───────────────────────────────────────────────────────────────────────

    /**
     * WHY THIS SERVICE DOES NOT RETURN ENGLISH SENTENCES.
     *
     * Every generated line on this page carries a number — "3 recorded events", "144 days apart".
     * The frontend phrase catalog matches whole sentences exactly, so a baked-in number makes a line
     * permanently untranslatable: "3 recorded events" and "4 recorded events" are two different
     * misses. The page could therefore never render in Arabic while the wording was assembled here.
     *
     * So the service emits a CODE plus its PARAMS and the catalog owns the wording, per
     * [[reason-code-contract]]. Three node shapes cover everything, and `tx()` on the frontend
     * renders all three:
     *
     *   tr()   — translatable: a catalog key and the values to interpolate.
     *   raw()  — verbatim: a garage's name, a fault as the fitter wrote it, a date. RECORDED DATA,
     *            never translated. Arabic UI showing an English garage note is correct — that note
     *            is what the record says, and rewriting it would be inventing evidence.
     *   joinTr() — several nodes rendered in order with a separator.
     *
     * `plural` marks a count-bearing string: Arabic has six plural categories to English's two, so
     * those resolve through tp() rather than plain interpolation.
     */
    private function tr(string $code, array $params = [], bool $plural = false): array
    {
        return ['code' => $code, 'params' => (object) $params, 'plural' => $plural];
    }

    /** Recorded data, reproduced exactly as logged and never translated. */
    private function raw(?string $text): array
    {
        return ['text' => (string) $text];
    }

    /**
     * A garage name is data — except when there isn't one. "Not recorded" is this report's own words
     * for an empty column, so it translates; the workshop's actual name never does.
     */
    private function garageNode(?string $garage): array
    {
        return ($garage === null || $garage === '' || $garage === 'Not recorded')
            ? $this->tr('reportSystem.notRecorded')
            : $this->raw($garage);
    }

    /** @param  array<int, array>  $parts */
    private function joinTr(array $parts, string $sep = ' · '): array
    {
        return ['parts' => array_values(array_filter($parts)), 'sep' => $sep];
    }

    /** The catalog key for a severity, including the "never rated" case. */
    private function severityCode(?string $severity): string
    {
        return 'reportSystem.severity.' . ($severity ?: 'unrated');
    }

    // ── The score ───────────────────────────────────────────────────────────────────────────────

    /**
     * Risk as five auditable components. Every one reports the count it read, the points that count
     * earned, AND the specific records that produced the count.
     *
     * ON SHOWING THE EVIDENCE: a component that says only "0 rated Critical" is unreadable — the
     * reader cannot tell whether nothing serious happened or whether nobody filled the severity field
     * in. So each component carries `evidence` (the rows it counted, named and dated) and, where the
     * count could not have been anything else, a `caveat` saying so. A zero that was structurally
     * impossible to beat is a different fact from a zero that was earned, and the two must not print
     * the same way.
     */
    private function risk(Collection $events, string $system, array $analysis): array
    {
        $incidents  = collect($analysis['incidents']);
        $recurrence = $analysis['recurrence'];
        $confidence = $analysis['confidence'];

        $incidentCount = $incidents->count();
        $confirmed     = $incidents->where('is_confirmed', true);
        $criticals     = $confirmed->where('severity', Maintenance::FAULT_SEVERITY_CRITICAL)->count();

        // Recency runs from the last CONFIRMED fault. Falling back to the last incident of any kind
        // would let a generic workshop row keep a car's recency at full marks indefinitely.
        $lastConfirmed = $confirmed->last();
        $lastAny       = $incidents->last();
        $recencyFrom   = $lastConfirmed ?: $lastAny;
        $daysSince     = ($recencyFrom && $recencyFrom['start'])
            ? Carbon::parse($recencyFrom['start'])->diffInDays(Carbon::today())
            : null;

        // Only completed work counts as a repair to measure against — recommendations do not.
        $repairs   = collect($analysis['durability']);
        $returned  = $repairs->whereIn('verdict', ['same_fault_returned', 'different_fault_followed']);
        $sameFault = $repairs->where('verdict', 'same_fault_returned')->count();

        /*
         * RECURRENCE IS SCORED BY WHAT THE RECORD ESTABLISHES, NOT BY TEXT REPETITION.
         *
         * The old component matched finding strings and awarded points whenever two matched. Four
         * workshop rows whose only engine content was the category word "Engine" matched each other
         * perfectly and scored as a recurring engine fault. They are now worth nothing here: a repeat
         * of a category label is not a repeat of a fault. Repeated visits for the system still count,
         * but at a third of the weight and under their own name.
         */
        $repeatedFaults = count($recurrence['repeated_faults']);
        [$recurrencePoints, $recurrenceKey] = match ($recurrence['status']) {
            'confirmed_recurring_fault' => [
                (int) round(min($repeatedFaults, 3) / 3 * self::RISK_WEIGHTS['recurrence']),
                'confirmed',
            ],
            'recurring_system_visits' => [
                (int) round(min($incidentCount - 1, 3) / 3 * (self::RISK_WEIGHTS['recurrence'] / 3)),
                'visits',
            ],
            default => [0, $recurrence['status'] === 'insufficient_data' ? 'insufficient' : 'none'],
        };

        $components = [
            [
                'key'      => 'volume',
                'label'    => 'How many separate incidents',
                'input'    => $incidentCount . ' ' . Str::plural('incident', $incidentCount)
                    . ' (' . $events->count() . ' source ' . Str::plural('record', $events->count()) . ')',
                'points'   => (int) round(min($incidentCount, 8) / 8 * self::RISK_WEIGHTS['volume']),
                'max'      => self::RISK_WEIGHTS['volume'],
                'rule'     => '8 or more separate incidents reaches the full ' . self::RISK_WEIGHTS['volume'] . ' points.',
                'detail'   => $this->incidentSpanDetail($incidents),
                'evidence' => $this->incidentEvidence($incidents),
                'caveat'   => $incidentCount === $events->count() ? null
                    : ($events->count() - $incidentCount) . ' of the ' . $events->count()
                        . ' source records were follow-up rows of an incident already counted, so they add nothing here.',
                'i18n'     => [
                    'label'  => $this->tr('reportSystem.risk.volume.label'),
                    'input'  => $this->tr('reportSystem.risk.volume.input', ['n' => $incidentCount, 'rows' => $events->count()], true),
                    'rule'   => $this->tr('reportSystem.risk.volume.rule', ['max' => self::RISK_WEIGHTS['volume']]),
                    'detail' => $this->incidentSpanDetailI18n($incidents),
                    'caveat' => $incidentCount === $events->count() ? null
                        : $this->tr('reportSystem.risk.volume.caveatFollowUps', [
                            'n' => $events->count() - $incidentCount, 'rows' => $events->count(),
                        ], true),
                ],
            ],
            [
                'key'      => 'severity',
                'label'    => 'How serious the identified faults were',
                'input'    => $criticals . ' rated Critical',
                'points'   => (int) round(min($criticals, 3) / 3 * self::RISK_WEIGHTS['severity']),
                'max'      => self::RISK_WEIGHTS['severity'],
                'rule'     => '3 or more Critical incidents reaches the full ' . self::RISK_WEIGHTS['severity'] . ' points.',
                'detail'   => $confirmed->isEmpty()
                    ? 'No incident names a fault, so there is nothing here to rate.'
                    : $confirmed->count() . ' of ' . $incidentCount . ' incidents name a fault; '
                        . $confirmed->filter(fn ($i) => ! empty($i['severity']))->count() . ' of those carry a severity.',
                'evidence' => $this->severityIncidentEvidence($confirmed),
                'caveat'   => $confirmed->isEmpty()
                    ? 'Severity is only recorded against identified faults. This system has none, so this component cannot score.'
                    : null,
                'i18n'     => [
                    'label'  => $this->tr('reportSystem.risk.severity.label'),
                    'input'  => $this->tr('reportSystem.risk.severity.input', ['n' => $criticals], true),
                    'rule'   => $this->tr('reportSystem.risk.severity.rule', ['max' => self::RISK_WEIGHTS['severity']]),
                    'detail' => $confirmed->isEmpty()
                        ? $this->tr('reportSystem.risk.severity.detailNone')
                        : $this->tr('reportSystem.risk.severity.detail', [
                            'named' => $confirmed->count(),
                            'total' => $incidentCount,
                            'rated' => $confirmed->filter(fn ($i) => ! empty($i['severity']))->count(),
                        ]),
                    'caveat' => $confirmed->isEmpty() ? $this->tr('reportSystem.risk.severity.caveatNoNamed') : null,
                ],
            ],
            [
                'key'      => 'recurrence',
                'label'    => 'The same identified fault coming back',
                'input'    => $this->recurrenceInput($recurrence),
                'points'   => $recurrencePoints,
                'max'      => self::RISK_WEIGHTS['recurrence'],
                'rule'     => 'Only a repeat of the SAME named fault scores in full; repeated visits for the system score at most a third.',
                'detail'   => $this->recurrenceDetail($recurrence),
                'evidence' => $this->recurrenceIncidentEvidence($recurrence, $incidents),
                'caveat'   => $recurrenceKey === 'insufficient'
                    ? 'The car came back for this system, but no incident names a fault — so whether the SAME fault recurred cannot be decided either way, and this component scores nothing rather than guessing.'
                    : null,
                'i18n'     => [
                    'label'  => $this->tr('reportSystem.risk.recurrence.label'),
                    'input'  => $this->tr('reportSystem.risk.recurrence.input.' . $recurrenceKey, [
                        'n' => $repeatedFaults, 'incidents' => $incidentCount,
                    ], true),
                    'rule'   => $this->tr('reportSystem.risk.recurrence.rule'),
                    'detail' => $this->recurrenceDetailI18n($recurrence),
                    'caveat' => $recurrenceKey === 'insufficient'
                        ? $this->tr('reportSystem.risk.recurrence.caveatInsufficient') : null,
                ],
            ],
            [
                'key'      => 'recency',
                'label'    => 'How recently it last failed',
                'input'    => $daysSince === null ? 'no dated incident' : $daysSince . ' days ago',
                'points'   => $lastConfirmed
                    ? $this->recencyPoints($daysSince)
                    // No named fault: recency is measured off a workshop visit, which is weaker
                    // evidence of a live problem, so it can reach only half the weight.
                    : (int) round($this->recencyPoints($daysSince) / 2),
                'max'      => self::RISK_WEIGHTS['recency'],
                'rule'     => 'Within 90 days scores full; 91–180 two thirds; 181–365 a third; older nothing. Halved when measured off a visit rather than an identified fault.',
                'detail'   => $recencyFrom
                    ? ($lastConfirmed
                        ? 'Measured from the last identified fault, ' . $recencyFrom['start'] . '.'
                        : 'No fault has ever been identified, so this is measured from the last workshop visit, ' . $recencyFrom['start'] . ' — and halved.')
                    : 'No incident carries a date.',
                'evidence' => $this->recencyIncidentEvidence($recencyFrom),
                'caveat'   => null,
                'i18n'     => [
                    'label'  => $this->tr('reportSystem.risk.recency.label'),
                    'input'  => $daysSince === null
                        ? $this->tr('reportSystem.risk.recency.inputNone')
                        : $this->tr('reportSystem.risk.recency.input', ['n' => $daysSince], true),
                    'rule'   => $this->tr('reportSystem.risk.recency.rule'),
                    'detail' => $recencyFrom
                        ? ($lastConfirmed
                            ? $this->tr('reportSystem.risk.recency.detail', ['date' => $recencyFrom['start']])
                            : $this->tr('reportSystem.risk.recency.detailVisit', ['date' => $recencyFrom['start']]))
                        : $this->tr('reportSystem.risk.recency.detailNone'),
                    'caveat' => null,
                ],
            ],
            [
                'key'      => 'post_repair',
                'label'    => 'Failures after work was done',
                'input'    => $returned->count() . ' of ' . $repairs->count() . ' recorded repairs were followed by another incident',
                'points'   => $repairs->isEmpty() ? 0
                    : (int) round(min($sameFault * 2 + $returned->count(), 4) / 4 * self::RISK_WEIGHTS['post_repair']),
                'max'      => self::RISK_WEIGHTS['post_repair'],
                'rule'     => 'The same fault returning after a repair counts double. 4 points of weight reaches the full ' . self::RISK_WEIGHTS['post_repair'] . '.',
                'detail'   => $repairs->isEmpty()
                    ? 'No completed repair or replacement is recorded for this system, so there is nothing to measure against.'
                    : $repairs->count() . ' completed ' . Str::plural('repair', $repairs->count()) . ' found in the notes; '
                        . $sameFault . ' were followed by the same named fault.',
                'evidence' => $this->durabilityEvidence($analysis['durability']),
                'caveat'   => $repairs->isEmpty()
                    ? 'Parts and labour are not recorded as structured lines on this fleet, so a repair is only visible here when a note says one was done. This component cannot score without that, and the highest total this car could reach is '
                        . (100 - self::RISK_WEIGHTS['post_repair']) . ', not 100.'
                    : null,
                'i18n'     => [
                    'label'  => $this->tr('reportSystem.risk.postRepair.label'),
                    'input'  => $this->tr('reportSystem.risk.postRepair.input', [
                        'n' => $returned->count(), 'total' => $repairs->count(),
                    ], true),
                    'rule'   => $this->tr('reportSystem.risk.postRepair.rule', ['max' => self::RISK_WEIGHTS['post_repair']]),
                    'detail' => $repairs->isEmpty()
                        ? $this->tr('reportSystem.risk.postRepair.detailNone')
                        : $this->tr('reportSystem.risk.postRepair.detail', [
                            'n' => $repairs->count(), 'same' => $sameFault,
                        ]),
                    'caveat' => $repairs->isEmpty()
                        ? $this->tr('reportSystem.risk.postRepair.caveat', ['ceiling' => 100 - self::RISK_WEIGHTS['post_repair']])
                        : null,
                ],
            ],
        ];

        $score = array_sum(array_column($components, 'points'));

        // The ceiling is what this record could reach at worst, given the components it structurally
        // cannot earn. A score of 50/100 on a car whose ceiling is 65 means something very different
        // from 50/100 on a car that could have reached 100.
        $ceiling = 100
            - ($confirmed->isEmpty() ? self::RISK_WEIGHTS['severity'] : 0)
            - ($repairs->isEmpty() ? self::RISK_WEIGHTS['post_repair'] : 0)
            - (in_array($recurrence['status'], ['no_evidence', 'insufficient_data'], true) ? self::RISK_WEIGHTS['recurrence'] : 0);

        return [
            'score'      => $score,
            'health'     => 100 - $score,
            'ceiling'    => $ceiling,
            'band'       => $this->riskBand($score),
            'confidence' => $confidence,
            'components' => $components,
            'basis'      => 'Sum of five counts over the INCIDENTS below — not over source rows. No model, no prediction; the arithmetic is printed beside each component with the records it counted.',
            'basis_i18n' => $this->tr('reportSystem.risk.basis'),
        ];
    }

    /** Risk expressed as a word, because a bare number invites more precision than the data supports. */
    private function riskBand(int $score): string
    {
        return match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'moderate',
            $score >= 20 => 'low',
            default      => 'minimal',
        };
    }

    // ── Evidence for the incident-based components ──────────────────────────────────────────────

    private function incidentSpanDetail(Collection $incidents): ?string
    {
        $dates = $incidents->pluck('start')->filter()->sort()->values();

        if ($dates->count() < 2) {
            return $dates->count() === 1 ? 'A single incident, on ' . $dates->first() . '.' : null;
        }

        $days = Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last()));

        return 'From ' . $dates->first() . ' to ' . $dates->last() . ' — ' . $days . ' days apart.';
    }

    private function incidentSpanDetailI18n(Collection $incidents): ?array
    {
        $dates = $incidents->pluck('start')->filter()->sort()->values();

        if ($dates->count() < 2) {
            return $dates->count() === 1
                ? $this->tr('reportSystem.risk.volume.detailOne', ['date' => $dates->first()])
                : null;
        }

        return $this->tr('reportSystem.risk.volume.detail', [
            'from' => $dates->first(),
            'to'   => $dates->last(),
            'n'    => Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last())),
        ], true);
    }

    /** Each incident, saying plainly what it was and how many rows it absorbed. */
    private function incidentEvidence(Collection $incidents): array
    {
        return $incidents
            ->sortByDesc('start')
            ->take(12)
            ->map(fn (array $i) => $this->evidenceRow(
                $i['start'],
                $i['fault'] ?: 'No fault named — workshop activity only',
                collect([
                    $i['garages'] ? implode(', ', $i['garages']) : 'garage not recorded',
                    $i['row_count'] > 1 ? $i['row_count'] . ' rows, one incident' : '1 row',
                    $i['strength'] . ' evidence',
                ])->implode(' · '),
                $i['fault'] ? null : $this->tr('reportSystem.incident.noFaultNamed'),
                $this->joinTr([
                    $i['garages'] ? $this->raw(implode(', ', $i['garages'])) : $this->tr('reportSystem.notRecorded'),
                    $this->tr('reportSystem.incident.rowCount', ['n' => $i['row_count']], true),
                    $this->tr('reportSystem.strength.' . $i['strength']),
                ])
            ))
            ->values()
            ->all();
    }

    private function severityIncidentEvidence(Collection $confirmed): array
    {
        return $confirmed
            ->sortByDesc('start')
            ->map(fn (array $i) => $this->evidenceRow(
                $i['start'],
                $i['fault'],
                $i['severity'] ? ucfirst($i['severity']) : 'no severity recorded',
                null,
                $i['severity']
                    ? $this->tr($this->severityCode($i['severity']))
                    : $this->tr('reportSystem.incident.noSeverity')
            ))
            ->values()
            ->all();
    }

    private function recurrenceInput(array $recurrence): string
    {
        return match ($recurrence['status']) {
            'confirmed_recurring_fault' => count($recurrence['repeated_faults']) . ' named fault(s) recorded in more than one incident',
            'recurring_system_visits'   => $recurrence['incident_count'] . ' separate incidents, but no fault repeats',
            'insufficient_data'         => $recurrence['incident_count'] . ' incidents, none naming a fault',
            default                     => 'no repeat on record',
        };
    }

    private function recurrenceDetail(array $recurrence): string
    {
        return match ($recurrence['status']) {
            'confirmed_recurring_fault' => 'The same named fault appears in more than one incident.',
            'recurring_system_visits'   => 'The car came back for this system, but the incidents name different faults — repeated activity, not a proven repeat of one fault.',
            'insufficient_data'         => 'The car came back for this system, but the records name no fault, so recurrence cannot be decided either way.',
            default                     => 'There is at most one incident on record for this system.',
        };
    }

    private function recurrenceDetailI18n(array $recurrence): array
    {
        return $this->tr('reportSystem.risk.recurrence.detail.' . match ($recurrence['status']) {
            'confirmed_recurring_fault' => 'confirmed',
            'recurring_system_visits'   => 'visits',
            'insufficient_data'         => 'insufficient',
            default                     => 'none',
        });
    }

    private function recurrenceIncidentEvidence(array $recurrence, Collection $incidents): array
    {
        if ($recurrence['repeated_faults'] !== []) {
            return collect($recurrence['repeated_faults'])
                ->map(fn (array $f) => $this->evidenceRow(
                    null,
                    $f['fault'] . ' — ' . $f['count'] . ' incidents',
                    implode(' → ', $f['incidents']),
                    $this->tr('reportSystem.evidence.repeatedFinding', ['finding' => $f['fault'], 'n' => $f['count']], true)
                ))
                ->all();
        }

        // Nothing repeated: show what there IS, so the zero can be checked rather than trusted.
        return $incidents
            ->sortByDesc('start')
            ->take(8)
            ->map(fn (array $i) => $this->evidenceRow(
                $i['start'],
                $i['fault'] ?: 'No fault named',
                $i['kind'],
                $i['fault'] ? null : $this->tr('reportSystem.incident.noFaultNamed'),
                $this->tr('reportSystem.kind.' . $i['kind'])
            ))
            ->values()
            ->all();
    }

    private function recencyIncidentEvidence(?array $incident): array
    {
        if (! $incident || ! $incident['start']) {
            return [];
        }

        return [$this->evidenceRow(
            $incident['start'],
            $incident['fault'] ?: 'No fault named — workshop activity only',
            $incident['garages'] ? implode(', ', $incident['garages']) : 'garage not recorded',
            $incident['fault'] ? null : $this->tr('reportSystem.incident.noFaultNamed'),
            $incident['garages'] ? $this->raw(implode(', ', $incident['garages'])) : $this->tr('reportSystem.notRecorded')
        )];
    }

    /** Each completed repair and what the record shows after it. */
    private function durabilityEvidence(array $durability): array
    {
        return collect($durability)
            ->map(fn (array $d) => $this->evidenceRow(
                $d['date'],
                trim(($d['work']['component'] ? ucfirst($d['work']['component']) . ' — ' : '') . $d['work']['action']),
                $this->durabilityLine($d),
                null,
                $this->tr('reportSystem.durability.' . $d['verdict'], [
                    'days' => $d['next_confirmed']['days'] ?? $d['next_incident']['days'] ?? 0,
                ])
            ))
            ->all();
    }

    private function durabilityLine(array $d): string
    {
        return match ($d['verdict']) {
            'same_fault_returned'      => 'the same fault returned after ' . $d['next_confirmed']['days'] . ' days',
            'different_fault_followed' => 'a different fault followed after ' . $d['next_confirmed']['days'] . ' days',
            'system_activity_followed' => 'the car came back for this system after ' . $d['next_incident']['days'] . ' days, no fault named',
            default                    => 'nothing further is recorded — which is not the same as the repair holding',
        };
    }

    // ── The evidence behind each component ──────────────────────────────────────────────────────

    /**
     * One evidence row. `date` may be null for a row that summarises rather than points at a day.
     *
     * @return array{date: ?string, text: string, meta: ?string}
     */
    private function evidenceRow(?string $date, string $text, ?string $meta = null, ?array $textI18n = null, ?array $metaI18n = null): array
    {
        return [
            'date'      => $date,
            'text'      => $text,
            'meta'      => $meta,
            // Null where the row is pure recorded data — the frontend then prints `text` verbatim.
            'text_i18n' => $textI18n,
            'meta_i18n' => $metaI18n,
        ];
    }

    /** The catalog key for where an entry came from — a per-fault ticket or the workshop log. */
    private function sourceNode(string $source): array
    {
        return $this->tr('reportSystem.source.' . ($source === 'ticket' ? 'ticket' : 'workshopLog'));
    }

    /** How far apart the events are — three failures in a month is not three across four years. */
    private function spanDetail(Collection $events): ?string
    {
        $dated = $events->pluck('date')->filter()->sort()->values();

        if ($dated->count() < 2) {
            return $dated->count() === 1 ? 'A single dated event, on ' . $dated->first() . '.' : null;
        }

        $days = Carbon::parse($dated->first())->diffInDays(Carbon::parse($dated->last()));

        return 'From ' . $dated->first() . ' to ' . $dated->last() . ' — ' . $days . ' days apart.';
    }

    /** @see spanDetail — the same three cases, as catalog keys. */
    private function spanDetailI18n(Collection $events): ?array
    {
        $dated = $events->pluck('date')->filter()->sort()->values();

        if ($dated->count() < 2) {
            return $dated->count() === 1
                ? $this->tr('reportSystem.risk.volume.detailOne', ['date' => $dated->first()])
                : null;
        }

        return $this->tr('reportSystem.risk.volume.detail', [
            'from' => $dated->first(),
            'to'   => $dated->last(),
            'n'    => Carbon::parse($dated->first())->diffInDays(Carbon::parse($dated->last())),
        ], true);
    }

    /** Every event the volume component counted, most recent first. */
    private function volumeEvidence(Collection $events): array
    {
        return $events
            ->sortByDesc('date')
            ->take(12)
            ->map(fn (array $e) => $this->evidenceRow(
                $e['date'],
                $e['finding'],
                $e['garage'] . ' · ' . $e['source'] . ' · ' . $e['outcome'],
                null, // the finding is the garage's own wording — never translated
                $this->joinTr([
                    $this->garageNode($e['garage']),
                    $this->sourceNode($e['source']),
                    $e['outcome_i18n'] ?? $this->raw($e['outcome']),
                ])
            ))
            ->values()
            ->all();
    }

    /** What the severities actually were — including how many were never rated. */
    private function severityDetail(Collection $events): ?string
    {
        if ($events->isEmpty()) {
            return null;
        }

        $counts = $events
            ->groupBy(fn (array $e) => $e['severity'] ?? 'unrated')
            ->map(fn (Collection $g) => $g->count());

        return $counts
            ->map(fn (int $n, string $sev) => $n . ' ' . Str::ucfirst($sev))
            ->implode(' · ');
    }

    /** @see severityDetail — one node per severity, joined by the frontend. */
    private function severityDetailI18n(Collection $events): ?array
    {
        if ($events->isEmpty()) {
            return null;
        }

        return $this->joinTr(
            $events
                ->groupBy(fn (array $e) => $e['severity'] ?? 'unrated')
                ->map(fn (Collection $g, string $sev) => $this->tr(
                    'reportSystem.severityCount.' . $sev,
                    ['n' => $g->count()],
                    true
                ))
                ->values()
                ->all()
        );
    }

    /** The events that carry each severity, so a rating can be traced to the day it was given. */
    private function severityEvidence(Collection $events): array
    {
        return $events
            ->groupBy(fn (array $e) => $e['severity'] ?? 'unrated')
            ->map(fn (Collection $g, string $sev) => $this->evidenceRow(
                null,
                Str::ucfirst($sev) . ' — ' . $g->count() . ' ' . Str::plural('event', $g->count()),
                $g->sortByDesc('date')->pluck('date')->filter()->implode(', ') ?: null,
                $this->tr('reportSystem.evidence.severityGroup.' . $sev, ['n' => $g->count()], true)
            ))
            ->values()
            ->all();
    }

    /** Each finding that appeared more than once, with every date it appeared on. */
    private function recurrenceEvidence(Collection $events): array
    {
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->sortByDesc(fn (Collection $g) => $g->count())
            ->map(fn (Collection $g) => $this->evidenceRow(
                null,
                trim($g->first()['finding']) . ' — ' . $g->count() . ' times',
                $g->sortBy('date')->map(fn (array $e) => $e['date'] . ' (' . $e['garage'] . ')')->implode(' → '),
                // The finding stays the garage's word; only the "— N times" tail is translated.
                $this->tr('reportSystem.evidence.repeatedFinding', [
                    'finding' => trim($g->first()['finding']),
                    'n'       => $g->count(),
                ], true)
            ))
            ->values()
            ->all();
    }

    /** The single event the recency clock is measured from. */
    private function recencyEvidence(?array $last): array
    {
        if (! $last || ! $last['date']) {
            return [];
        }

        return [$this->evidenceRow(
            $last['date'],
            $last['finding'],
            $last['garage'] . ' · ' . $last['outcome'],
            null,
            $this->joinTr([
                $this->garageNode($last['garage']),
                $last['outcome_i18n'] ?? $this->raw($last['outcome']),
            ])
        )];
    }

    /**
     * The replacement itself, then every failure recorded after it — the sequence that the component
     * is actually claiming. Without the replacement row printed alongside, "2 events after replacement
     * work" cannot be checked against anything.
     */
    private function postRepairEvidence(Collection $events, ?array $firstMajor): array
    {
        if (! $firstMajor) {
            return [];
        }

        $rows = [$this->evidenceRow(
            $firstMajor['date'],
            'The replacement: ' . $firstMajor['finding'],
            $firstMajor['garage'] . ' · everything below is dated after this',
            $this->tr('reportSystem.evidence.theReplacement', ['finding' => $firstMajor['finding']]),
            $this->joinTr([
                $this->garageNode($firstMajor['garage']),
                $this->tr('reportSystem.evidence.datedAfterThis'),
            ])
        )];

        foreach ($events->filter(fn (array $e) => $e['date'] > $firstMajor['date'])->sortBy('date') as $e) {
            $rows[] = $this->evidenceRow(
                $e['date'],
                $e['finding'],
                $e['garage'] . ' · ' . $e['outcome'],
                null,
                $this->joinTr([
                    $this->garageNode($e['garage']),
                    $e['outcome_i18n'] ?? $this->raw($e['outcome']),
                ])
            );
        }

        return $rows;
    }

    private function recencyPoints(?int $daysSince): int
    {
        if ($daysSince === null) {
            return 0;
        }

        $max = self::RISK_WEIGHTS['recency'];

        return match (true) {
            $daysSince <= 90  => $max,
            $daysSince <= 180 => (int) round($max * 2 / 3),
            $daysSince <= 365 => (int) round($max / 3),
            default           => 0,
        };
    }

    /** How many distinct findings appear more than once in the timeline. */
    private function repeatCount(Collection $events): int
    {
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->count();
    }

    /** Events dated after the FIRST entry that described a replacement/overhaul on this system. */
    private function postRepairFailures(Collection $events): int
    {
        $firstMajor = $events->first(fn (array $e) => $e['major_work']);

        if (! $firstMajor) {
            return 0;
        }

        return $events->filter(fn (array $e) => $e['date'] > $firstMajor['date'])->count();
    }

    // ── The verdict ─────────────────────────────────────────────────────────────────────────────

    /**
     * What the record supports. The rule that produced the line is printed with it, so the reader
     * can disagree with the rule rather than with an unexplained word.
     */
    /**
     * The verdict now carries the evidence grade with it.
     *
     * "REPAIR AND WATCH" read as a finding about the car; on this data it was largely a finding about
     * the paperwork. A decision line that cannot be acted on without knowing how good its evidence is
     * must not be shown without it, so the grade travels with the decision rather than sitting in a
     * panel further down that a reader may never reach.
     */
    private function verdict(array $risk, Collection $events, array $analysis): array
    {
        $score = $risk['score'];

        if ($events->isEmpty()) {
            return [
                'decision'      => 'NOTHING RECORDED',
                'tone'          => 'neutral',
                'status'        => 'No history on this system',
                'rule'          => 'No event for this system has ever been logged against this car.',
                'decision_i18n' => $this->tr('reportSystem.verdict.none.decision'),
                'status_i18n'   => $this->tr('reportSystem.verdict.none.status'),
                'rule_i18n'     => $this->tr('reportSystem.verdict.none.rule'),
            ];
        }

        $confidence = $analysis['confidence']['level'];

        [$decision, $tone, $status, $key] = match (true) {
            $score >= 80 => ['STOP INVESTMENT',   'danger',  'CRITICAL / UNSTABLE', 'stop'],
            $score >= 55 => ['MANAGEMENT REVIEW', 'warn',    'REPEATED FAILURES',   'review'],
            $score >= 30 => ['REPAIR AND WATCH',  'warn',    'ACTIVE HISTORY',      'watch'],
            default      => ['NORMAL',            'ok',      'STABLE',              'normal'],
        };

        /*
         * A decision drawn from weak evidence is downgraded, not dressed up. On LOW confidence the
         * only honest instruction is to improve the record before spending against it — the score may
         * be describing the paperwork rather than the car.
         */
        if ($confidence === 'low' && in_array($key, ['stop', 'review'], true)) {
            [$decision, $tone, $status, $key] = ['CHECK THE RECORD FIRST', 'warn', 'EVIDENCE TOO WEAK TO ACT ON', 'weak'];
        }

        return [
            'decision'      => $decision,
            'tone'          => $tone,
            'status'        => $status,
            'confidence'    => $confidence,
            'rule'          => 'Risk 80+ = stop investment · 55–79 = management review · 30–54 = repair and watch · under 30 = normal. A stop or review verdict built on LOW-confidence evidence is held back until the record is improved.',
            'decision_i18n' => $this->tr('reportSystem.verdict.' . $key . '.decision'),
            'status_i18n'   => $this->tr('reportSystem.verdict.' . $key . '.status'),
            'rule_i18n'     => $this->tr('reportSystem.verdict.rule'),
        ];
    }

    /**
     * MANAGEMENT TAKEAWAY — the two or three sentences a fleet manager can act on, assembled only from
     * counts this report can defend. It states what the record establishes, then what it does NOT.
     */
    private function takeaway(array $analysis, array $risk, string $system): array
    {
        $f    = $analysis['facts'];
        $rec  = $analysis['recurrence'];
        $conf = $analysis['confidence']['level'];

        return [
            'system'            => self::SYSTEMS[$system],
            'incidents'         => $f['incidents'],
            'records'           => $f['records'],
            'confirmed_faults'  => $f['confirmed_faults'],
            'recurrence_status' => $rec['status'],
            'latest_fault'      => $f['latest_confirmed']['fault'] ?? null,
            'latest_fault_date' => $f['latest_confirmed']['start'] ?? null,
            'latest_activity'   => $f['latest_incident']['start'] ?? null,
            'risk_band'         => $risk['band'],
            'confidence'        => $conf,
            'repairs_found'     => count($analysis['durability']),
            /*
             * The recommended actions are a fixed set keyed by what the evidence supports — not free
             * text, and never a diagnosis. "Require structured fault classification" is advice about
             * the RECORD; nothing here advises a repair, because this report cannot know what is wrong
             * with the car, only what was written down about it.
             */
            'actions' => array_values(array_filter([
                $rec['status'] === 'confirmed_recurring_fault' ? 'escalate_same_fault' : null,
                $rec['status'] === 'insufficient_data' ? 'require_fault_classification' : null,
                $conf === 'low' ? 'improve_record' : null,
                $f['confirmed_faults'] > 0 ? 'monitor_next_visit' : null,
                count($analysis['durability']) === 0 ? 'require_parts_recording' : null,
            ])),
        ];
    }

    /**
     * THE STRIP NOW COUNTS INCIDENTS AND CONFIRMED FAULTS, not rows and not string matches.
     *
     * "Recorded Events 5 / Repeated Findings 1 / Major Work 3" was three misleading numbers in a row:
     * five rows were three incidents, the repeat was the category word "Engine" matching itself, and
     * the major work was a TRANSMISSION replacement mentioned inside an engine-categorised visit.
     * Each tile is now a count this report can defend, and "days since" runs from an identified fault
     * rather than from any workshop row.
     */
    private function kpis(Collection $events, array $risk, array $analysis): array
    {
        $f    = $analysis['facts'];
        $rec  = $analysis['recurrence'];
        $last = $f['latest_confirmed']['start'] ?? null;

        $daysSince = $last ? Carbon::parse($last)->diffInDays(Carbon::today()) : null;

        /*
         * `scope` is not decoration. Five of these tiles count the SELECTED period and one — the risk
         * score — is all-history by construction (see build()). Six numbers in a row with no marking
         * would read as six answers to the same question, and the odd one out is the one a manager
         * would act on. The frontend prints the scope on the tile.
         */
        $kpi = fn (string $key, string $label, $value, string $note, ?string $noteKey = null, string $scope = 'period') => [
            'key'        => $key,
            'label'      => $label,
            'value'      => $value,
            'note'       => $note,
            'scope'      => $scope,
            'label_i18n' => $this->tr('reportSystem.kpi.' . $key . '.label'),
            'note_i18n'  => $this->tr('reportSystem.kpi.' . $key . '.' . ($noteKey ?: 'note')),
        ];

        return [
            $kpi('risk', 'Risk', $risk['score'] . ' / 100',
                'Evidence confidence: ' . strtoupper($analysis['confidence']['level']) . '.',
                null, 'history'),
            $kpi('incidents', 'Real Incidents', $f['incidents'],
                'Grouped from ' . $f['records'] . ' source records.'),
            $kpi('confirmed', 'Confirmed Faults', $f['confirmed_faults'],
                $f['confirmed_faults'] > 0
                    ? 'Incidents naming an actual fault.'
                    : 'No incident names a fault.',
                $f['confirmed_faults'] > 0 ? 'note' : 'noteNone'),
            $kpi('recurrence', 'Recurrence', count($rec['repeated_faults']),
                'Named faults recorded more than once.'),
            $kpi('repairs', 'Repairs Found', count($analysis['durability']),
                'Completed work described in the notes.'),
            $kpi('sinceFault', 'Days Since Fault', $daysSince ?? '—',
                $last ? 'Since ' . $last . '.' : 'No identified fault on record.',
                $last ? 'note' : 'noteNone'),
        ];
    }

    /** The mix of findings, biggest first — what actually goes wrong with this system on this car. */
    private function failureMix(Collection $events): array
    {
        // Grouped case-insensitively (the sheet writes "Engine Oil leak" and "Engine Oil Leak" for
        // one thing), labelled with the first spelling seen so the wording stays the garage's own.
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->map(fn (Collection $g) => ['label' => trim($g->first()['finding']), 'count' => $g->count()])
            ->sortByDesc('count')
            ->values()
            ->take(8)
            ->all();
    }

    /**
     * EVERY FAULT, AND EVERY DATE IT WAS LOGGED.
     *
     * The timeline answers "what happened, in order"; the donut answers "how often". Neither answers
     * the question actually asked about a problem car — *when did THIS fault happen, each time?* A
     * fault seen three times in one month is a different problem from the same three spread over two
     * years, and no ordering of the timeline makes that visible: the occurrences sit far apart, with
     * other faults between them.
     *
     * So the events are regrouped by finding, most-repeated first, each carrying every occurrence and
     * the gap between the first and the last. The gaps between consecutive occurrences are given too,
     * because "came back after 9 days" and "came back after 9 months" are not the same fact.
     */
    private function faultHistory(Collection $events, string $system): array
    {
        return $events
            ->groupBy(fn (array $e) => mb_strtolower(trim($e['finding'])))
            ->map(function (Collection $g) use ($system) {
                $ordered = $g->sortBy('date')->values();
                $dates   = $ordered->pluck('date')->filter()->values();

                $finding = trim($ordered->first()['finding']);

                $prev = null;
                $occurrences = $ordered->map(function (array $e) use (&$prev, $system, $finding) {
                    $gap = ($prev && $e['date'])
                        ? Carbon::parse($prev)->diffInDays(Carbon::parse($e['date']))
                        : null;
                    $prev = $e['date'] ?? $prev;

                    return [
                        'date'        => $e['date'],
                        'garage'      => $e['garage'],
                        'garage_i18n' => $this->garageNode($e['garage']),
                        'severity'    => $e['severity'],
                        'source'      => $e['source'],
                        'source_i18n' => $this->sourceNode($e['source']),
                        'outcome'     => $e['outcome'],
                        'outcome_i18n'=> $e['outcome_i18n'] ?? $this->raw($e['outcome']),
                        'ref'         => $e['ref'],
                        'major_work'  => $e['major_work'],
                        // Days since the PREVIOUS time this same fault was logged. Null on the first.
                        'gap_days'    => $gap,
                        /*
                         * A gap of a day or less is almost never a fault returning. The sheet writes
                         * the stages of one trip on consecutive days — the OUT row on the 11th, the
                         * garage's write-up on the 12th — and collapseVisits only merges rows sharing
                         * a date, so those two survive as "two occurrences". Counting them as a
                         * recurrence would say a fault came back overnight. They are marked, not
                         * dropped: the reader sees both rows and the reason they are not two events.
                         */
                        'same_visit'  => $gap !== null && $gap <= 1,
                        'record'      => $this->faultNotes($e['detail'], $system, $finding),
                    ];
                })->all();

                // What the fault actually came back from — same-visit continuations do not count.
                $returns = collect($occurrences)->filter(fn ($o) => $o['gap_days'] !== null && ! $o['same_visit']);

                return [
                    'finding'     => $finding,
                    'count'       => $ordered->count(),
                    // Rows minus the consecutive-day continuations: how many times the car actually
                    // went in for this. Printed next to the raw count when the two differ.
                    'visits'      => $ordered->count() - collect($occurrences)->where('same_visit', true)->count(),
                    'returns'     => $returns->count(),
                    'shortest_gap'=> $returns->min('gap_days'),
                    'longest_gap' => $returns->max('gap_days'),
                    'first'       => $dates->first(),
                    'last'        => $dates->last(),
                    'span_days'   => $dates->count() > 1
                        ? Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last()))
                        : null,
                    'garages'     => $ordered->pluck('garage')->reject(fn ($g) => $g === 'Not recorded')->unique()->values()->all(),
                    'worst'       => $this->worstSeverity($ordered),
                    'occurrences' => $occurrences,
                ];
            })
            ->sortByDesc(fn (array $f) => [$f['count'], $f['last']])
            ->values()
            ->all();
    }

    /**
     * THE RECORD ITSELF — the lines of the visit's note that actually concern THIS fault.
     *
     * A workshop note covers the whole visit: "Oil leakage from the valve cover area. Scratches on
     * the right-side rims. The right rear taillight is broken." Printed whole under a repeated engine
     * fault, the rim scratches and the taillight bury the one line that is the evidence, and the
     * reader stops reading. Printed as nothing, the claim "this came back" has no proof at all.
     *
     * So each line is classified with GarageRecommendationService — the platform's single sanctioned
     * reader of this text, the same one that decided the event belonged to this system in the first
     * place — and only the lines belonging to this system, or to a category the finding itself names,
     * are kept. "Engine Oil leak" names engine AND fluids, so the oil line survives on the engine
     * dashboard even though "oil leakage" alone classifies as fluids.
     *
     * When nothing matches, the whole note is returned with filtered=false rather than an empty
     * block: a note this reader cannot attribute is still the record, and hiding it would be worse
     * than showing it unsorted. `dropped` says how many lines were set aside, so the filtering is
     * never silent.
     */
    private function faultNotes(?string $detail, string $system, string $finding): array
    {
        $lines = collect(preg_split('/\r?\n|\s*\/\/\s*|\s+·\s+|(?<=\.)\s+/u', (string) $detail))
            ->map(fn ($l) => trim((string) $l, " \t\n\r\0\x0B\"'"))
            ->filter(fn ($l) => $l !== '' && $l !== 'No notes recorded')
            ->unique(fn ($l) => mb_strtolower($l))
            ->values();

        if ($lines->isEmpty()) {
            return ['lines' => [], 'filtered' => false, 'dropped' => 0];
        }

        $wanted  = array_unique(array_merge([$system], $this->categories->extractCategories($finding, null, null)));
        $matched = $lines->filter(
            fn ($l) => array_intersect($wanted, $this->categories->extractCategories($l, null, null)) !== []
        )->values();

        return $matched->isNotEmpty()
            ? ['lines' => $matched->all(), 'filtered' => true, 'dropped' => $lines->count() - $matched->count()]
            : ['lines' => $lines->all(), 'filtered' => false, 'dropped' => 0];
    }

    /** The most serious severity anyone gave this fault, or null if nobody rated it. */
    private function worstSeverity(Collection $group): ?string
    {
        $rank = array_flip(['routine', 'moderate', 'high', 'critical']);

        return $group
            ->pluck('severity')
            ->filter()
            ->sortByDesc(fn (string $s) => $rank[$s] ?? -1)
            ->first();
    }

    // ── What was replaced, and did it hold ──────────────────────────────────────────────────────

    /**
     * The parts and work billed against this system, each answered with the one question that
     * matters: was there another failure of this system AFTER it? A part followed by a further
     * failure is reported as such — that is a fact about the timeline, not a judgement of the garage.
     */
    private function repairs(Vehicle $vehicle, string $system, Collection $events, ?ReportDateRange $range = null): array
    {
        $range = $range ?: ReportDateRange::allHistory();

        $lines = MaintenanceLineItem::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category_key', $system)
            // A line is dated by its visit when it has one, and by its own columns when it does not —
            // so the filter has to ask the same two questions the reader below will.
            ->when($range->isActive(), fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('maintenance', fn ($m) => $range->applyToColumn($m, 'maintenances.out_date'))
                ->orWhere(fn ($x) => $x
                    ->whereDoesntHave('maintenance', fn ($m) => $m->whereNotNull('maintenances.out_date'))
                    ->where(fn ($y) => $range->applyToExpression(
                        $y, 'COALESCE(maintenance_line_items.installed_on, maintenance_line_items.created_at)'
                    )))))
            ->with('maintenance:id,out_date,garage,vendor_id', 'maintenance.vendor:id,name')
            ->get();

        return $lines
            ->map(function (MaintenanceLineItem $l) use ($events) {
                $date  = optional(optional($l->maintenance)->out_date) ?: $l->installed_on ?: $l->created_at;
                $date  = $date ? Carbon::parse($date)->toDateString() : null;
                $after = $date ? $events->filter(fn ($e) => $e['date'] > $date)->count() : null;

                return [
                    'date'        => $date,
                    'work'        => $l->description ?: ($l->finding_text ?: 'Line recorded without a description'),
                    // The invoice line's own wording is data; only the fallback is ours to translate.
                    'work_i18n'   => ($l->description || $l->finding_text) ? null : $this->tr('reportSystem.repairs.noDescription'),
                    'part_number' => $l->part_number,
                    'garage'      => optional(optional($l->maintenance)->vendor)->name ?? (optional($l->maintenance)->garage ?: 'Not recorded'),
                    'held'        => $after === null ? 'Date not recorded' : ($after === 0 ? 'No further failure since' : $after . ' further ' . Str::plural('failure', $after) . ' after this'),
                    'held_i18n'   => $after === null
                        ? $this->tr('reportSystem.repairs.noDate')
                        : ($after === 0
                            ? $this->tr('reportSystem.repairs.held')
                            : $this->tr('reportSystem.repairs.failedAfter', ['n' => $after], true)),
                    'held_ok'     => $after === 0,
                ];
            })
            // The exact inclusive boundary, asserted against the date the row is printed under —
            // the SQL above narrows, this decides.
            ->filter(fn (array $r) => $range->contains($r['date']))
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    // ── Header + Data Origin ────────────────────────────────────────────────────────────────────

    public function vehicleHeader(Vehicle $vehicle): array
    {
        return [
            'id'    => $vehicle->id,
            'label' => collect([
                trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')),
                $vehicle->color,
                $vehicle->year,
                $vehicle->plate_no,
            ])->filter(fn ($p) => $p !== null && $p !== '')->implode(' · '),
            'plate' => $vehicle->plate_no,
            'vin'   => $vehicle->vin,
        ];
    }

    /**
     * WHAT THIS CAR IS — the identity a reader needs before they can judge the history above it.
     *
     * Recorded columns only. Nothing here is inferred: a field the record does not carry is omitted
     * rather than filled with a guess or a dash, so a short brief means a thin record and says so by
     * being short. `kind` tells the frontend how to render the value (a distance, a date, a money
     * amount, a status chip) — the units and the labels live in the catalog, not here.
     *
     * The odometer is reported WITH the source that last wrote it. Several writers advance that
     * column and the highest reading wins, so a reading with no provenance is not checkable — see
     * [[odometer-source-race]].
     */
    public function vehicleBrief(Vehicle $vehicle): array
    {
        $spec = collect([
            $vehicle->cylinders ? $vehicle->cylinders . ' cyl' : null,
            $vehicle->horse_power ? $vehicle->horse_power . ' hp' : null,
            $vehicle->doors ? $vehicle->doors . '-door' : null,
            $vehicle->seats ? $vehicle->seats . ' seats' : null,
        ])->filter()->implode(' · ');

        $odo      = $vehicle->odometer;
        $lastSvc  = $vehicle->last_service_odometer;
        $interval = $vehicle->service_interval_km;
        $since    = ($odo !== null && $lastSvc !== null) ? max(0, $odo - $lastSvc) : null;
        $dueIn    = ($since !== null && $interval) ? $interval - $since : null;

        $rows = [
            ['key' => 'model',      'kind' => 'text', 'value' => trim(collect([$vehicle->make, $vehicle->model, $vehicle->year])->filter()->implode(' · '))],
            ['key' => 'colour',     'kind' => 'text', 'value' => $vehicle->color],
            ['key' => 'plate',      'kind' => 'text', 'value' => trim(collect([$vehicle->plate_code, $vehicle->plate_no])->filter()->implode(' · '))],
            ['key' => 'vin',        'kind' => 'mono', 'value' => $vehicle->vin],
            ['key' => 'serial',     'kind' => 'mono', 'value' => $vehicle->car_serial],
            ['key' => 'class',      'kind' => 'text', 'value' => $vehicle->sheet_category],
            ['key' => 'spec',       'kind' => 'text', 'value' => $spec ?: null],
            ['key' => 'gearbox',    'kind' => 'tr',   'value' => $vehicle->auto_gear === null ? null : ($vehicle->auto_gear ? 'reportSystem.brief.automatic' : 'reportSystem.brief.manual')],
            ['key' => 'odometer',   'kind' => 'km',   'value' => $odo, 'note' => $vehicle->odometer_source],
            ['key' => 'lastService','kind' => 'km',   'value' => $lastSvc],
            ['key' => 'sinceService','kind' => 'km',  'value' => $since],
            ['key' => 'serviceEvery','kind' => 'km',  'value' => $interval],
            // Negative means the interval is already passed — the frontend colours it, but the number
            // is stated either way rather than being clamped to zero.
            ['key' => 'serviceDueIn','kind' => 'km',  'value' => $dueIn],
            ['key' => 'purchased',  'kind' => 'date', 'value' => optional($vehicle->purchase_date)->toDateString()],
            ['key' => 'price',      'kind' => 'money','value' => $vehicle->purchase_price],
            ['key' => 'battery',    'kind' => 'date', 'value' => optional($vehicle->battery_last_changed)->toDateString()],
            ['key' => 'location',   'kind' => 'text', 'value' => $vehicle->location],
            ['key' => 'operational','kind' => 'chip', 'value' => $vehicle->operational_status],
            ['key' => 'condition',  'kind' => 'chip', 'value' => $vehicle->condition_grade],
        ];

        return array_values(array_filter(
            $rows,
            fn (array $r) => $r['value'] !== null && $r['value'] !== '',
        ));
    }

    private function provenance(Vehicle $vehicle, string $system, Collection $events, ?ReportDateRange $range = null): array
    {
        $range   = $range ?: ReportDateRange::allHistory();
        $tickets = $events->where('source', 'ticket')->count();
        $sheet   = $events->where('source', 'workshop log')->count();

        /*
         * The window states the SELECTED period first and the records found inside it second. Those
         * are different facts — a filter covering May to July whose records run 04 Jul to 28 Jul is
         * reporting a mostly empty period, and collapsing the two would hide that.
         */
        $selected = $range->isActive()
            ? 'Selected period ' . ($range->from ?: 'the earliest record') . ' to ' . ($range->to ?: 'today') . ' — '
            : 'All history — ';

        return [
            'source'  => 'maintenance_tasks (category_key = ' . $system . ') + workshop-log rows whose finding resolves to that category',
            'window'  => $events->isEmpty()
                ? ($range->isActive() ? $selected . 'no events recorded in it' : 'No events on record')
                : $selected . 'records run ' . $events->first()['date'] . ' to ' . $events->last()['date'],
            'split'   => $tickets . ' from per-fault tickets · ' . $sheet . ' from the workshop log',
            'grouping'=> 'One visit is one event: sheet rows identical on date, garage and finding are the stages of a single trip (OUT / Follow up / IN) and are collapsed into one.',
            'omitted' => 'Retired tickets are excluded. Entries whose finding matches no catalog keyword are not attributed to any system, so they appear on no dashboard.',
            'derived' => 'Only the risk score and health index are derived, and every component is printed with its input count.',
            'i18n'    => [
                // The table and column names stay in English on purpose: they are identifiers a reader
                // would grep the schema for, not prose. Everything around them translates.
                'source'  => $this->tr('reportSystem.provenance.source', ['system' => $system]),
                'window'  => match (true) {
                    $events->isEmpty() && ! $range->isActive() => $this->tr('reportSystem.provenance.windowNone'),
                    $events->isEmpty()                         => $this->tr('reportSystem.provenance.windowPeriodNone', [
                        'from' => $range->from ?: '—', 'to' => $range->to ?: '—',
                    ]),
                    $range->isActive()                         => $this->tr('reportSystem.provenance.windowPeriod', [
                        'from'   => $range->from ?: $events->first()['date'],
                        'to'     => $range->to ?: $events->last()['date'],
                        'first'  => $events->first()['date'],
                        'last'   => $events->last()['date'],
                    ]),
                    default => $this->tr('reportSystem.provenance.window', [
                        'from' => $events->first()['date'],
                        'to'   => $events->last()['date'],
                    ]),
                },
                'split'   => $this->tr('reportSystem.provenance.split', ['tickets' => $tickets, 'sheet' => $sheet]),
                'grouping'=> $this->tr('reportSystem.provenance.grouping'),
                'omitted' => $this->tr('reportSystem.provenance.omitted'),
                'derived' => $this->tr('reportSystem.provenance.derived'),
            ],
        ];
    }
}
