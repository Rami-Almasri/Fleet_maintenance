<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceRequiredPart;
use App\Models\MaintenanceTask;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The inspector's required parts, and the Part Requests they raise.
 *
 * The inspector reports a TECHNICAL need: "this repair will need these parts". He is not doing
 * procurement — he sets no price, picks no supplier, and approves no spend. But his report is enough to
 * START procurement, so it does: {@see record()} writes the technical lines, and {@see raiseRequests()}
 * immediately turns them into real {@see PartRequest} rows that land in the parts team's queue.
 *
 * Both run inside submitReport, so filing an inspection with required parts reaches procurement in one
 * step. There is deliberately NO approval gate in between: making the parts team wait on a maintenance
 * sign-off only delayed sourcing, and the request's own lifecycle (Requested → Approved → Purchased →
 * Delivered → Installed) already contains every approval that actually matters. See
 * [[inspection-required-parts-split]].
 *
 * The `maintenance_required_parts` rows are kept for TRACEABILITY — they are what the inspector actually
 * said, preserved separately from what procurement then did about it — and linked many-to-many to the
 * requests they raised.
 *
 * Required parts are recorded while faults are still `findings` JSON, so a line carries a `finding_key`
 * until the fault row exists. Binding it to that fault is owned by
 * {@see MaintenanceTaskService::bindRequiredParts()}, which runs automatically whenever findings are
 * promoted to tasks — so the traceability loop closes on every path, with no call-site to remember.
 */
class MaintenanceRequiredPartService
{
    public function __construct(
        private PartWorkflowService $parts,
        private VehicleLogService $log,
        private NotificationScanner $notifier,
        private PartCatalogMatcher $catalogMatcher,
    ) {}

    // ───────────────────────────── 1. inspector: record the requirement ─────────────────────────────

    /**
     * Record the inspector's required parts for a ticket. Additive by design: a report can be filed more
     * than once and lines already actioned by the coordinator must never be silently rewritten, so this
     * only ever ADDS. An identical still-pending line (same part, same fault) is treated as a re-submission
     * of the same requirement and updated in place rather than duplicated.
     *
     * @param array<int, array{part_name:string, quantity?:float|null, priority?:string|null, notes?:string|null, finding?:string|null}> $lines
     * @return int how many lines were newly recorded
     */
    public function record(Maintenance $ticket, array $lines, User $actor): int
    {
        $created = 0;

        DB::transaction(function () use ($ticket, $lines, $actor, &$created) {
            // Everything still pending on this ticket, keyed by part+fault, so a repeat submission updates
            // instead of piling up duplicates.
            $pending = $ticket->requiredParts()->pending()->get()
                ->keyBy(fn (MaintenanceRequiredPart $p) => $this->lineKey($p->part_name, $p->finding_key));

            foreach ($lines as $line) {
                $partName = trim((string) ($line['part_name'] ?? ''));
                if ($partName === '') {
                    continue; // an empty row in the UI is not a requirement
                }

                // Which catalog part this line means. An explicit id (the picker) always wins and is
                // recorded as 'manual' — a person chose it. Without one we fall back to matching the
                // typed text, which is exact-only and may legitimately find nothing.
                [$catalogId, $matchedBy] = $this->resolveCatalog($line, $partName);

                $findingKey = MaintenanceRequiredPart::findingKey($line['finding'] ?? null);
                $quantity   = $this->quantity($line['quantity'] ?? null) ?? 1.0;
                $priority   = in_array(($line['priority'] ?? null), MaintenanceRequiredPart::PRIORITIES, true)
                    ? $line['priority']
                    : MaintenanceRequiredPart::PRIORITY_NORMAL;
                $notes      = $this->clean($line['notes'] ?? null);

                if ($existing = $pending->get($this->lineKey($partName, $findingKey))) {
                    $existing->fill([
                        'quantity' => $quantity, 'priority' => $priority, 'notes' => $notes,
                        // Re-submitting can only ever IMPROVE the link: a line already tied to a
                        // catalog part keeps that tie rather than losing it to a weaker re-match.
                        'component_catalog_id' => $existing->component_catalog_id ?: $catalogId,
                        'catalog_matched_by'   => $existing->component_catalog_id ? $existing->catalog_matched_by : $matchedBy,
                    ])->save();
                    continue;
                }

                $row = MaintenanceRequiredPart::create([
                    'maintenance_id'    => $ticket->id,
                    'vehicle_id'        => $ticket->vehicle_id,
                    'finding_key'       => $findingKey,
                    'finding_text'      => $this->clean($line['finding'] ?? null),
                    // Bind straight away when the fault is already a first-class task (a part added while
                    // the car is mid-repair); otherwise bindToTasks() resolves it after findings promotion.
                    'maintenance_task_id' => $findingKey ? $this->taskIdFor($ticket, $findingKey) : null,
                    'part_name'         => $partName,
                    // The structured answer, beside the inspector's own words (which stay verbatim).
                    'component_catalog_id' => $catalogId,
                    'catalog_matched_by'   => $matchedBy,
                    'notes'             => $notes,
                    'quantity'          => $quantity,
                    'priority'          => $priority,
                    'status'            => MaintenanceRequiredPart::STATUS_PENDING,
                    'recorded_by'       => $actor->id,
                    'recorded_by_name'  => $actor->name ?: $actor->email,
                    'recorded_at'       => Carbon::now(),
                ]);

                $pending->put($this->lineKey($partName, $findingKey), $row);
                $created++;
            }
        });

        if ($created > 0) {
            // One log line for the batch — the inspector recorded a technical requirement, nothing was ordered.
            $this->logVehicle($ticket, $actor, VehicleLogEvent::EVENT_PART_REQUIRED, [
                'description' => "Inspection recorded {$created} required part(s) — awaiting the coordinator's procurement decision",
                'meta'        => ['required_parts' => $created, 'stage' => 'inspection'],
            ]);
        }

        return $created;
    }

