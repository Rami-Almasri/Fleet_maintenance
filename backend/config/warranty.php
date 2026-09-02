<?php

/**
 * The dials on the warranty-aware layer.
 *
 * Everything here is an OPERATIONAL choice, not a technical one — how much notice this fleet needs
 * to act on a warranty before it disappears, and how long a dealer is given before somebody chases
 * them. They live in config rather than as constants because the right answer differs by fleet and
 * changes with experience, and because a threshold buried in a service is a threshold nobody ever
 * revisits.
 *
 * NOTHING HERE DECIDES COVERAGE. Not one of these values can turn an UNKNOWN into a COVERED. They
 * only change WHEN somebody is asked to look — see WarrantyCoverageEngine, where the decision is
 * made from recorded facts and, failing that, handed to a human.
 */
return [

    /**
     * How near the end of a warranty counts as "expiring soon", on each leg independently.
     *
     * BOTH legs, because either can be the binding one and in a rental fleet it is usually distance:
     * a car doing 6,000 km a month with 8 months and 900 km of cover left is expiring soon, and a
     * date-only threshold would show it as comfortably healthy right up until the day it isn't.
     *
     * 60 days is chosen to be longer than it takes to get a car looked at, sent to a dealer and back
     * — a warning that arrives inside the turnaround time is a warning that arrives too late.
     */
    'expiring_soon_days' => (int) env('WARRANTY_EXPIRING_SOON_DAYS', 60),
    'expiring_soon_km'   => (int) env('WARRANTY_EXPIRING_SOON_KM', 5000),

    /**
     * When to ask for the pre-expiry inspection — the one that finds defects while somebody else is
     * still paying for them.
     *
     * Deliberately SHORTER than the expiring-soon horizon. The alert is a heads-up; the inspection is
     * a job somebody has to schedule, and asking for it two months out means it sits unanswered until
     * it is urgent. Asked once per warranty (the check requirement's cycle key is the warranty), so
     * the daily sweep re-raising it every morning is impossible by construction.
     */
    'inspection_lead_days' => (int) env('WARRANTY_INSPECTION_LEAD_DAYS', 30),

    /**
     * How long a provider is given to answer once a case is sent to them, when nobody recorded a
     * date the dealer actually promised.
     *
     * A DEFAULT, NOT A RULE: warranty_claims.provider_response_due_on is set explicitly whenever a
     * real commitment exists, and this only fills the gap so that "awaiting dealer" is chaseable
     * instead of a column things rot in. A case with no due date and no default is never overdue,
     * which is how a three-week silence used to become normal.
     */
    'provider_response_days' => (int) env('WARRANTY_PROVIDER_RESPONSE_DAYS', 7),

    /**
     * How long a coverage review may sit unanswered before it becomes an alert in its own right.
     *
     * This one matters more than it looks. A review that nobody answers holds a purchase request
     * hostage — the car is not being repaired and the money is not being spent. Two days, because
     * the alternative to answering is not "nothing happens", it is "somebody overrides the gate to
     * get the car moving", and every override is a claim we probably lose.
     */
    'review_overdue_days' => (int) env('WARRANTY_REVIEW_OVERDUE_DAYS', 2),

    /**
     * How long an open case may go without movement before it is chased.
     *
     * Claims are lost by being forgotten far more often than by being refused.
     */
    'case_stale_days' => (int) env('WARRANTY_CASE_STALE_DAYS', 14),
];
