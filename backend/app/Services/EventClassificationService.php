<?php

namespace App\Services;

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
    /** Cached normalized lookup maps (built once per request). */
    private ?array $serviceMap = null;   // normalized name|slug => service_catalog_id
    private ?array $faultMap = null;     // normalized name|slug => fault_catalog_id
    private ?array $labelMap = null;     // normalized legacy sheet label => service|context

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

        // 3) Ticket/finding context says routine/periodic → service.
        if ($task->category_key === 'routine'
            || ($ticket && $ticket->visit_context === Maintenance::CONTEXT_ROUTINE)
            || ($ticket && $ticket->maintenance_type === Maintenance::TYPE_ROUTINE)
            || ($ticket && $ticket->trigger_reason === Maintenance::TRIGGER_PERIODIC)) {
            return $this->attributes(MaintenanceTask::KIND_SERVICE, null, MaintenanceTask::CLS_RESOLVER);
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
     * Order: the explicit legacy map wins, then an exact catalog name/slug hit, then fault — so the
     * default stays exactly what every reader does today and an unmapped label is never dropped.
     *
     * @return string one of MaintenanceTask::KIND_FAULT, KIND_SERVICE, self::LABEL_CONTEXT
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
        if (isset($this->serviceMap()[$key])) {
            return MaintenanceTask::KIND_SERVICE;
        }

        return MaintenanceTask::KIND_FAULT;
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
            foreach ([MaintenanceTask::KIND_SERVICE, self::LABEL_CONTEXT] as $kind) {
                foreach ((array) config("sheet_label_kinds.{$kind}", []) as $label) {
                    $this->labelMap[$this->norm($label)] = $kind;
                }
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

    /** Normalise for matching: lowercase + collapse whitespace (mirrors FaultCause::normalizeKey). */
    private function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $s)));
    }
}
