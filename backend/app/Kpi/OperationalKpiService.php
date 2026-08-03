<?php

namespace App\Kpi;

use Illuminate\Support\Facades\DB;

/**
 * The operational scoreboard — what the platform is supposed to move.
 *
 * These are deliberately not technical metrics. Nothing here measures response times or cache hit
 * rates; every number is one an operations manager would recognise as their own problem. A feature
 * that cannot be argued to move one of them is a feature that needs justifying.
 *
 * HONESTY IS THE DESIGN CONSTRAINT. Roughly half of these cannot be computed today, and the ones
 * that can are computed over wildly different samples: comeback rate rests on 31,782 historical
 * repairs, while anything derived from workflow timestamps rests on about two dozen, because the
 * workflow engine is newer than the corpus it inherited. Reporting both as plain percentages side by
 * side would be the most misleading thing this service could do — so each carries its sample, and
 * the ones that are not ready say so.
 *
 * THE BASELINE IS THE POINT. Snapshotting these now is what makes every later change arguable: the
 * one measurement that cannot be taken retroactively is the one from before the change.
 *
 * ── RETIRED TICKETS ARE COUNTED HERE, DELIBERATELY ───────────────────────────────────────────────
 * `maintenances` is soft-deleted, and every query in this class is raw SQL, so none of them inherit
 * the model's scope: they all see soft-deleted rows and are MEANT to. A retired ticket is a repair
 * that really happened — the car really was off the road, the garage really did the work — and
 * dropping it would silently rewrite history each time somebody tidied the board. Worse, the
 * denominator would move without the numerator, so a comeback rate could improve simply because a
 * ticket was deleted.
 *
 * That is the opposite of the rule for operational surfaces (a retired ticket must vanish from the
 * board at once). The split is the point: LIVE reads ask "what is true now", these ask "what happened".
 * If a metric here ever needs the live-only population instead, it must say so and filter explicitly.
 */
class OperationalKpiService
{
    /** Below this, a rate is an anecdote. */
    private const MIN_SAMPLE = 30;

    /**
     * The window that defines a comeback.
     *
     * Declared here rather than imported, because this service must not depend on the
     * maintenance-owned repair-intelligence layer — the dependency runs one way. If a shared
     * definition is ever needed, it belongs in config so both sides read one value; duplicating the
     * number across two owners with no link between them is the failure mode to avoid.
     */
    private const COMEBACK_WINDOW_DAYS = 90;

    /** @return array<int,Kpi> */
    public function all(): array
    {
        return [
            // --- repair quality -----------------------------------------------------------------
            $this->firstTimeFixRate(),
            $this->comebackRate(),
            $this->repeatFailureRate(),

            // --- speed --------------------------------------------------------------------------
            $this->workshopTurnaround(),
            $this->diagnosisTime(),

            // --- data quality -------------------------------------------------------------------
            $this->rootCauseCompleteness(),
            $this->repairActionCompleteness(),
            $this->outcomeCompleteness(),
            $this->garageAttribution(),

            // --- capture friction ---------------------------------------------------------------
            $this->averageCaptureTime(),
            $this->optionalFieldUsage(),
            $this->captureCorrectionRate(),
            $this->captureAbandonmentRate(),
            $this->captureDropOffStep(),
            $this->independentVerificationRate(),
        ];
    }

    // =============================================================================================
    // Repair quality
    // =============================================================================================

