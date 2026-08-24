<?php

/**
 * SECOND WORDINGS FOR ONE CONCEPT — alternative spellings that resolve to an existing catalog row.
 *
 * ── WHY THIS EXISTS RATHER THAN A SECOND CATALOG ROW ───────────────────────────────────────────
 * [[EventClassificationService]] types a finding by matching its text against the fault / service /
 * damage catalogs on `name` or `slug`. Exact match, nothing else. So a concept the platform genuinely
 * knows about, written a second way, classifies as NOTHING — and the legacy shield then files it as a
 * fault by default. It looks typed. It carries no catalog id, no default severity, and nothing the
 * reporting layer can group on.
 *
 * The obvious repair — add the second spelling as its own catalog row — is the actual bug. Two rows
 * for one concept forks its history: half the tyre rotations under one id, half under another, and
 * every recurrence count, garage scorecard and service interval quietly computed over half the
 * evidence. [[FindingsVocabularyCheckCommand]] already documents this exact failure ("a one-letter
 * spelling difference forks one fault into two rows") for the ontology side.
 *
 * The other obvious repair — rename one side — was rejected too, and this is the interesting part:
 * THE FORK RUNS THE OTHER WAY FROM WHAT THE CATALOGS SUGGEST. "Tire Rotation" (American) is the
 * platform's canonical spelling — ServiceReminder::TYPES, ServiceRemindersSync, DiagnosticGateService,
 * DataHealthService and MaintenanceWorkflowService all write it. The SERVICE CATALOG is the outlier
 * with "Tyre Rotation". Renaming either side means editing live reminder types and sync mappings to
 * win a spelling argument, on a fleet that already has rows in both. An alias costs nothing and
 * breaks nothing.
 *
 * ── WHAT BELONGS HERE ──────────────────────────────────────────────────────────────────────────
 * ONLY a second wording for a concept that already has a row. This file must never be a way to add
 * vocabulary: if the concept is genuinely new, it belongs in config/fault_catalog.php,
 * config/service_catalog.php or config/damage_catalog.php with its own severity, category and rules.
 * An alias inherits ALL of those from its target, which is exactly why it is safe — and exactly why
 * it is wrong for anything that needs its own.
 *
 * Aliases are matched AFTER the real catalogs (a real name always wins) and are normalised the same
 * way, so case and spacing do not matter.
 */

return [

    /**
     * Alternative wording => the canonical catalog slug it means.
     *
     * Each entry below is a keyword config/maintenance_findings.php offers an inspector today, whose
     * concept already exists in a catalog under a different spelling.
     */
    'service' => [
        // The Tire/Tyre fork. The findings catalog and every reminder path say "Tire"; the service
        // catalog says "Tyre". Same wheel, same service, same interval.
        'Tire Rotation'   => 'tyre_rotation',
        'Tire Change'     => 'tyre_change',
        // The findings catalog qualifies it ("Coolant service"); the service catalog names the fluid.
        'Coolant service' => 'coolant',
    ],

    'fault' => [
        // (none yet — every unrecognised fault wording so far was a genuinely missing concept and was
        // added to config/fault_catalog.php with its own severity and category.)
    ],

    'damage' => [
        // "Damaged rim" is the findings catalog's general wording for what the damage catalog splits
        // into Rim Scratch and Rim Dent / Bend. Pointed at the DENT row because that is the one that
        // affects roadworthiness — an alias must resolve to a single row, and between the two this is
        // the one it would be wrong to under-call. If the split matters at capture time, retire the
        // general wording from the findings catalog rather than making this entry cleverer.
        'Damaged rim' => 'rim_dent_bend',
    ],
];
