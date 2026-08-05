<?php

/*
|--------------------------------------------------------------------------
| GOVERNED METRIC — Recurrence
|--------------------------------------------------------------------------
|
| THIS FILE IS THE BUSINESS DEFINITION. Not a tuning file — the definition.
|
| Before this contract existed the platform carried SIX implementations of the
| question "did the same fault come back?", and they disagreed: fleet comeback
| read 40.62%, 40.96% or 46.51% depending on which page you opened, over sample
| sizes ranging from 10,595 to 33,026. Each implementation was individually
| defensible. Together they were indefensible, because a user asking one
| question got a different answer per surface.
|
| So recurrence is now GOVERNED:
|
|   · one canonical dataset      fault_recurrence_pairs
|   · one reader                 App\Intelligence\Recurrence\RecurrenceRepository
|   · one definition             this file
|   · one façade for scalars     App\Intelligence\MetricRegistry
|
| RULES OF CHANGE
|   1. Any change to a value below is a VERSION BUMP plus a `change_history`
|      entry. There is no such thing as a quiet tuning here: every number in
|      this file moves a number somebody makes a decision on.
|   2. RecurrenceRepository reads this file. It never hardcodes a window, a
|      horizon or a floor.
|   3. RecurrenceContractTest asserts this file and the repository agree, so the
|      documentation cannot drift away from the implementation.
|   4. Every kpi_snapshots row is stamped with `version`, so a historical
|      baseline always says which definition produced it.
|
| Full specification: docs/Metric-Specification-Recurrence.md
| Convergence plan:   docs/Recurrence-Convergence-Implementation-Plan.md
| Audit + evidence:   docs/Recurrence-Metric-Convergence.md
|
*/