    // ─────────────── 2. raise the Part Requests — automatic, at report time ───────────────

    /**
     * Turn every still-pending required part on this ticket into a real {@see PartRequest}, immediately.
     *
     * Called straight after {@see record()} inside submitReport, so the parts team sees the requirement the
     * moment the inspection is filed. No garage is needed and none is assumed: a request opens in
     * `requested`, and every sourcing decision — do we buy it, from whom, for how much, or does the garage
     * supply it after all — is made inside the request's own lifecycle, where it belongs. A request that
     * turns out to be unnecessary is rejected there, not gate-kept here.
     *
     * Each line becomes its own request via PartWorkflowService, so classification, the duplicate engine,
     * the vehicle log and notifications behave exactly as they do for any other request. Best-effort per
     * line: one bad line must never cost the inspector his whole report.
     *
     * @return array<int, PartRequest> the requests actually raised
     */
    public function raiseRequests(Maintenance $ticket, User $actor): array
    {
        $lines = $ticket->requiredParts()->pending()->get();
        if ($lines->isEmpty()) {
            return [];
        }

        $requests = [];

        foreach ($lines as $line) {
            try {
                DB::transaction(function () use ($line, $ticket, $actor, &$requests) {
                    $req = $this->parts->createRequest([
                        'source'              => PartRequest::SOURCE_GARAGE,
                        'vehicle_id'          => $line->vehicle_id,
                        'maintenance_id'      => $ticket->id,
                        'maintenance_task_id' => $line->maintenance_task_id,
                        'part_name'           => $line->part_name,
                        'category_key'        => $line->task?->category_key,
                        'quantity'            => $line->quantity,
                        // The inspector's requirement IS the reason procurement exists — carried over
                        // verbatim so the parts team reads the fault, not a re-typed summary of it.
                        'reason'              => $this->reasonFor($line),
                        'notes'               => $line->notes,
                    ], $actor);

                    // Traceability, both directions: the technical line and the request it raised.
                    $req->requiredParts()->syncWithoutDetaching([$line->id]);

                    $line->fill([
                        'status'           => MaintenanceRequiredPart::STATUS_REQUESTED,
                        'actioned_by'      => $actor->id,
                        'actioned_by_name' => $actor->name ?: $actor->email,
                        'actioned_at'      => Carbon::now(),
                    ])->save();

                    $requests[] = $req;
                });
            } catch (\Throwable $e) {
                // The requirement is already recorded; a failed hand-off to procurement is worth reporting
                // but never worth failing the inspection over. The line stays pending and visible.
                report($e);
            }
        }

        if ($requests !== []) {
            $this->notifyProcurement($ticket, $requests, $actor);
        }

        return $requests;
    }

