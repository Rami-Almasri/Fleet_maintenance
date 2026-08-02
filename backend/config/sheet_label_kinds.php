<?php

/**
 * THE LEGACY SHEET LABEL VOCABULARY — which of the N-Maintenance sheet's labels describe a
 * SERVICE, and which are not findings at all.
 *
 * `maintenances.service_main` (system category) and `service_sup` (specific finding) are the only
 * description the ~27k imported sheet events carry. They were typed into one flat pair of free-text
 * columns with no type axis, so "Oil & Fillter Change" (planned service), "Rim Scratch" (fault) and
 * "Customer" (who asked for the visit) all sit in the same column and every reader has counted them
 * as the same thing. The vehicle dossier's Fault Distribution donut is the visible symptom: it
 * reported an oil-and-filter change as one of the car's top faults.
 *
 * WHY A MAP AND NOT A CLASSIFIER. The vocabulary is CLOSED and small — 147 distinct labels across
 * the whole corpus, and the sheet is no longer the write path (new work is typed at the catalog by
 * [[EventClassificationService::classifyFromCatalog]], which stores `maintenance_tasks.kind`). A
 * finite, hand-checked list is the data itself; a keyword classifier would be a guess re-run on
 * every read. Regenerate the coverage check any time with `php artisan sheet:label-kinds --unmapped`.
 *
 * THE THREE BUCKETS. Only `service` and `context` are listed; anything not listed is a `fault`,
 * which keeps the default identical to today's behaviour (never worse) and means a newly imported
 * label degrades to "counted as a fault" rather than vanishing from the chart.
 *
 *   service — planned/elective work. Recurring is normal, so it must never count as a fault.
 *   context — not a finding at all: who asked, what stage the row was at, what kind of visit it was.
 *             Excluded from fault counts, but a visit whose ONLY labels are context still reads as
 *             "no fault recorded" (Unspecified) rather than as a service visit.
 *
 * This describes the LEGACY corpus only. It is not a second classifier: `maintenance_tasks.kind`
 * remains the sole classification for anything created in the workflow.
 * See docs/Service-vs-Fault-Domain-Separation.md.
 */
return [

    /**
     * Planned, elective or preventive work. Matching is case- and whitespace-insensitive, so
     * "Periodic Maintenance" and "Periodic maintenance" need only one entry.
     */
    'service' => [
        'Oil & Fillter Change',       // the sheet's spelling — 910 rows; do not "fix" it here
        'Oil & Filter Change',
        'Periodic Maintenance',
        'periodic m & damage',        // a combined visit; its damage half is carried by its own tags
        'Cleaning',
        'Deep Cleaning',
        'ACC Programming',            // fitting/coding an accessory — the FAULT is "ACC Programming Error"
        'Key Battery Replacement',
        'Freon Recharge Needed',
        'Upgrades / Modifications',
        'Modification-Related',
    ],

    /**
     * Not findings: request origin, workflow stage, and visit type. These leaked into the finding
     * columns because the sheet had nowhere else to put them.
     */
    'context' => [
        'Maintenance',                // visit type on the customer sheet, not a finding
        'Main reason',
        'Customer',                   // who requested the visit
        'Company',
        'Staff',
        'Garage',
        'Repeated',                   // a flag on the visit, not something found on the car
        'New Car',
        'Ready',                      // workflow stage chatter
        'Test',
        'Testing',
    ],
];
