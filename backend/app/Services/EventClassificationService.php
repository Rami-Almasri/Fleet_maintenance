<?php

namespace App\Services;

use App\Models\DamageCatalog;
use App\Models\FaultCatalog;
use App\Models\InspectionType;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\ServiceCatalog;

/**
 * The SINGLE classification choke point for maintenance events.
 *
 * `kind` (fault | service | inspection) is the primary domain classification. Its source of truth is
 * the catalog the user picked — resolved here, never guessed from symptom text at read time. Two entry
 * points:
 *
 *   classifyFromCatalog()  — the NORMAL path: the user picked a catalog row, so kind is authoritative.
 *   resolveLegacyKind()    — the SHIELD, run ONCE at backfill/import for rows that carry no catalog
 *                            reference. Best-effort, marks ambiguous rows needs_review. Never read-time.
 *   labelKind()            — types a legacy SHEET LABEL (a `service_main`/`service_sup` tag), not an
 *                            event: a lookup over the closed 147-label vocabulary in
 *                            config/sheet_label_kinds.php. It exists because the imported sheet
 *                            corpus has no `kind` to read and every reader was counting oil changes
 *                            as faults. Never use it on anything that has a MaintenanceTask.
 *
 * Every write path (workflow, API, backfill, import) goes through this class; no other code assigns
 * `kind` directly. See docs/Service-vs-Fault-Domain-Separation.md.
 */
class EventClassificationService
{
    /**
     * Score a single ontology match must reach before it is allowed to decide an event's type.
     * 70 is the matcher's "confident" band (see `ontology:coverage`); below it the weak bucket is full of
     * generic-token hits ("under", "noise") that must not out-vote the explicit rules.
     */
    private const ONTOLOGY_EVIDENCE_MIN_SCORE = 70;

    /** Cached normalized lookup maps (built once per request). */
    private ?array $serviceMap = null;   // normalized name|slug => service_catalog_id
    private ?array $faultMap = null;     // normalized name|slug => fault_catalog_id
    private ?array $damageMap = null;    // normalized name|slug => damage_catalog_id (null when config-only)
    private ?array $labelMap = null;     // normalized legacy sheet label => service|context|damage
    private ?array $aliasMap = null;     // normalized second wording => ['kind' =>, 'id' =>]
    private ?array $faultReasonIds = null; // maintenance_reasons ids whose name is a fault

