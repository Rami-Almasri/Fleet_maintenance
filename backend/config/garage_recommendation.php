<?php

/**
 * Tuning surface for the Garage Recommendation engine (App\Services\GarageRecommendationService).
 *
 * This is the DATA-DRIVEN cousin of config/garage_routing.php. Where the Smart Routing engine scores
 * garages against hand-curated policy rules (garage_routing_rules), this engine learns from the actual
 * maintenance history — which garages have PROVEN experience with a given vehicle model + fault, how
 * CONCENTRATED that work is (a true specialist vs. a busy generalist), and how far that exceeds the
 * fleet average (LIFT). Nobody maintains a rules table; the signal emerges from `maintenances`.
 *
 * Same "config is the tuning surface, no migration to re-tune" pattern as garage_routing.php. Every
 * weight/threshold the scorer reads lives here, so the ranking can be adjusted without touching code.
 * The scoring mirrors the validated prototype: experience (volume) + concentration (share of the
 * garage's own work) + lift, all damped by a credibility ramp so a 2-job shop at "100%" never
 * masquerades as the expert.
 *
 * See [[garage-recommendation-engine]] and the standalone BI prototype it was validated against.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Versioning — so an old decision can still be explained
    |--------------------------------------------------------------------------
    | A recommendation is a judgement made by a specific engine, under specific policy, against data as
    | it stood on a specific day. All three move. Six months from now "why did we send that car there?"
    | is unanswerable unless the decision recorded which engine, which rules and which data produced it —
    | re-running today's engine answers a different question.
    |
    | `engine_version` is hand-bumped and describes BEHAVIOUR: bump the minor for a new factor or a
    | changed decision rule, the patch for a tuning change that cannot reorder results. `policy_version`
    | covers the criticality/business/strategy rules that a non-engineer may edit. The config fingerprint
    | is computed, not declared, so a quiet weight change can never masquerade as the same policy.
    */
    'engine_version' => 'garage_decision_v1.4',
    'policy_version' => 'criticality_config_v2',

    // How long the derived history dataset is cached (seconds). The engine reads live `maintenances`
    // once, aggregates in memory, and reuses it. Bumped implicitly whenever the cache key vN changes.
    'cache_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | Scoring weights & thresholds (the tuning knobs)
    |--------------------------------------------------------------------------
    | A garage's fit for a {model, brand, fault} query is a weighted sum of four terms, each in 0..1:
    |   - model : experience + concentration in that model
    |   - brand : experience + concentration in that brand (selected, or inferred from the model)
    |   - fault : experience + concentration×lift in that fault category
    |   - combo : jobs matching ALL selected criteria at once (the strongest "proven on this" signal)
    | Each term is only added when its dimension is part of the query. `combo` applies once ≥2
    | dimensions are selected. Higher total = stronger recommendation.
    */
    'scoring' => [
        'w_model' => 1.0,
        'w_brand' => 0.9,
        'w_fault' => 1.1,
        'w_combo' => 2.0,

        // Credibility ramp: concentration only earns full credit once the garage has this many matching
        // jobs (score factor = min(1, sqrt(jobs)/sqrt(this))). Stops tiny shops winning on "100%".
        'credibility_jobs' => 8,

        // The PRIMARY (exact-match) list requires at least this many combo-matched jobs; if fewer than two
        // garages clear it, the bar drops to 1 so a thin-but-real answer still shows.
        'min_primary_combo' => 3,
        'primary_limit'     => 4,

        // Lift cap so a rare fault at a small garage can't explode the score.
        'lift_cap' => 3.0,

        // Reason-chip thresholds (when each explanation is allowed to appear).
        'model_focus_min_share'     => 0.5,
        'model_focus_min_jobs'      => 5,
        'brand_conc_min_share'      => 0.6,
        'brand_conc_min_jobs'       => 5,
        'fault_specialist_min_share'=> 0.4,
        'fault_specialist_min_jobs' => 6,
        'fault_specialist_min_lift' => 1.5,
        'proven_combo_min'          => 5,   // "Proven on X + Y" needs this many exact-combo jobs
        'high_experience_min'       => 8,   // "High X experience" needs this many jobs

        // "Also worth considering" — a single-factor specialist must have at least this many jobs to be
        // offered, so the alternative is a real destination, not a fluke.
        'also_consider_min_jobs' => 5,

        // Confidence level shown to the user = how much EVIDENCE backs the recommendation (matching jobs).
        // High = strong history; Medium = some; Low = limited data (trust it less). Tune to taste.
        'confidence_high_matches' => 12,
        'confidence_med_matches'  => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | The EXPLAINABLE 100-point score (what the operator actually sees)
    |--------------------------------------------------------------------------
    | `scoring` above produces an unbounded weighted `score` used internally. THIS block defines the
    | 0–100 `match_score` shown on the card, and it is deliberately a plain additive budget so every
    | point can be traced back to a fact:
    |
    |   Fault matching   40   how many of THIS ticket's faults the garage has actually repaired,
    |                         graded exact (same fault + same model) > domain (same fault, any model)
    |                         > general (repair history but nothing in this domain).
    |   Vehicle sim.     20   same model / same brand / same vehicle class.
    |   Hist. success    20   proven successful volume + re-inspection pass rate + turnaround speed.
    |   Specialization   10   share of this garage's own work that sits in these fault domains.
    |   Confidence       10   sample size behind all of the above.
    |
    | A component that CANNOT be measured for a query (e.g. no fault selected) is marked
    | not-applicable and its budget is redistributed pro-rata over the rest, so the number always
    | means "out of 100" and is never silently deflated by missing data.
    |
    | Each `*_full` is a credibility saturation point: N matching jobs earn full credit for that term,
    | ramped as min(1, √jobs / √N), so a 1-job shop can never present as a proven specialist.
    */
    'explain' => [
        'weights' => [
            'fault_matching'    => 40,
            'vehicle_similarity'=> 20,
            'historical_success'=> 20,
            'specialization'    => 10,
            'confidence'        => 10,
        ],

        // Fault matching — the per-fault evidence ladder. A fault scores in exactly one tier; the base is
        // that tier's floor and the ramp is what VOLUME adds on top (base + ramp ≤ 1.0), so the tier
        // ceilings stay strictly ordered: exact (1.00) > domain (0.55) > general (0.10).
        //
        // This ramp is LINEAR (jobs / full), not the √ curve used elsewhere. √ is right for "is this shop
        // credible at all", where the first job is worth the most; it is wrong here, where a single
        // matching repair would collect 41% of the ramp and a one-job garage would read as 82% covered.
        // Linear makes the third job worth exactly as much as the thirtieth is short of full.
        'fault' => [
            'exact_base'   => 0.35, 'exact_ramp'   => 0.65, 'exact_full'   => 8,   // same fault + same model
            'domain_base'  => 0.20, 'domain_ramp'  => 0.35, 'domain_full'  => 20,  // same fault, other models
            'general_base' => 0.00, 'general_ramp' => 0.10, 'general_full' => 40,  // no record in this domain
        ],

        // Vehicle similarity — how close the garage's experience is to THIS car. Shares must total 1.0.
        'vehicle' => [
            'model_share' => 0.65, 'model_full' => 10,
            'brand_share' => 0.20, 'brand_full' => 15,
            'class_share' => 0.15, 'class_full' => 20,   // vehicles.category (class/segment)
        ],

        // Historical success — shares must total 1.0. `unknown` is the NEUTRAL credit given when a
        // sub-signal cannot be measured (no concluded re-inspections / no timed repairs): we neither
        // reward nor punish a garage for data we simply do not have, and the UI says so.
        'success' => [
            'volume_share'   => 0.50, 'volume_full' => 10,   // concluded relevant repairs
            'quality_share'  => 0.35, 'quality_min_attempts' => 3,
            'duration_share' => 0.15, 'duration_min_jobs'    => 3,
            // Turnaround credit is relative to the fleet: at or below the fleet median = full credit,
            // at (median × this multiple) or slower = zero.
            'duration_slow_multiple' => 2.0,
            'unknown' => 0.75,
        ],

        // Specialization — the share of the garage's categorised work sitting in the queried domains at
        // which it counts as a full specialist, lightly damped by sample size.
        'specialization' => [
            'full_share' => 0.40,
            'damp_floor' => 0.50,   // 0 jobs still keeps this fraction of the earned credit
            'damp_full'  => 8,
        ],

        // Confidence — the sample-size bands from the brief. `points` is awarded at the band floor and
        // ramps linearly to the next band's floor.
        'confidence' => [
            'high_min' => 10, 'high_points' => 10,   // 10+ jobs
            'med_min'  => 3,  'med_points'  => 5,    // 3–9 jobs → 5..9 points
            'low_min'  => 1,  'low_points'  => 3,    // 1–2 jobs
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fault criticality — not every fault deserves an equal vote
    |--------------------------------------------------------------------------
    | Averaging a brake fault with a paint scratch is operationally wrong: it lets cosmetic work drag the
    | recommendation away from the garage that has to get the brakes right. Every fault category is
    | assigned a business-impact tier, and its weight multiplies its influence on BOTH the Fault Matching
    | component and the single-vs-split decision.
    |
    | `severity_escalation` lets a fault the inspector marked severe climb a tier — a "high" severity
    | electrical fault (dead lights at night) outranks a routine one. It can only ever escalate, never
    | demote: a brake fault logged as "routine" is still a brake fault.
    */
    'criticality' => [
        'tiers' => [
            'safety_critical'  => ['weight' => 1.5, 'label' => 'Safety critical'],
            'major_mechanical' => ['weight' => 1.3, 'label' => 'Major mechanical'],
            'operational'      => ['weight' => 1.1, 'label' => 'Operational'],
            'cosmetic'         => ['weight' => 0.6, 'label' => 'Cosmetic'],
        ],

        // Fault category (config/maintenance_findings.php keys) → tier. A category missing here falls to
        // `default_tier`, so adding a finding category can never silently make it weightless.
        'categories' => [
            'brakes'       => 'safety_critical',
            'suspension'   => 'safety_critical',
            'tyres'        => 'safety_critical',
            'engine'       => 'major_mechanical',
            'transmission' => 'major_mechanical',
            'electrical'   => 'operational',
            'ac'           => 'operational',
            'lights'       => 'operational',
            'fluids'       => 'operational',
            'routine'      => 'operational',
            'bodywork'     => 'cosmetic',
            'interior'     => 'cosmetic',
        ],
        'default_tier' => 'operational',

        // Inspector severity (maintenance_tasks.severity) → how many tiers it may promote the fault.
        'severity_escalation' => ['high' => 1, 'moderate' => 0, 'routine' => 0],

        // Order, most critical first — used for escalation and for "the critical fault leads" messaging.
        'order' => ['safety_critical', 'major_mechanical', 'operational', 'cosmetic'],

        /*
        | Bridge from the RepairSignatureClassifier's 22 signatures to the 12 findings categories.
        |
        | The fleet has two fault vocabularies: `maintenance_tasks.category_key` (12 curated categories,
        | which the recommender uses but which exists on only a handful of rows) and
        | `maintenance_signatures.signature` (22 machine-derived signatures across ~31k rows). Calibration
        | needs the second — it is the only fault label with enough coverage to segment by — but must
        | report in the first, which is the vocabulary the rest of the product speaks.
        |
        | This map is a REPORTING bridge only. It never feeds scoring: signatures stay a Repair
        | Intelligence projection and the recommender keeps reading category_key, so the ownership
        | boundary is intact. A signature with no entry here segments as `unmapped` rather than being
        | silently folded into a category it does not belong to.
        */
        'signature_categories' => [
            'BRAKES' => 'brakes',        'SUSPENSION' => 'suspension',   'STEERING' => 'suspension',
            'TYRE' => 'tyres',           'RIM' => 'tyres',
            'ENGINE_MECH' => 'engine',   'CHECK_ENGINE' => 'engine',     'COOLING' => 'engine',
            'EXHAUST' => 'engine',       'FUEL_SYS' => 'engine',
            'TRANSMISSION' => 'transmission',
            'ELECTRICAL' => 'electrical', 'BATTERY' => 'electrical',     'KEY' => 'electrical',
            'AC' => 'ac',                'LIGHTS' => 'lights',
            'OIL_SERVICE' => 'routine',  'LEAK_OTHER' => 'fluids',
            'BODY' => 'bodywork',        'GLASS' => 'bodywork',
            'INTERIOR' => 'interior',    'ACCESSORY' => 'interior',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expected outcomes & business factors
    |--------------------------------------------------------------------------
    | "What is likely to happen if we send it here?" — turnaround, comeback risk, cost and queue. These
    | are FORECASTS, and each one is published with the BASIS it was derived from and the sample behind
    | it. A forecast we cannot ground is reported as unavailable; it is never silently replaced by a
    | fleet number wearing a garage's name.
    |
    | Basis ladder, best first: garage+fault → garage → fleet → unavailable.
    |
    | ⚠️ Data reality at the time of writing (measure again before trusting a threshold change):
    |   turnaround  MEASURED — 6,600 timed repairs (out_date → actual_in_date), 62 garages with ≥5
    |   comeback    MEASURED — 31,120 attributable signature repairs, 63 garages with ≥30; the per-garage
    |               numbers reconcile with the frozen fleet baseline (39.9% vs 40.6%)
    |   queue       LIVE     — open workflow tickets per garage, small but genuine current state
    |   cost        THIN     — only 317 costed repairs fleet-wide and 3 garages with ≥5; nearly always
    |               falls back to the fleet median and MUST be labelled as such
    |   distance    ABSENT   — `vendors` stores no address or coordinates, so transport impact cannot be
    |               computed at all. Deliberately not faked; add location to vendors to enable it.
    */
    'outcomes' => [
        'cache_ttl' => 900,

        // Minimum samples before a forecast may be attributed to a garage rather than the fleet.
        'min_sample' => [
            'duration_garage_fault' => 5,
            'duration_garage'       => 5,
            'comeback_garage'       => 30,   // matches the KPI service's floor for this proxy
            'cost_garage'           => 5,
        ],

        // The comeback proxy window — MUST track App\Kpi\OperationalKpiService::COMEBACK_WINDOW_DAYS, or
        // the per-garage rates stop reconciling with the published fleet baseline.
        'comeback_window_days' => 90,

        // Turnaround outliers: a repair parked for months is a dispute or a data error, not a turnaround.
        'duration_outlier_days' => 60,

        // Queue → when can they actually start? Each open ticket already at the garage is assumed to add
        // this much delay before a new car is looked at. A declared lead time would be better, but
        // `vendors.default_lead_time_days` is NULL for all 247 garages.
        'queue_days_per_open_ticket' => 0.5,
        'queue_busy_threshold'       => 4,   // open tickets above which we call a garage busy
    ],

    /*
    |--------------------------------------------------------------------------
    | Repair cost, rebuilt from the vehicle expense ledger
    |--------------------------------------------------------------------------
    | `maintenances.cost` is almost empty — 314 priced repairs fleet-wide, 294 of them at ONE garage, so
    | only 3 garages ever earned a cost of their own and everyone else showed the fleet median wearing
    | their name. The expense ledger holds the same money in a different place: 28,327 lines / AED 26.9M,
    | with the garage and the work written into `remarks`.
    |
    | So cost is ATTRIBUTED: a ticket's spend is whatever was booked against that vehicle in a tight
    | window around the day it went out. Validated against the 313 tickets whose true cost we do know —
    | 39% match exactly, 50% within 10%, median error 11.4%. That is good enough to publish WITH its
    | sample and basis, and nowhere near good enough to publish as a bare number.
    |
    | ⚠️ The window is deliberately TIGHT. Widening it looks like better coverage and is not: the median
    | climbs 400 → 800 → 1,137 → 1,899 as it goes 0 → 7 → 14 → 30 days, which is other spending leaking
    | in, not repairs being priced more accurately.
    */
    'cost' => [
        'enabled'   => true,
        'cache_ttl' => 3600,

        // Attribution window around `out_date`, in days. Two days back catches a deposit paid before
        // the car moved; seven forward catches the invoice settled after it came back.
        'window_before_days' => 2,
        'window_after_days'  => 7,

        // Minimum priced repairs before a figure may be attributed at each grain. Below the floor we
        // fall to the next rung down and SAY we did.
        'min_sample' => [
            'garage_fault_model' => 5,
            'garage_fault'       => 5,
            'garage'             => 5,
        ],

        // RULE: never mix unrelated expenses into repair cost. Any line matching one of these is not a
        // repair and is dropped before anything is averaged — fuel, insurance, registration, fines,
        // trackers, washing, cash advances. Kept as reviewable config rather than a regex buried in a
        // service, because what counts as "not a repair" is a business judgement, not an implementation
        // detail, and it will need adjusting as new vocabulary shows up in the ledger.
        'exclude' => [
            'fuel'         => 'petrol|fuel|enoc|emarat|adnoc|filling station|diesel',
            'insurance'    => 'insuranc|takaful',
            'registration' => 'rta|car test|certificate|registration|renewal|mulkiya|passing|retest',
            'fines'        => 'salik|fine |fines|traffic file|black point|impound',
            'tracking'     => 'gps|tracker|device',
            'cleaning'     => 'wash|cleaning|polish|shampoo',
            'admin'        => 'advance|salary|commission|petty cash',
        ],

        // A single line above this is a write-off, a bulk settlement or a keying error, not one repair.
        'line_outlier_aed' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor override reasons — the feedback loop
    |--------------------------------------------------------------------------
    | When a supervisor sends the car somewhere other than the recommendation, the reason is captured
    | from this fixed list so it can be COUNTED. Free text cannot be aggregated, and an override with no
    | reason teaches nothing except that someone disagreed.
    |
    | ⚠️ AN OVERRIDE IS NOT A MISTAKE. Most are legitimate operational judgement the engine has no way to
    | see — a customer asking for a particular workshop, a standing relationship, a garage that can take
    | the car this afternoon. Nothing in this taxonomy is worded as fault, and nothing downstream treats
    | it as one: the moment supervisors feel logged-and-judged they pick whichever reason ends the
    | conversation fastest, and the data stops meaning anything.
    |
    | `axis` is what makes an override analysable. It says which part of the decision the supervisor was
    | actually trading on:
    |   cost / speed / availability   we ALREADY measure this — a consistent pattern means our weights
    |                                 disagree with the operation, and the weights are the thing to fix
    |   relationship / external       we do NOT measure this and arguably should not — these overrides
    |                                 are correct behaviour and must never be read as engine error
    |   evidence                      the supervisor knows something the history does not contain yet
    */
    'override_reasons' => [
        'lower_cost'          => ['label' => 'Lower cost priority',              'axis' => 'cost'],
        'faster_availability' => ['label' => 'Faster availability',              'axis' => 'availability'],
        'faster_turnaround'   => ['label' => 'Faster turnaround expected',       'axis' => 'speed'],
        'customer_requested'  => ['label' => 'Customer requested this garage',   'axis' => 'external'],
        'existing_relation'   => ['label' => 'Existing relationship with garage', 'axis' => 'relationship'],
        'special_expertise'   => ['label' => 'Special expertise for this repair', 'axis' => 'evidence'],
        'warranty_or_contract' => ['label' => 'Warranty or contract obligation', 'axis' => 'external'],
        'location'            => ['label' => 'Closer / easier to move the car',  'axis' => 'availability'],
        'other'               => ['label' => 'Other reason',                     'axis' => 'other'],
    ],

    /*
    | How much evidence before an override pattern is worth acting on. Below these the "pattern" is a
    | handful of decisions by one supervisor in one week, and re-tuning weights on that is how a model
    | starts chasing noise.
    */
    'learning' => [
        'min_decisions_for_signal' => 20,   // total decisions before acceptance rate means anything
        'min_overrides_per_reason' => 5,    // per-reason floor before a reason is reported as a pattern
        // An override at a small score gap suggests the engine nearly agreed; a large one suggests it
        // was working from different information entirely. Those need different responses.
        'near_tie_gap'             => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Business trade-off (technical score is not the whole decision)
    |--------------------------------------------------------------------------
    | The technical 100 answers "can they do this job?". It deliberately stays clean of money and
    | calendars — folding cost into it would destroy the traceability the breakdown exists for. Instead
    | the business factors are scored on their OWN axis and the decision layer compares them.
    |
    | A challenger only unseats the technical pick when the technical gap is small enough to be noise
    | (`max_technical_gap`) AND the business advantage is material (`min_business_gain`). Otherwise the
    | trade-off is merely EXPLAINED, and the technical pick stands.
    */
    'business' => [
        'enabled' => true,

        'weights' => [
            'cost'         => 0.40,
            'speed'        => 0.35,   // expected turnaround
            'availability' => 0.25,   // queue / how soon they can start
        ],

        // What counts as a MATERIAL difference — below these, two garages are "about the same" and we
        // never bother the supervisor with the comparison.
        'material' => [
            'cost_pct'       => 0.15,   // ≥15% cheaper
            'duration_days'  => 1.0,    // ≥1 day faster
            'start_days'     => 1.0,    // ≥1 day sooner
        ],

        'max_technical_gap'  => 5,    // points of technical score we are willing to trade away
        'min_business_gain'  => 15,   // …and only for this much business advantage (0–100 scale)
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-fault assignment strategy (one garage, or split the job?)
    |--------------------------------------------------------------------------
    | A car with an engine knock AND accident body damage has no single best garage — averaging the two
    | just hides that. The strategy layer scores BOTH plans (send everything to the best all-rounder vs.
    | split the faults between specialists) and only recommends a split when it clearly pays for the
    | extra vehicle move. Every gate below must pass; otherwise we stay single and say why.
    |
    | The split plan is directly actionable: assign-dispatch already accepts `fault_ids` for the first
    | leg and assign-pending routes the remainder. See [[split-dispatch-feature]].
    */
    'strategy' => [
        'enabled' => true,

        // A plan's confidence is its WEAKEST fault (a chain is as strong as its weakest link), as a %.
        // Splitting must beat the single-garage plan by at least this many points to be worth the move.
        'min_gain' => 15,

        // …and the single-garage plan must actually be weak somewhere: if its worst fault is already
        // covered at or above this, one garage is good enough and operational simplicity wins.
        'weak_fault_max' => 55,

        // Guardrails on the split itself.
        'max_legs'          => 2,   // never send one car on a tour
        'min_leg_evidence'  => 3,   // each leg's garage needs this many relevant jobs — no fluke legs
        'min_faults_to_split' => 2,

        // CRITICALITY gates. A split is a real operational cost, so it must be bought by a fault that
        // matters. A leg carrying nothing but cosmetic work never justifies moving the car — the paint
        // can wait for the next visit; the brakes cannot.
        'leg_min_tier'   => 'operational',   // every leg must carry at least one fault at this tier or above
        'weak_fault_max_by_tier' => [
            // How weak a fault's coverage must be before it is worth splitting FOR. A safety-critical
            // fault is worth moving the car over much sooner than a cosmetic one.
            'safety_critical'  => 70,
            'major_mechanical' => 60,
            'operational'      => 55,
            'cosmetic'         => 30,
        ],

        // The downtime a second garage move costs, in days. Charged against the split's benefit so a
        // marginal quality gain never wins by ignoring the extra day the car spends off the road.
        'split_downtime_days'      => 1.0,
        // …converted into score points so it can be compared with the confidence gain directly.
        'downtime_points_per_day'  => 8,

        // Splitting is only sane when the faults are genuinely INDEPENDENT work — a different trade, a
        // different bay, no shared diagnosis. Legs must land in different groups here; two faults from
        // the same group always travel together (an engine job and a transmission job are one visit).
        'independent_domains' => [
            'mechanical' => ['engine', 'transmission', 'brakes', 'suspension', 'fluids', 'routine'],
            'body'       => ['bodywork', 'interior'],
            'electrical' => ['electrical', 'ac', 'lights'],
            'wheels'     => ['tyres'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality penalty (re-inspection track record) — OFF-by-reliability
    |--------------------------------------------------------------------------
    | A SOFT penalty (never a hard block, matching the Smart Routing strategy) from this garage's failed
    | re-inspections on the SAME fault category, read from maintenance_task_assignments. Per the brief it
    | only kicks in when the data is RELIABLE: the garage must have at least `min_attempts` concluded
    | attempts for that category before any points are docked, so we never punish on one bad data point.
    */
    'quality_penalty' => [
        'enabled'      => true,
        'min_attempts' => 3,      // reliability gate: need ≥ this concluded attempts before penalising
        'penalty_each' => 0.15,   // score subtracted per failed re-inspection (score units)
        'penalty_max'  => 0.60,   // ceiling on the penalty
        'warn_rate'    => 0.40,   // failure-rate above this raises a ⚠️ warning chip (still selectable)
    ],
];