    /**
     * Tell the parts team that an inspection just raised requirements. One notification for the batch —
     * a five-part inspection is one event to them, not five.
     */
    private function notifyProcurement(Maintenance $ticket, array $requests, User $actor): void
    {
        try {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $plate   = $vehicle?->plate_no ?: ('#' . $ticket->id);
            $names   = collect($requests)->pluck('part_name')->take(3)->implode(', ');
            $more    = count($requests) - min(3, count($requests));
            $n       = count($requests);

            $this->notifier->notifyByAnyPermission(['parts.view', 'parts.purchase', 'parts.request'], [
                'type'     => 'part_required_from_inspection',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => "🔧 {$n} part(s) needed · {$plate}",
                'body'     => "{$actor->name}'s inspection of {$plate} needs: {$names}"
                              . ($more > 0 ? " +{$more} more" : '') . '. Ready for sourcing.',
                'url'      => '/parts?maintenance_id=' . $ticket->id,
                'key'      => 'part_required:' . $ticket->id . ':' . $requests[0]->id,
                'icon'     => 'wrench',
                'meta'     => [
                    'ticket_id'  => $ticket->id,
                    'vehicle_id' => $ticket->vehicle_id,
                    'plate'      => $vehicle?->plate_no,
                    'request_ids' => collect($requests)->pluck('id')->all(),
                ],
            ], $actor->id);
        } catch (\Throwable $e) {
            report($e); // a notification failure must never fail the report
        }
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** The WHY carried into procurement: the fault it serves, plus the inspector's own note. */
    private function reasonFor(MaintenanceRequiredPart $line): string
    {
        // Prefer the promoted fault's symptom, then the inspector's verbatim wording. finding_key is a
        // lowercased matching key, never user-facing.
        $fault  = $line->task?->symptom ?: $line->finding_text;
        $reason = $fault
            ? "Required for fault: {$fault}"
            : "Required by inspection on ticket #{$line->maintenance_id}";

        if ($line->notes) {
            $reason .= ' — ' . $line->notes;
        }

        return mb_substr($reason, 0, 1000);
    }

    /** Identity of a requirement line: the same part for the same fault is the same requirement. */
    /**
     * Which catalog part a line refers to, and how we know.
     *
     * Order of trust: an explicit id from the picker ('manual' — a human chose it) beats matching
     * the typed text, which is exact-only and refuses to guess (see PartCatalogMatcher).
     *
     * THE CUTOVER. The long-term rule is that every required part references the catalog rather
     * than free text. Enforcing that the day the column appeared would have broken the live
     * inspection flow, where reports are still filed with typed names and no picker exists yet — an
     * inspector mid-report would simply be unable to submit. So enforcement is behind
     * `parts.require_catalog_link`, off by default: today an unresolved line is recorded with a
     * null link and listed by `parts:link-required`; the day the picker ships, the flag goes on and
     * a line that names no known part is refused at the door.
     *
     * @return array{0:int|null, 1:string|null} [catalog id, how it was matched]
     */
    private function resolveCatalog(array $line, string $partName): array
    {
        $explicit = $line['component_catalog_id'] ?? null;

        if ($explicit && ComponentCatalog::whereKey($explicit)->exists()) {
            return [(int) $explicit, 'manual'];
        }

        $hit = $this->catalogMatcher->resolve($partName);

        if ($hit['catalog_id'] === null && config('parts.require_catalog_link', false)) {
            throw ValidationException::withMessages([
                'required_parts' => sprintf(
                    '"%s" does not match any part in the catalog. Pick the part from the list, or add it to the catalog first.',
                    $partName
                ),
            ]);
        }

        return [$hit['catalog_id'], $hit['catalog_id'] ? $hit['matched_by'] : null];
    }

    private function lineKey(string $partName, ?string $findingKey): string
    {
        return strtolower(trim($partName)) . '|' . ($findingKey ?? '');
    }

    /** Does a first-class fault already exist for this finding? (A part added mid-repair binds at once.) */
    private function taskIdFor(Maintenance $ticket, string $findingKey): ?int
    {
        return $ticket->tasks()->get()
            ->first(fn (MaintenanceTask $t) => MaintenanceRequiredPart::findingKey($t->symptom) === $findingKey)?->id;
    }

    /** A positive quantity, or null when nothing usable was supplied (callers fall back to 1 / the line's own). */
    private function quantity(mixed $raw): ?float
    {
        return (is_numeric($raw) && (float) $raw > 0) ? (float) $raw : null;
    }

    private function clean(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s === '' ? null : $s;
    }

    /** Best-effort vehicle log entry — an audit failure must never fail the inspection. */
    private function logVehicle(Maintenance $ticket, User $actor, string $event, array $payload): void
    {
        try {
            $this->log->record($ticket, $event, $actor, $payload);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