    /**
     * The share of repairs where the same fault did NOT return within the quality window.
     *
     * EXPOSURE IS EXCLUDED, and this is what makes the number mean anything. Body and rim damage
     * recur constantly because customers keep scraping cars, not because repairs fail; counting them
     * would push the rate down for reasons no workshop can influence, and the metric would be
     * ignored within a month. The same exclusion is what `maintenance_signatures.is_exposure` exists for.
     *
     * This is a PROXY for first-time fix, not the real thing: the corpus records what broke and who
     * fixed it, never what was done, so "fixed correctly on the first visit" is measured as "did not
     * come back". Those differ when a car is sold, written off, or simply not driven — a limitation
     * worth remembering before this number is used to grade anyone.
     */
    private function firstTimeFixRate(): Kpi
    {
        $r = $this->recurrenceStats();

        if ($r->n < self::MIN_SAMPLE) {
            return Kpi::insufficient('first_time_fix_rate', 'First-time fix rate', (int) $r->n, self::MIN_SAMPLE);
        }

        return Kpi::measured(
            'first_time_fix_rate',
            'First-time fix rate',
            100 - ($r->returned / $r->n * 100),
            'percent',
            (int) $r->n,
            Kpi::HIGHER_BETTER,
            [
                'window_days' => self::COMEBACK_WINDOW_DAYS,
                'method'      => 'proxy: same fault signature did not recur on the same vehicle within the window',
                'excludes'    => 'exposure damage (body, rim) — customer-caused, not repair quality',
            ],
        );
    }

    private function comebackRate(): Kpi
    {
        $r = $this->recurrenceStats();

        if ($r->n < self::MIN_SAMPLE) {
            return Kpi::insufficient('comeback_rate', 'Comeback rate', (int) $r->n, self::MIN_SAMPLE, 'percent', Kpi::LOWER_BETTER);
        }

        return Kpi::measured(
            'comeback_rate',
            'Comeback rate',
            $r->returned / $r->n * 100,
            'percent',
            (int) $r->n,
            Kpi::LOWER_BETTER,
            ['window_days' => self::COMEBACK_WINDOW_DAYS],
        );
    }