return [

    'version'        => '2.1.0',
    'effective_date' => '2026-08-04',
    'owner'          => 'Fleet Intelligence',
    'supersedes'     => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Business definition
    |--------------------------------------------------------------------------
    | The sentence an operations manager would recognise as their own problem.
    | If the SQL below ever stops implementing THIS sentence, the SQL is wrong.
    */
    'business_definition' =>
        'Of the repairs a garage completed that have since had a full observation window '
        . 'in which to fail, the share where the same fault was recorded again on the same '
        . 'vehicle inside that window.',

    /*
    |--------------------------------------------------------------------------
    | Technical definition
    |--------------------------------------------------------------------------
    */
    'canonical_dataset' => 'fault_recurrence_pairs',

    // One row = one real fault event. NOT one label row: maintenance_signatures
    // holds 2–8 rows per fault-day (a derived label AND a human one, several
    // matched terms, several tickets sharing a date).
    'grain' => ['vehicle_id', 'signature', 'occurred_at'],

    'deduplication' => [
        'enabled'          => true,
        'key'              => ['vehicle_id', 'signature', 'occurred_at'],
        'vendor_tiebreak'  => 'min_maintenance_id',
        'audit_column'     => 'source_row_count',
        'enforced_by'      => 'unique index frp_event_unique',
        // Measured 2026-08-04: 33,026 raw label rows collapse to 12,608 events.
        'observed_factor'  => 2.62,
        'rationale'        =>
            'One fault on one car on one day is one repair. Counting label rows counts a single '
            . 'repair up to eight times, and the duplication is NOT neutral: an event that did not '
            . 'recur contributes up to eight "held" rows, diluting the rate downward. Correcting it '
            . 'moves the fleet figure from 40.62% to 45.84%.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Observation horizon — the right-censoring correction
    |--------------------------------------------------------------------------
    | A repair done last week cannot have come back within 90 days yet. Counting
    | it as one that HELD is a free pass that flatters every garage, and flatters
    | the busiest ones most because they have the most recent work.
    |
    | corpus_max, not CURDATE(): the corpus ends before today (signatures are
    | rebuilt nightly from a sheet that lags), and anchoring on the clock
    | silently discards several hundred fully-observed rows.
    */
    'observation_horizon' => [
        'mode'      => 'corpus_max',        // corpus_max | none
        'column'    => 'days_observed',
        'predicate' => 'days_observed >= window_days',
        'rationale' =>
            'Right-censoring. Excluding repairs too recent to have failed removes a bias that would '
            . 'otherwise grow every quarter the corpus does.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Windows
    |--------------------------------------------------------------------------
    | 90 days is THE quality window and the only one a garage is judged on.
    | 30 and 60 are reported alongside it because "12 of 90 came back within a
    | month" is a sentence somebody can act on and "13.3%" is not.
    |
    | NOTE: config/features.intelligence.comeback.window_days = 14 is a DIFFERENT
    | QUESTION (is this car bouncing right now — an operator alert) and is being
    | renamed in its own PR so that "comeback" has exactly one meaning here.
    */
    'window_days'           => 90,
    'reported_windows_days' => [30, 60, 90],

    /*
    |--------------------------------------------------------------------------
    | Required filters
    |--------------------------------------------------------------------------
    */
    'filters' => [
        // Exposure = the customer scraped the car. It recurs constantly and no
        // workshop can influence it; grading a body shop on it would push its
        // score down for reasons it does not control. Derived from the
        // classifier's own list, never declared here, so adding an exposure
        // signature cannot silently start grading it.
        'exclude_exposure' => true,
        'exposure_source'  => \App\Services\Knowledge\RepairSignatureClassifier::class . '::EXPOSURE_SIGNATURES',

        // Scheduled work is not a failure. An oil change recurring every 90 days is
        // the service working, and counting it as "the fault came back" charged
        // 1,073 fully-observed services to garages as repair failures — at 47.72%,
        // slightly ABOVE the 46.38% real faults recur at, so the pollution pushed
        // every garage's rate UP rather than averaging out.
        //
        // Same derivation discipline as exposure: the list lives with the
        // classifier, never here, so adding a service signature cannot silently
        // start or stop grading it.
        //
        // The rows are NOT deleted from the corpus — they carry kind='service' and
        // stay readable, because "we excluded 1,073 services" has to be auditable
        // and the Services tab on the evidence drawer reads exactly those rows.
        // Excluding by filter rather than by deletion is what keeps both true.
        'exclude_services' => true,
        'service_source'   => \App\Services\Knowledge\RepairSignatureClassifier::class . '::SERVICE_SIGNATURES',
        'kind_column'      => 'kind',

        'require_vehicle'  => true,
        'require_date'     => true,

        // HISTORICAL, deliberately. A retired ticket is a repair that really
        // happened — the car really was off the road. Dropping it would let
        // history rewrite itself whenever somebody tidied the board, and would
        // move a denominator without its numerator.
        // Matches OperationalKpiService's documented policy.
        'ticket_scope'     => 'historical',
    ],

    /*
    |--------------------------------------------------------------------------
    | Numerator / denominator
    |--------------------------------------------------------------------------
    */
    'numerator'   => 'COUNT(*) WHERE next_occurred_at IS NOT NULL AND days_to_return <= window_days',
    'denominator' => 'COUNT(*) over fully-observed events',

    /*
    |--------------------------------------------------------------------------
    | Sample thresholds
    |--------------------------------------------------------------------------
    | Below these a rate is an anecdote and the surface says "not enough
    | repairs" — never a percentage, never a zero.
    |
    | ⚠ These floors are stated in REAL repairs. Before deduplication the
    | garage floor of 30 was enforcing roughly 6, which is why 63 garages
    | carried a score and only 36 clear the honest bar. Do NOT lower a floor to
    | restore the old count: that would re-import the bug as a setting.
    */
    'min_sample' => [
        'fleet'         => 30,
        'garage'        => 30,
        'garage_domain' => 20,
        'signature'     => 30,
        'vehicle'       => 5,
        'duration'      => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coverage
    |--------------------------------------------------------------------------
    | Sample size answers "is this enough?". Coverage answers "enough OF WHAT?".
    | Every Kpi built from this metric carries both.
    */
    'coverage' => [
        'covered_definition' => 'fully-observed events (days_observed >= window_days)',
        'total_definition'   => 'all deduplicated fault events',
        'partial_below_pct'  => 90.0,   // below this, Kpi::confidence = partial
    ],

    /*
    |--------------------------------------------------------------------------
    | Case-mix adjustment — JUDGEMENT, not measurement
    |--------------------------------------------------------------------------
    | Declared here so the rule is governed, but APPLIED in the domain service
    | (GarageScorecardService), because it answers "what does it mean?" rather
    | than "what happened?". The repository never applies it.
    |
    | Method: indirect standardisation. expected = Σ(n_domain × fleet_rate_domain),
    | then the garage's ratio actual/expected. Structurally an SMR.
    |
    | ⚠ DOCUMENTED LIMIT — this is rigorous for GARAGE vs FLEET. Comparing two
    | garages' ratios TO EACH OTHER is only approximate when their case-mixes
    | differ materially (the classic SMR limitation). Any surface that compares
    | garages must display each garage's domain mix beside its ratio.
    */
    'case_mix' => [
        'enabled'      => true,
        'method'       => 'indirect_standardisation',
        'applied_in'   => \App\Services\Garage\GarageScorecardService::class,
        'expected'     => 'SUM(n_domain * fleet_comeback_rate_domain) / SUM(n_domain)',
        'valid_for'    => 'garage vs fleet',
        'approximate_for' => 'garage vs garage when case-mixes differ materially',
        'rationale'    =>
            'A garage\'s raw rate is mostly a description of its work mix. Oil services recur by '
            . 'schedule; a shop that does nothing else looks unreliable and a brake specialist looks '
            . 'excellent, for reasons neither controls.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain weighting — JUDGEMENT, heuristic, deliberately unchanged
    |--------------------------------------------------------------------------
    | Orders a garage's strengths/problems so a perfect record over 21 jobs does
    | not outrank a 30-point lead over 300. A crude empirical-Bayes shrinkage.
    |
    | It is NOT a formal shrinkage estimator, and upgrading it to one is a
    | candidate for a later PR. It is deliberately left untouched during this
    | convergence: changing an estimator at the same time as changing the corpus
    | would make it impossible to say which change moved the numbers.
    */
    'domain_weighting' => [
        'enabled'    => true,
        'formula'    => 'abs(vs_fleet_pts) * min(1, sqrt(n) / sqrt(5 * min_sample.garage_domain))',
        'applied_in' => \App\Services\Garage\GarageScorecardService::class,
        'status'     => 'heuristic — candidate for formal shrinkage in a later PR',
    ],

    /*
    |--------------------------------------------------------------------------
    | Freshness
    |--------------------------------------------------------------------------
    | The whole platform now reads one table, so a dead rebuild is a
    | platform-wide silent failure rather than one stale page. Staleness is
    | SURFACED, never swallowed.
    */
    'freshness' => [
        'rebuild_command'   => 'intelligence:rebuild-recurrence',
        'expected_cadence'  => 'daily 04:35',
        'stale_after_hours' => 36,
        'as_of_definition'  => 'MAX(occurred_at) of the canonical dataset',
        'on_stale'          => 'surface the condition in the UI; never render analytics as current',
    ],

    /*
    |--------------------------------------------------------------------------
    | Change history
    |--------------------------------------------------------------------------
    | Append-only. Anyone reading a historical report must be able to see why a
    | KPI moved without reverse-engineering it from git.
    */
    'change_history' => [
        [
            'version'       => '1.0.0',
            'effective'     => '2025-11-01',
            'summary'       => 'Original raw-signature definition.',
            'dataset'       => 'maintenance_signatures (self-join)',
            'deduplication' => false,
            'horizon'       => 'none (varied by implementation)',
            'fleet_comeback' => 40.63,
            'fleet_n'       => 33040,
            'retired_because' =>
                'Counted label rows as repairs (2.62x inflation), applied no right-censoring, and was '
                . 'reimplemented six times with three different horizons — producing a 5.9-point spread '
                . 'across surfaces for the same question.',
        ],
        [
            'version'       => '2.0.0',
            'effective'     => '2026-08-04',
            'summary'       => 'Deduplicated events + right-censoring, single canonical dataset.',
            'dataset'       => 'fault_recurrence_pairs',
            'deduplication' => true,
            'horizon'       => 'corpus_max - 90d',
            'fleet_comeback' => 46.51,
            'fleet_n'       => 10595,
            'reason'        =>
                'One repair must count once, and a repair too recent to have failed must not count as '
                . 'one that held. See docs/Recurrence-Metric-Convergence.md for the measured evidence.',
            'retired_because' =>
                'Counted scheduled services as repair failures. 1,073 of its 10,595 fully-observed '
                . 'events were oil services, which recur BY DESIGN — superseded by 2.1.0.',
        ],
        [
            'version'       => '2.1.0',
            'effective'     => '2026-08-05',
            'summary'       => 'Scheduled services separated from faults; the rate measures repair quality only.',
            'dataset'       => 'fault_recurrence_pairs (kind = fault)',
            'deduplication' => true,
            'horizon'       => 'corpus_max - 90d',
            'fleet_comeback' => 46.38,
            'fleet_n'       => 9522,
            'reason'        =>
                'A recurring oil change is the service working, not the repair failing. Under 2.0.0 the '
                . '1,073 scheduled services in the corpus returned at 47.72% — ABOVE the 46.38% of real '
                . 'faults — so they were not neutral filler, they were pushing every garage rate up. '
                . 'Services are now typed at rebuild (kind) and scoped out of every rate; they remain '
                . 'in the corpus and readable, so the exclusion is auditable rather than a deletion.',
            'moved_by'      => '-0.13 pts fleet (46.51 -> 46.38); n 10,595 -> 9,522',
            'known_limit'   =>
                'Only OIL_SERVICE is typed as service. TYRE (996 pairs) genuinely mixes punctures with '
                . 'rotations and the corpus cannot separate them; maintenance_tasks.kind, which could, '
                . 'reaches 28 of 12,608 pairs. This gets better when the history is classified, not by '
                . 'guessing here.',
        ],
    ],

];