    /**
     * NORMAL path — the user picked a catalog row. Returns the attribute set to persist on the task:
     * [kind, fault_catalog_id, service_catalog_id, inspection_type_id, classification_source].
     * Exactly one catalog id is non-null and matches kind (the model guard + DB CHECK enforce it too).
     *
     * @param array{kind:string, catalog_id?:int, catalog_slug?:string} $selection
     * @throws \InvalidArgumentException on unknown kind or unresolved catalog row
     */
    public function classifyFromCatalog(array $selection): array
    {
        $kind = $selection['kind'] ?? null;
        if (! in_array($kind, MaintenanceTask::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown maintenance-event kind: " . var_export($kind, true));
        }

        $row = $this->resolveCatalogRow($kind, $selection['catalog_id'] ?? null, $selection['catalog_slug'] ?? null);
        if (! $row) {
            throw new \InvalidArgumentException("No {$kind} catalog row for the given selection.");
        }

        return $this->attributes($kind, $row->id, MaintenanceTask::CLS_CATALOG);
    }

    /**
     * NORMAL path, from a findings entry — the shape the inspector's report actually produces.
     *
     * Returns the attribute set to persist, or NULL when the finding names nothing the catalogs know (the
     * caller then lets the legacy shield have it).
     *
     * Two ways a finding can be authoritative:
     *   1. It carries an explicit `kind` (+ optional `catalog_id`/`catalog_slug`) — a type-first intake.
     *   2. Its text EXACTLY names a `service_catalog` or `fault_catalog` row. The findings picker offers
     *      catalog wording (every one of the fault catalog's rows exists in the findings vocabulary — the
     *      invariant `findings:vocabulary-check` enforces), so an exact hit IS the user having picked that
     *      catalog row; the only thing missing was the plumbing to say so.
     *
     * This is what makes ADR §0.2 ("the catalog is the source of type") true in practice. Before it,
     * every task in the database was classified `resolver` — a guess — including the ones where the user
     * had picked an unambiguous catalog name (audit H5).
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>|null
     */
    public function classifyFromFinding(array $finding): ?array
    {
        if (! empty($finding['kind'])) {
            // An explicit type with a catalog reference is the strongest possible statement.
            if (! empty($finding['catalog_id']) || ! empty($finding['catalog_slug'])) {
                return $this->classifyFromCatalog([
                    'kind'         => $finding['kind'],
                    'catalog_id'   => $finding['catalog_id'] ?? null,
                    'catalog_slug' => $finding['catalog_slug'] ?? null,
                ]);
            }

            // A type with no catalog row behind it: honour it, but say where it came from.
            if (in_array($finding['kind'], MaintenanceTask::KINDS, true)) {
                return $this->attributes($finding['kind'], null, MaintenanceTask::CLS_MANUAL);
            }
        }

        $text = $this->norm($finding['text'] ?? null);
        if ($text === '') {
            return null;
        }

        // DAMAGE FIRST. Its vocabulary is the most specific of the three ("Rim Scratch", "Bumper
        // Damage") and several of its wordings also live in the fault catalog for historical reasons;
        // asking the most specific catalog first means the wording that unambiguously describes
        // externally-caused damage is never claimed by a broader fault row.
        if (array_key_exists($text, $this->damageMap()) && $this->damageMap()[$text] !== null) {
            return $this->attributes(MaintenanceTask::KIND_DAMAGE, $this->damageMap()[$text], MaintenanceTask::CLS_CATALOG);
        }
        if (isset($this->serviceMap()[$text])) {
            return $this->attributes(MaintenanceTask::KIND_SERVICE, $this->serviceMap()[$text], MaintenanceTask::CLS_CATALOG);
        }
        if (isset($this->faultMap()[$text])) {
            return $this->attributes(MaintenanceTask::KIND_FAULT, $this->faultMap()[$text], MaintenanceTask::CLS_CATALOG);
        }

        // SECOND WORDINGS, checked LAST so a real catalog name always wins. One concept written two
        // ways ("Tire Rotation" here, "Tyre Rotation" in the service catalog) used to classify as
        // nothing and be filed as a fault by the shield below. An alias resolves it to the EXISTING
        // row — never a new one, because a second row for one concept forks its whole history.
        // See config/catalog_aliases.php.
        if ($alias = $this->aliasMap()[$text] ?? null) {
            return $this->attributes($alias['kind'], $alias['id'], MaintenanceTask::CLS_CATALOG);
        }

        return null;
    }

    /**
     * normalised alternative wording => ['kind' => …, 'id' => catalog id].
     *
     * Resolved against the SAME maps the exact-match branch uses, so an alias can only ever point at a
     * row that genuinely exists; an entry naming a slug nothing defines is skipped rather than
     * producing a kind with no catalog behind it. `catalog:aliases-check` asserts none are skipped.
     *
     * @return array<string,array{kind:string,id:?int}>
     */
    private function aliasMap(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $this->aliasMap = [];

        $targets = [
            MaintenanceTask::KIND_SERVICE => $this->serviceMap(),
            MaintenanceTask::KIND_FAULT   => $this->faultMap(),
            MaintenanceTask::KIND_DAMAGE  => $this->damageMap(),
        ];

        foreach ((array) config('catalog_aliases', []) as $kind => $entries) {
            $map = $targets[$kind] ?? null;
            if ($map === null) {
                continue;
            }

            foreach ((array) $entries as $wording => $slug) {
                $key  = $this->norm($wording);
                $slugKey = $this->norm($slug);

                if ($key === '' || ! array_key_exists($slugKey, $map)) {
                    continue;   // alias points at nothing — surfaced by the check command, never guessed
                }

                $this->aliasMap[$key] = ['kind' => $kind, 'id' => $map[$slugKey]];
            }
        }

        return $this->aliasMap;
    }

    /**
     * Aliases that name a catalog slug nothing defines — the one way this file can rot silently.
     *
     * @return array<int,string> "kind → wording → slug" for each broken entry; empty means healthy
     */
    public function aliasViolations(): array
    {
        $targets = [
            MaintenanceTask::KIND_SERVICE => $this->serviceMap(),
            MaintenanceTask::KIND_FAULT   => $this->faultMap(),
            MaintenanceTask::KIND_DAMAGE  => $this->damageMap(),
        ];

        $broken = [];

        foreach ((array) config('catalog_aliases', []) as $kind => $entries) {
            foreach ((array) $entries as $wording => $slug) {
                if (! isset($targets[$kind])) {
                    $broken[] = "$kind is not a catalog kind (alias “$wording”)";
                    continue;
                }
                if (! array_key_exists($this->norm($slug), $targets[$kind])) {
                    $broken[] = "$kind → “$wording” → “$slug” names no catalog row";
                }
                // An alias must never shadow a REAL name — that would mean the concept has two rows
                // after all, and the alias is hiding it rather than fixing it.
                if (array_key_exists($this->norm($wording), $targets[$kind])) {
                    $broken[] = "$kind → “$wording” is already a real catalog name; the alias is redundant";
                }
            }
        }

        return $broken;
    }

    /**
     * SHIELD — resolve a kind for a legacy/import row that has no catalog pick. Priority order (first
     * match wins); ambiguous outcomes are flagged needs_review for human confirmation. Recall is out of
     * scope. Inspection is not inferred from legacy tasks (historically none existed as tasks) — the
     * default when nothing matches is `fault` (= today's behaviour, never worse).
     *
     * @return array the attribute set to persist (kind, *_catalog_id, classification_source, needs_review)
     */
    public function resolveLegacyKind(MaintenanceTask $task): array
    {
        $symptom = $this->norm($task->symptom);
        $ticket  = $task->relationLoaded('maintenance') ? $task->maintenance : $task->maintenance()->first();

        // 0) Exact DAMAGE catalog match — the most specific vocabulary, checked first for the same
        //    reason as in classifyFromFinding().
        if ($symptom !== '' && array_key_exists($symptom, $this->damageMap())) {
            return $this->attributes(MaintenanceTask::KIND_DAMAGE, $this->damageMap()[$symptom], MaintenanceTask::CLS_RESOLVER);
        }

        // 1) Exact catalog name/slug match — highest confidence, gives us the catalog id too.
        if ($symptom !== '' && isset($this->serviceMap()[$symptom])) {
            return $this->attributes(MaintenanceTask::KIND_SERVICE, $this->serviceMap()[$symptom], MaintenanceTask::CLS_RESOLVER);
        }
        if ($symptom !== '' && isset($this->faultMap()[$symptom])) {
            return $this->attributes(MaintenanceTask::KIND_FAULT, $this->faultMap()[$symptom], MaintenanceTask::CLS_RESOLVER);
        }

        // 2) Curated routine allow-list (the existing service/fault boundary) — service, no catalog id.
        $routineKeys = array_map([$this, 'norm'], array_keys((array) config('maintenance_findings.routine_service_types', [])));
        if ($symptom !== '' && in_array($symptom, $routineKeys, true)) {
            return $this->attributes(MaintenanceTask::KIND_SERVICE, null, MaintenanceTask::CLS_RESOLVER);
        }

        // 2.5) CLEAR fault evidence WINS over ticket context — an explicit failure word ("Brake Failure",
        //      "Engine noise", "oil leak") is a fault even when found on a Routine/Periodic visit. This runs
        //      BEFORE the routine-context rule below so routine tickets can't bury a real fault.
        if ($symptom !== '' && $this->hasFaultEvidence($symptom)) {
            return $this->attributes(MaintenanceTask::KIND_FAULT, $this->faultMap()[$symptom] ?? null, MaintenanceTask::CLS_RESOLVER);
        }

        // 2.6) ASK THE ONTOLOGY. The keyword list above is a fast substring pass in two languages; the
        //      ontology is the platform's actual automotive vocabulary (1,400+ terms, 289 of them Arabic,
        //      86 deliberate misspellings) and it knows which lane a phrase belongs to. A confident match
        //      on a `routine` concept is service evidence; a confident match on anything else is fault
        //      evidence. This is what stops "صوت باب امامي" (front door noise) being filed as planned
        //      maintenance because its ticket happened to be typed routine.
        if ($symptom !== '' && ($ontologyKind = $this->ontologyKind($symptom)) !== null) {
            $map = $ontologyKind === MaintenanceTask::KIND_SERVICE ? $this->serviceMap() : $this->faultMap();

            return $this->attributes($ontologyKind, $map[$symptom] ?? null, MaintenanceTask::CLS_RESOLVER);
        }

        // 3) Ticket/finding context says routine/periodic → service.
        //
        //    NEEDS REVIEW. Ticket context is evidence about the VISIT, not about this individual finding —
        //    a routine visit is exactly where an unrelated fault gets discovered. Every rule above tested
        //    the symptom itself and can stand behind its answer; this one has tested nothing about the
        //    finding, so it must not assert confidence. Marking it reviewable is what makes the mistake
        //    FINDABLE in the review queue instead of silent (audit C1: a real fault was stored as a
        //    service with needs_review=0 and nothing could surface it).
        if ($task->category_key === 'routine'
            || ($ticket && $ticket->visit_context === Maintenance::CONTEXT_ROUTINE)
            || ($ticket && $ticket->maintenance_type === Maintenance::TYPE_ROUTINE)
            || ($ticket && $ticket->trigger_reason === Maintenance::TRIGGER_PERIODIC)) {
            // `category_key === 'routine'` is the one exception: that IS a statement about the finding
            // (the inspector picked a routine chip), so it stays confident.
            $fromFinding = $task->category_key === 'routine';

            return $this->attributes(MaintenanceTask::KIND_SERVICE, null, MaintenanceTask::CLS_RESOLVER, ! $fromFinding);
        }

        // 4) Ticket classified as a real problem → fault.
        if ($ticket && in_array($ticket->maintenance_type, [
            Maintenance::TYPE_BREAKDOWN, Maintenance::TYPE_INS_INCIDENT, Maintenance::TYPE_NON_INS_INCIDENT,
        ], true)) {
            return $this->attributes(MaintenanceTask::KIND_FAULT, null, MaintenanceTask::CLS_RESOLVER);
        }

        // 5) Fuzzy routine token — low confidence, flag for review.
        foreach (['oil', 'filter', 'rotation', 'alignment', 'coolant', 'battery check', 'spark plug'] as $token) {
            if ($symptom !== '' && str_contains($symptom, $token)) {
                return $this->attributes(MaintenanceTask::KIND_SERVICE, null, MaintenanceTask::CLS_RESOLVER, true);
            }
        }

        // 6) Nothing matched → default fault (= current behaviour), flag for review.
        return $this->attributes(MaintenanceTask::KIND_FAULT, null, MaintenanceTask::CLS_RESOLVER, true);
    }

    // ── legacy sheet labels ───────────────────────────────────────────────────────────────────────

    /** A sheet label that is neither a fault nor a service: request origin, stage, visit type. */
    public const LABEL_CONTEXT = 'context';

    /**
     * The kind of ONE legacy sheet label (a single `service_main` / `service_sup` tag).
     *
     * This is a VOCABULARY LOOKUP over the closed 147-label sheet corpus (config/sheet_label_kinds.php),
     * not a third classifier: it types a *word*, never an *event*. Anything the workflow created
     * carries `maintenance_tasks.kind` and must be read from there instead.
     *
     * Order: the explicit legacy map wins, then the DAMAGE catalog, then the SERVICE catalog, then fault
     * — so the default stays exactly what every reader does today and an unmapped label is never dropped.
     * Damage is asked before service because its wordings are the most specific in the corpus.
     *
     * @return string one of MaintenanceTask::KIND_FAULT, KIND_SERVICE, KIND_DAMAGE, self::LABEL_CONTEXT
     */
    public function labelKind(?string $label): string
    {
        $key = $this->norm($label);
        if ($key === '') {
            return MaintenanceTask::KIND_FAULT;
        }

        if (isset($this->labelMap()[$key])) {
            return $this->labelMap()[$key];
        }
        if (array_key_exists($key, $this->damageMap())) {
            return MaintenanceTask::KIND_DAMAGE;
        }
        if (isset($this->serviceMap()[$key])) {
            return MaintenanceTask::KIND_SERVICE;
        }

        return MaintenanceTask::KIND_FAULT;
    }

    /**
     * Does this event kind say anything about the VEHICLE's reliability?
     *
     * The label-grain twin of MaintenanceTask::affectsReliability(), for the sheet corpus where there is
     * no task row to ask. Every reliability-shaped reader over sheet labels goes through this instead of
     * testing `=== fault` inline, so "which kinds count as evidence about the car" stays one decision.
     */
    public function kindAffectsReliability(?string $kind): bool
    {
        return in_array($kind, MaintenanceTask::RELIABILITY_KINDS, true);
    }

    /**
     * Split labels into the FAULT ones only — the shorthand every reliability reader over the sheet
     * corpus actually wants. Damage, service and bookkeeping words all fall away together.
     *
     * @param  array<int,string>  $labels
     * @return array<int,string>
     */
    public function faultLabels(array $labels): array
    {
        return array_values(array_filter(
            $labels,
            fn ($l) => $this->kindAffectsReliability($this->labelKind($l))
        ));
    }

    /**
     * The `maintenance_reasons` rows that name an actual FAULT, as ids ready for a whereIn().
     *
     * The workshop log's reason vocabulary carries a `level` (critical | minor | routine | special) that
     * mirrors the sheet's own "Major / Critical", "Cosmetic / Minor", "Maintenance / Routine" column.
     * That is a PRIORITY axis, and readers were using it as a TYPE axis — "level = routine means it isn't
     * a fault". It does not: the sheet's owners rate `Engine Oil leak` and `Fluid Leaks` as
     * "Maintenance / Routine" because they are cheap and expected, not because they are planned work. Any
     * filter built on `level` therefore dropped real recurring leaks while still counting `Cleaning`.
     *
     * So the reason's NAME is typed through the one label vocabulary instead, and `level` goes back to
     * meaning only what it says (audit M7 / H2). The sheet's own data is never rewritten — it is simply
     * read on the axis it actually describes ([[treat-data-as-source-of-truth]]).
     *
     * @return array<int,int>
     */
    public function faultReasonIds(): array
    {
        if ($this->faultReasonIds === null) {
            $this->faultReasonIds = \App\Models\MaintenanceReason::query()
                ->get(['id', 'reason_en'])
                ->filter(fn ($r) => $this->labelKind($r->reason_en) === MaintenanceTask::KIND_FAULT)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->faultReasonIds;
    }

    /**
     * Is this CATEGORY key planned work rather than failure work?
     *
     * The category-grain sibling of labelKind(). One owner for one question: FaultVocabulary (the sheet /
     * recurrence vocabulary) and anything else that needs to exclude planned upkeep asks here instead of
     * keeping a private 'routine' list that can drift out of step with this one (audit H7).
     */
    public function isServiceCategory(?string $categoryKey): bool
    {
        $key = $this->norm($categoryKey);

        return $key !== '' && in_array($key, array_map(
            [$this, 'norm'],
            (array) config('maintenance_findings.service_ontology_categories', ['routine'])
        ), true);
    }

    /**
     * The Service/Fault lane of an ONTOLOGY CONCEPT, from its name and its category.
     *
     * Category alone is not enough. The ontology files its concepts by SYSTEM ("Tyre Rotation", "Wheel
     * Alignment" and "Tyre Change" live in `tyres`, next to punctures and blowouts, because that is where
     * a technician looks for them) while the type of the work is a different axis entirely. So the
     * authoritative service catalog is consulted first: if a concept names a row in `service_catalog`, it
     * is planned work no matter which system file it lives in. Only then does the category rule apply.
     *
     * This is the ADR's own principle applied to the knowledge layer — the catalog is the source of type
     * (§0.2) — and it is why the fault lane no longer offers "Tyre Change" as a diagnosis.
     */
    public function conceptKind(?string $name, ?string $categoryKey): string
    {
        if ($this->isServiceCategory($categoryKey)) {
            return MaintenanceTask::KIND_SERVICE;
        }

        // NOTE the deliberate absence of an `isDamageCategory()` twin. Damage is typed per CONCEPT, never
        // per category, because the categories that hold most damage also hold real faults: `interior`
        // carries Dashboard fault, Door lock fault, Interior light fault, Seat adjustment fault,
        // Infotainment issue and Water leakage into cabin alongside upholstery tears, and `bodywork`
        // carries Rust / corrosion. A category rule would mislabel all seven as damage and quietly delete
        // them from reliability. See config/damage_catalog.php for the membership test.
        return $this->labelKind($name);
    }

    /**
     * Split a list of legacy sheet labels by kind, preserving order and dropping duplicates.
     * Every input label lands in exactly one bucket, so the three always re-assemble into the input.
     *
     * @param  array<int,string> $labels
     * @return array{fault: array<int,string>, service: array<int,string>, context: array<int,string>}
     */
    public function splitLabels(array $labels): array
    {
        $out = [
            MaintenanceTask::KIND_FAULT   => [],
            MaintenanceTask::KIND_SERVICE => [],
            MaintenanceTask::KIND_DAMAGE  => [],
            self::LABEL_CONTEXT           => [],
        ];

        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $kind = $this->labelKind($label);
            if (! in_array($label, $out[$kind], true)) {
                $out[$kind][] = $label;
            }
        }

        return $out;
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────

    /** normalized legacy label => kind, built once per request from config/sheet_label_kinds.php. */
    private function labelMap(): array
    {
        if ($this->labelMap === null) {
            $this->labelMap = [];
            foreach ([MaintenanceTask::KIND_SERVICE, MaintenanceTask::KIND_DAMAGE, self::LABEL_CONTEXT] as $kind) {
                foreach ((array) config("sheet_label_kinds.{$kind}", []) as $label) {
                    $this->labelMap[$this->norm($label)] = $kind;
                }
            }

            // The routine-service keywords are service BY DEFINITION — they are the list that decides
            // which findings roll a ServiceReminder forward. They were being typed correctly only via the
            // `service_catalog` fallback below, i.e. only when that table happened to be seeded: on a
            // fresh database, or in any test that does not seed catalogs, "oil change" typed as a FAULT.
            // Declaring them here makes the vocabulary answer from config alone and removes a silent
            // dependency on database state.
            foreach (array_keys((array) config('maintenance_findings.routine_service_types', [])) as $keyword) {
                $this->labelMap[$this->norm($keyword)] ??= MaintenanceTask::KIND_SERVICE;
            }
        }

        return $this->labelMap;
    }

    /** Build the attribute payload with exactly the one catalog FK matching the kind set. */
    private function attributes(string $kind, ?int $catalogId, string $source, bool $needsReview = false): array
    {
        return [
            'kind'                  => $kind,
            'fault_catalog_id'      => $kind === MaintenanceTask::KIND_FAULT ? $catalogId : null,
            'service_catalog_id'    => $kind === MaintenanceTask::KIND_SERVICE ? $catalogId : null,
            'inspection_type_id'    => $kind === MaintenanceTask::KIND_INSPECTION ? $catalogId : null,
            'damage_catalog_id'     => $kind === MaintenanceTask::KIND_DAMAGE ? $catalogId : null,
            'classification_source' => $source,
            'needs_review'          => $needsReview,
        ];
    }

    private function resolveCatalogRow(string $kind, ?int $id, ?string $slug)
    {
        $model = match ($kind) {
            MaintenanceTask::KIND_FAULT      => FaultCatalog::class,
            MaintenanceTask::KIND_SERVICE    => ServiceCatalog::class,
            MaintenanceTask::KIND_INSPECTION => InspectionType::class,
            MaintenanceTask::KIND_DAMAGE     => DamageCatalog::class,
        };

        if ($id) {
            return $model::find($id);
        }
        if ($slug) {
            return $model::where('slug', $slug)->first();
        }

        return null;
    }

    private function serviceMap(): array
    {
        if ($this->serviceMap === null) {
            $this->serviceMap = [];
            foreach (ServiceCatalog::query()->get(['id', 'slug', 'name']) as $row) {
                $this->serviceMap[$this->norm($row->name)] = $row->id;
                $this->serviceMap[$this->norm($row->slug)] = $row->id;
            }
        }

        return $this->serviceMap;
    }

    private function faultMap(): array
    {
        if ($this->faultMap === null) {
            $this->faultMap = [];
            foreach (FaultCatalog::query()->get(['id', 'slug', 'name']) as $row) {
                $this->faultMap[$this->norm($row->name)] = $row->id;
                $this->faultMap[$this->norm($row->slug)] = $row->id;
            }
        }

        return $this->faultMap;
    }

    /**
     * normalized name|slug => damage_catalog_id.
     *
     * Built from the DB when the table is available and folded together with config/damage_catalog.php
     * so the vocabulary still answers on an unseeded database — the same lesson the service map learned
     * the hard way (a classifier that silently depends on seed state is a classifier that is wrong in
     * every fresh environment). Config entries never overwrite a real row's id.
     */
    private function damageMap(): array
    {
        if ($this->damageMap === null) {
            $this->damageMap = [];

            try {
                foreach (DamageCatalog::query()->get(['id', 'slug', 'name']) as $row) {
                    $this->damageMap[$this->norm($row->name)] = $row->id;
                    $this->damageMap[$this->norm($row->slug)] = $row->id;
                }
            } catch (\Throwable $e) {
                // table not migrated yet (fresh test DB) — the config fallback below still types correctly
            }

            foreach ((array) config('damage_catalog', []) as $entry) {
                foreach ([$entry['name'] ?? null, $entry['slug'] ?? null] as $key) {
                    $k = $this->norm($key);
                    if ($k !== '' && ! array_key_exists($k, $this->damageMap)) {
                        $this->damageMap[$k] = null;   // known damage wording, no id behind it here
                    }
                }
            }
        }

        return $this->damageMap;
    }

    /** True when the (normalised) symptom carries an explicit failure word — see fault_evidence_keywords. */
    private function hasFaultEvidence(string $symptom): bool
    {
        foreach ((array) config('maintenance_findings.fault_evidence_keywords', []) as $needle) {
            if ($needle !== '' && str_contains($symptom, $this->norm($needle))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which lane does the ontology put this wording in — service or fault? Null when it has no confident
     * opinion (below the score floor, or the vocabulary is not seeded).
     *
     * Deliberately conservative: only a HIGH-confidence single best match counts. A weak or ambiguous hit
     * must fall through to the rules below rather than out-vote them — a wrong confident answer here is
     * worse than no answer, because it would be stamped without needs_review.
     *
     * Never throws: this runs inside a model `creating` hook and during backfill, where the knowledge
     * platform may be unseeded or its tables absent (fresh test databases). Failure = "no opinion".
     */
    private function ontologyKind(string $symptom): ?string
    {
        try {
            $match = app(KeywordOntologyService::class)
                ->resolve($symptom, ['limit' => 1, 'min_score' => self::ONTOLOGY_EVIDENCE_MIN_SCORE])
                ->first();
        } catch (\Throwable $e) {
            return null;   // unseeded / unavailable knowledge platform — fall through to the rules
        }

        if (! $match || ! ($concept = $match['keyword'] ?? null)) {
            return null;
        }

        $serviceCategories = (array) config('maintenance_findings.service_ontology_categories', ['routine']);

        return in_array((string) $concept->category_key, $serviceCategories, true)
            ? MaintenanceTask::KIND_SERVICE
            : MaintenanceTask::KIND_FAULT;
    }

    /** Normalise for matching: lowercase + collapse whitespace (mirrors FaultCause::normalizeKey). */
    private function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $s)));
    }
}