    /**
     * Vehicles that keep failing on the same fault — the chronic tail.
     *
     * REPORTED AS NOT MEASURABLE, AND THAT IS THE CORRECT ANSWER TODAY. The query runs and returns
     * 97.8%, which is worse than useless: it looks alarming and discriminates nothing.
     *
     * The cause is granularity, not logic. `maintenance_signatures` classifies 49,473 events into 22
     * coarse buckets (BODY, ELECTRICAL, STEERING…) across 233 vehicles — roughly 212 rows per car.
     * At that resolution almost every vehicle has three or more of ANY bucket, so the metric measures
     * "has visited a workshop repeatedly", which is true of the whole fleet. Narrowing to twelve
     * months only moves it to 96.9%.
     *
     * A real chronic-fault rate needs fault-level granularity — the 64-entry fault catalogue rather
     * than 22 buckets — which today exists only on `maintenance_tasks` (39 rows). So this is blocked
     * on the same thing as repair effectiveness: structured capture at fault level.
     *
     * Shipping the computable-but-meaningless version would have been the easy choice and the wrong
     * one. A KPI nobody can act on trains people to ignore the dashboard.
     */
    private function repeatFailureRate(): Kpi
    {
        $faultLevel = DB::table('maintenance_tasks')->whereNotNull('fault_catalog_id')->count();

        if ($faultLevel < self::MIN_SAMPLE) {
            return Kpi::unavailable(
                'repeat_failure_rate',
                'Vehicles with a chronic repeat fault',
                "Not measurable at the resolution available. The signature corpus has only 22 coarse categories "
                ."across 233 vehicles, so 'same fault 3+ times' is true of ~98% of the fleet and discriminates "
                ."nothing. Needs fault-level classification, which currently covers {$faultLevel} record(s).",
                'percent',
                Kpi::LOWER_BETTER,
            );
        }

        $row = DB::selectOne('
            SELECT COUNT(*) AS vehicles,
                   SUM(CASE WHEN mx >= 3 THEN 1 ELSE 0 END) AS chronic
            FROM (
                SELECT vehicle_id, MAX(c) AS mx
                FROM (
                    SELECT vehicle_id, fault_catalog_id, COUNT(*) AS c
                    FROM maintenance_tasks
                    WHERE vehicle_id IS NOT NULL
                      AND fault_catalog_id IS NOT NULL
                      -- "Chronic repeat FAULT" — say so explicitly rather than relying on the
                      -- exactly-one-catalog invariant to keep damage and service out implicitly. The
                      -- filter above already does that structurally today; this makes the intent
                      -- readable and survives any future row that slips past the guard.
                      AND kind = ?
                    GROUP BY vehicle_id, fault_catalog_id
                ) a
                GROUP BY vehicle_id
            ) b', [\App\Models\MaintenanceTask::KIND_FAULT]);

        $vehicles = (int) ($row->vehicles ?? 0);

        if ($vehicles < self::MIN_SAMPLE) {
            return Kpi::insufficient('repeat_failure_rate', 'Vehicles with a chronic repeat fault', $vehicles, self::MIN_SAMPLE, 'percent', Kpi::LOWER_BETTER);
        }

        return Kpi::measured(
            'repeat_failure_rate',
            'Vehicles with a chronic repeat fault',
            (int) $row->chronic / $vehicles * 100,
            'percent',
            $vehicles,
            Kpi::LOWER_BETTER,
            ['definition' => 'the same catalogued fault recorded 3+ times on one vehicle'],
        );
    }

    // =============================================================================================
    // Speed
    // =============================================================================================

    /**
     * Days between the car leaving for the workshop and coming back.
     *
     * Computed from the sheet-era `out_date` / `actual_in_date` rather than the workflow timestamps,
     * because those exist on about two dozen tickets while these cover thousands. Negative and
     * absurd spans are dropped rather than winsorised: they are data-entry errors, not long repairs,
     * and averaging them in would move the mean without telling anyone anything.
     */
    private function workshopTurnaround(): Kpi
    {
        $row = DB::selectOne('
            SELECT COUNT(*) AS n, AVG(DATEDIFF(actual_in_date, out_date)) AS mean
            FROM maintenances
            WHERE out_date IS NOT NULL AND actual_in_date IS NOT NULL
              AND DATEDIFF(actual_in_date, out_date) BETWEEN 0 AND 120');

        $n = (int) ($row->n ?? 0);

        if ($n < self::MIN_SAMPLE) {
            return Kpi::insufficient('workshop_turnaround_days', 'Workshop turnaround', $n, self::MIN_SAMPLE, 'days', Kpi::LOWER_BETTER);
        }

        // The median matters more than the mean here — turnaround is heavily skewed by a few very
        // long jobs, and a manager plans against the typical case.
        $median = DB::selectOne('
            SELECT d FROM (
                SELECT DATEDIFF(actual_in_date, out_date) AS d
                FROM maintenances
                WHERE out_date IS NOT NULL AND actual_in_date IS NOT NULL
                  AND DATEDIFF(actual_in_date, out_date) BETWEEN 0 AND 120
                ORDER BY d
                LIMIT 1 OFFSET ?
            ) x LIMIT 1', [intdiv($n, 2)]);

        return Kpi::measured(
            'workshop_turnaround_days',
            'Workshop turnaround',
            (float) $row->mean,
            'days',
            $n,
            Kpi::LOWER_BETTER,
            [
                'median' => (int) ($median->d ?? 0),
                'source' => 'out_date → actual_in_date (workflow timestamps cover too few tickets)',
            ],
        );
    }

    /** Hours from starting the diagnostic to filing the report. */
    private function diagnosisTime(): Kpi
    {
        $row = DB::selectOne('
            SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(MINUTE, test_started_at, inspected_at)) AS mins
            FROM maintenances
            WHERE test_started_at IS NOT NULL AND inspected_at IS NOT NULL
              AND inspected_at >= test_started_at
              AND TIMESTAMPDIFF(HOUR, test_started_at, inspected_at) <= 72');

        $n = (int) ($row->n ?? 0);

        if ($n < self::MIN_SAMPLE) {
            return Kpi::insufficient('diagnosis_time_hours', 'Average diagnosis time', $n, self::MIN_SAMPLE, 'hours', Kpi::LOWER_BETTER);
        }

        return Kpi::measured('diagnosis_time_hours', 'Average diagnosis time', ((float) $row->mins) / 60, 'hours', $n, Kpi::LOWER_BETTER);
    }

    // =============================================================================================
    // Data quality — what the intelligence layer actually has to work with
    // =============================================================================================

    /** Closed tickets carrying a structured root cause. The gate on every causal question. */
    private function rootCauseCompleteness(): Kpi
    {
        $total = DB::table('maintenance_tasks')->count();

        if ($total === 0) {
            return Kpi::unavailable('root_cause_completeness', 'Faults with a recorded root cause', 'No structured faults exist yet.');
        }

        $withCause = DB::table('maintenance_tasks')->whereNotNull('root_cause_id')->count();

        return Kpi::measured(
            'root_cause_completeness',
            'Faults with a recorded root cause',
            $withCause / $total * 100,
            'percent',
            $total,
            Kpi::HIGHER_BETTER,
        );
    }

    /**
     * The dataset gap, stated as a number.
     *
     * Kept in the list precisely BECAUSE it is zero: it is the single measurement that explains why
     * repair effectiveness cannot be computed, and dropping it would hide the reason behind an
     * absence.
     */
    private function repairActionCompleteness(): Kpi
    {
        if (! DB::getSchemaBuilder()->hasTable('maintenance_task_actions')) {
            return Kpi::unavailable(
                'repair_action_completeness',
                'Repairs with structured actions recorded',
                'No capture step exists yet — nothing records WHAT WAS DONE, only what was wrong and who fixed it. '
                .'This is the blocker on repair effectiveness, supplier quality and technician accuracy.',
            );
        }

        $total = DB::table('maintenance_tasks')->whereIn('status', ['completed'])->count();

        if ($total === 0) {
            return Kpi::unavailable('repair_action_completeness', 'Repairs with structured actions recorded', 'No completed faults yet.');
        }

        $withActions = DB::table('maintenance_task_actions')->distinct()->count('maintenance_task_id');

        return Kpi::measured('repair_action_completeness', 'Repairs with structured actions recorded', $withActions / $total * 100, 'percent', $total);
    }

    /** Closed repairs carrying a technician's claimed outcome. */
    private function outcomeCompleteness(): Kpi
    {
        if (! DB::getSchemaBuilder()->hasTable('domain_events')) {
            return Kpi::unavailable('outcome_completeness', 'Repairs with a claimed outcome', 'The event log does not exist.');
        }

        $claims = DB::table('domain_events')
            ->where('event_type', 'OutcomeClaimed')
            // JSON_UNQUOTE rather than CAST('null' AS JSON): MariaDB rejects casting to JSON, and
            // this project runs MariaDB locally against MySQL 8 in production. Unquoting yields the
            // literal string 'null' for a JSON null on BOTH engines, so this comparison is portable.
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.claimed_outcome')) IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.claimed_outcome')) <> 'null'")
            ->count();

        $released = DB::table('domain_events')->where('event_type', 'VehicleReleased')->count();

        if ($released === 0) {
            return Kpi::unavailable(
                'outcome_completeness',
                'Repairs with a claimed outcome',
                'No releases recorded yet. Closing a ticket currently records the outcome as unknown by design — '
                .'"closed" is a workflow state, not a repair result.',
            );
        }

        return Kpi::measured('outcome_completeness', 'Repairs with a claimed outcome', $claims / $released * 100, 'percent', $released);
    }

    /**
     * The pilot's actual experiment: can the two halves be joined?
     *
     * A repair the workshop recorded AND an inspector independently confirmed is the unit of
     * evidence everything downstream depends on. Measuring the join rate directly is how we find out
     * whether the split workflow works in practice, rather than assuming it because the code allows
     * it.
     */
    private function independentVerificationRate(): Kpi
    {
        $captured = DB::table('maintenance_tasks')
            ->where(fn ($q) => $q->whereNotNull('claimed_outcome')->orWhere('no_fault_found', true))
            ->count();

        if ($captured === 0) {
            return Kpi::unavailable(
                'independent_verification_rate',
                'Repairs independently verified',
                'No repairs captured yet. This is the pilot\'s core measurement: workshop records the '
                .'repair, a DIFFERENT person verifies it, and both join into one outcome.',
            );
        }

        // The same floor as every other rate here. A pilot metric is at its most dangerous in week
        // one, when "100%" on a single repair reads as a solved problem.
        if ($captured < self::MIN_SAMPLE) {
            return Kpi::insufficient('independent_verification_rate', 'Repairs independently verified', $captured, self::MIN_SAMPLE);
        }

        $verified = DB::table('maintenance_tasks')
            ->whereNotNull('verified_by')
            ->whereNotNull('verification_result')
            // The separation rule, re-checked at read time. If these are ever equal the data is
            // wrong regardless of what the service enforced at write time.
            ->whereColumn('verified_by', '!=', 'claimed_outcome_by')
            ->count();

        return Kpi::measured(
            'independent_verification_rate',
            'Repairs independently verified',
            $verified / $captured * 100,
            'percent',
            $captured,
            Kpi::HIGHER_BETTER,
            ['definition' => 'verified by someone other than the person who recorded the repair'],
        );
    }

    /** Whether a repair can be attributed to a workshop at all — the gate on supplier quality. */
    private function garageAttribution(): Kpi
    {
        $total = DB::table('maintenances')->count();
        $withVendor = DB::table('maintenances')->whereNotNull('vendor_id')->count();

        return Kpi::measured('garage_attribution', 'Repairs attributable to a garage', $withVendor / $total * 100, 'percent', $total);
    }

    // =============================================================================================
    // Capture friction — is the product usable
    // =============================================================================================

    private function averageCaptureTime(): Kpi
    {
        return $this->frictionKpi(
            'average_capture_time',
            'Average capture time per step',
            'seconds',
            Kpi::LOWER_BETTER,
            fn (int $n) => Kpi::measured(
                'average_capture_time',
                'Average capture time per step',
                ((float) DB::table('capture_friction')->whereNotNull('duration_ms')->avg('duration_ms')) / 1000,
                'seconds',
                $n,
                Kpi::LOWER_BETTER,
            ),
        );
    }

    /**
     * ABANDONMENT IS NOT MEASURABLE FROM SUCCESS RECORDS, and pretending otherwise produced a
     * metric reading 100%.
     *
     * A friction row is written only when a capture SUCCEEDS, so an abandoned attempt leaves no
     * trace — the population that would prove abandonment is exactly the one missing from the table.
     * The first version computed "filled < offered", which counts skipping an OPTIONAL field as
     * abandoning the form, and therefore reported 100% for a step every user completed.
     *
     * Measuring this needs the client to report STARTS as well as completions. Until it does, the
     * honest answer is that we do not know.
     */
    /**
     * Now genuinely measurable: a session is opened when the form opens and closed when it resolves,
     * so an abandoned attempt leaves a row instead of leaving nothing.
     *
     * Rows still `started` are counted as abandoned — that is a form opened and never resolved,
     * which is a closed tab or a closed browser, and is exactly the behaviour this is meant to catch.
     * The earlier version could only see people who finished and therefore reported 100%.
     */
    private function captureAbandonmentRate(): Kpi
    {
        return $this->frictionKpi(
            'capture_abandonment_rate',
            'Capture abandonment rate',
            'percent',
            Kpi::LOWER_BETTER,
            function (int $n) {
                $abandoned = DB::table('capture_friction')
                    ->whereIn('status', ['abandoned', 'started'])
                    ->count();

                return Kpi::measured(
                    'capture_abandonment_rate',
                    'Capture abandonment rate',
                    $abandoned / $n * 100,
                    'percent',
                    $n,
                    Kpi::LOWER_BETTER,
                    ['definition' => 'sessions opened that never completed (includes tabs closed mid-form)'],
                );
            },
        );
    }

    /**
     * Where people stop. One of the owner's five feedback-loop questions, and unanswerable until
     * sessions recorded a last step.
     */
    private function captureDropOffStep(): Kpi
    {
        return $this->frictionKpi(
            'capture_dropoff_step',
            'Most common step to leave at',
            'count',
            Kpi::NEUTRAL,
            function (int $n) {
                $row = DB::table('capture_friction')
                    ->where('status', 'abandoned')
                    ->whereNotNull('last_step')
                    ->selectRaw('last_step, COUNT(*) AS c')
                    ->groupBy('last_step')
                    ->orderByDesc('c')
                    ->first();

                return $row === null
                    ? Kpi::unavailable('capture_dropoff_step', 'Most common step to leave at', 'No abandoned sessions recorded a step.', 'count', Kpi::NEUTRAL)
                    : Kpi::measured('capture_dropoff_step', 'Most common step to leave at', (float) $row->last_step, 'count', (int) $row->c, Kpi::NEUTRAL, [
                        'step_1' => 'problem', 'step_2' => 'what was done', 'step_3' => 'did it work',
                    ]);
            },
        );
    }

    /** What the friction data DOES support: how much of the optional depth people actually use. */
    private function optionalFieldUsage(): Kpi
    {
        return $this->frictionKpi(
            'optional_field_usage',
            'Optional fields completed',
            'percent',
            Kpi::NEUTRAL,
            function (int $n) {
                $row = DB::table('capture_friction')
                    ->selectRaw('SUM(fields_filled) AS filled, SUM(fields_offered) AS offered')
                    ->first();

                $offered = (int) ($row->offered ?? 0);

                return $offered === 0
                    ? Kpi::unavailable('optional_field_usage', 'Optional fields completed', 'No fields recorded as offered.', 'percent', Kpi::NEUTRAL)
                    : Kpi::measured('optional_field_usage', 'Optional fields completed', (int) $row->filled / $offered * 100, 'percent', $n, Kpi::NEUTRAL);
            },
        );
    }

    /** How often a capture had to be redone — friction that already cost somebody twice. */
    private function captureCorrectionRate(): Kpi
    {
        return $this->frictionKpi(
            'capture_correction_rate',
            'Captures that were corrections',
            'percent',
            Kpi::LOWER_BETTER,
            function (int $n) {
                $corrected = DB::table('capture_friction')->where('was_corrected', true)->count();

                return Kpi::measured('capture_correction_rate', 'Captures that were corrections', $corrected / $n * 100, 'percent', $n, Kpi::LOWER_BETTER);
            },
        );
    }

    /**
     * Both friction metrics fail the same way and must say so identically: the table exists, the
     * frontend does not post to it, and the honest reading is "not measured yet" — never 0%, which
     * would read as a product with no friction at all.
     */
    private function frictionKpi(string $key, string $label, string $unit, string $direction, callable $compute): Kpi
    {
        if (! DB::getSchemaBuilder()->hasTable('capture_friction')) {
            return Kpi::unavailable($key, $label, 'Friction telemetry table does not exist.', $unit, $direction);
        }

        $n = DB::table('capture_friction')->count();

        if ($n === 0) {
            return Kpi::unavailable(
                $key,
                $label,
                'No telemetry recorded. The table and shape exist but the frontend does not post to it yet — '
                .'this reads as "not measured", never as zero friction.',
                $unit,
                $direction,
            );
        }

        // The same sample floor as everything else. A rollout metric is at its most dangerous in its
        // first week, when a handful of rows can be read as a trend and drive a redesign.
        if ($n < self::MIN_SAMPLE) {
            return Kpi::insufficient($key, $label, $n, self::MIN_SAMPLE, $unit, $direction);
        }

        return $compute($n);
    }

    // =============================================================================================

    /** Shared recurrence numbers — one query behind both the fix rate and the comeback rate. */
    private function recurrenceStats(): object
    {
        static $cached = null;

        return $cached ??= DB::selectOne('
            SELECT COUNT(*) AS n,
                   SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM maintenance_signatures b
                        WHERE b.vehicle_id = a.vehicle_id
                          AND b.signature  = a.signature
                          AND b.occurred_at >  a.occurred_at
                          AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                   ) THEN 1 ELSE 0 END) AS returned
            FROM maintenance_signatures a
            WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0',
            [self::COMEBACK_WINDOW_DAYS]);
    }
}
