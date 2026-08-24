<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCheckEvent;
use App\Models\VehicleCheckRequirement;
use App\Models\VehicleLogEvent;
use App\Support\VehicleCheckCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE CHECK LIFECYCLE — generic, and deliberately ignorant of what a battery is.
 *
 *   SYSTEM DETECTS CONDITION → raise() → PENDING
 *          ↓
 *   inspection ticket opens → attach() → ATTACHED
 *          ↓
 *   inspector answers → recordResult()
 *          ├── the catalog says this result needs no action  → RESOLVED. NO FAULT CREATED.
 *          └── the catalog says it does → decide()
 *                  ├── open_task → linkAction() → ACTION_PENDING → (existing MaintenanceTask workflow)
 *                  │                                    ↓ that task resolves
 *                  │                              completeAction() → RESOLVED
 *                  ├── defer → RESOLVED (deferred)
 *                  └── none  → RESOLVED (declined)
 *
 * ── WHAT THIS SERVICE DOES NOT DO ──────────────────────────────────────────────────────────────
 * It does not execute maintenance. An approved action becomes an ordinary [[MaintenanceTask]] through
 * the SAME path an inspector's own finding takes (MaintenanceTaskService::syncFromFindings), so the
 * repair is dispatched, tracked, invoiced and QC'd by machinery that already works and already has
 * every screen built. This service only orchestrates the question and records the answer.
 *
 * It also contains no `match ($checkType)`. Every domain-specific decision — which results exist,
 * which imply work, which keyword the work becomes — is asked of [[VehicleCheckCatalog]]. Adding
 * coolant is a config block; nothing here changes.
 *
 * ── EVIDENCE ARTIFACT CONTRACT (docs/Evidence-Artifacts.md) ────────────────────────────────────
 *   Class     : F (fact) — every field here is something a person or a rule actually did, with a
 *               timestamp and an actor. Nothing is inferred and no confidence is emitted.
 *   Consumes  : the diagnostic gate's conditions (rule output) · E2 ticket lifecycle · E7 repair
 *               outcomes (to know an action finished).
 *   Produces  : E19 check obligations — raised / answered / unanswered counts per check type, with
 *               recommendation→action lead time and inspector override rate. This is the first
 *               artifact in the platform that can measure whether a system recommendation was ever
 *               LOOKED at, as distinct from whether it produced a repair.
 */
class VehicleCheckService
{
    public function __construct(
        private VehicleLogService $log,
    ) {
    }

    // ── 1 · RAISE ──────────────────────────────────────────────────────────────────────────────

    /**
     * Turn the diagnostic gate's conditions into persistent obligations for one car.
     *
     * IDEMPOTENT BY CONSTRUCTION, at two levels. First we look for an existing OPEN requirement on
     * the same (vehicle, check_key, cycle) and return it untouched. Then, because the daily monitor
     * and a manual rerun can race, the insert itself is guarded by the table's UNIQUE index and a
     * duplicate-key collision is caught and resolved to the row the other writer won with. The
     * scheduler may run a hundred times; the car ends up with one battery check.
     *
     * @param  array<int,array<string,mixed>>  $conditions  DiagnosticGateService::conditionsDue() output
     * @param  array<string,mixed>             $context     ['service' => serviceStatus(), 'source' => …]
     * @return array<int,VehicleCheckRequirement>  every requirement now open for these conditions
     */
    public function raiseFromConditions(Vehicle $vehicle, array $conditions, array $context = []): array
    {
        $service = is_array($context['service'] ?? null) ? $context['service'] : null;
        $source  = $context['source'] ?? VehicleCheckRequirement::SOURCE_MONITOR;
        $raised  = [];

        $conditions = array_values(array_filter($conditions, fn ($c) => is_array($c) && ! empty($c['key'])));

        // SPECIFIC RULES FIRST, AGENDAS SECOND — and the order is load-bearing, not tidiness.
        //
        // A car can trip the battery-age rule AND the post-downtime agenda in the same scan. Both want
        // to ask about the battery, and asking twice is worse than a cosmetic duplicate: the inspector
        // reads two identical "Battery" rows, cannot tell what distinguishes them, and answers the same
        // observation twice — which then counts as two data points in the analytics. Sorting specific
        // before generic lets the expansion below defer to a rule that already asked, with its own
        // measured evidence attached.
        usort($conditions, fn ($a, $b) => (VehicleCheckCatalog::expansionsFor((string) $a['key']) !== [])
            <=> (VehicleCheckCatalog::expansionsFor((string) $b['key']) !== []));

        foreach ($conditions as $condition) {
            $key = (string) $condition['key'];

            // An AGENDA condition ("go check Battery, Fluids and Brakes") is several obligations, not
            // one. Each gets its own answer; they share the parent's cycle so they expire together.
            $expansions = VehicleCheckCatalog::expansionsFor($key);

            if ($expansions !== []) {
                // Anything a specific rule is ALREADY asking about on this car, live right now. The
                // agenda says "go look at the battery"; a battery-age rule that has already raised
                // exactly that question — carrying the real reason and the real numbers — answers the
                // agenda item too. One question, the better-evidenced version of it.
                $alreadyAsked = VehicleCheckRequirement::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->open()
                    ->pluck('check_type')
                    ->all();

                foreach ($expansions as $checkType) {
                    if (in_array($checkType, $alreadyAsked, true)) {
                        continue;
                    }
                    $alreadyAsked[] = $checkType;
                    $raised[] = $this->raise($vehicle, [
                        'check_type' => $checkType,
                        'check_key'  => $key . ':' . $checkType,
                        'rule_key'   => $key,
                        'source'     => $source,
                        'severity'   => $condition['severity'] ?? 'routine',
                        'cycle_key'  => (string) ($condition['cycle_key'] ?? $key),
                        'reason_code'   => 'check.post_downtime',
                        'reason_params' => ['days' => (int) ($condition['days'] ?? 0), 'item' => $checkType],
                        'detail_en'  => $condition['detail'] ?? null,
                        'evidence'   => $this->evidenceFor($condition, $service),
                    ]);
                }

                continue;
            }

            $raised[] = $this->raise($vehicle, [
                'check_type' => VehicleCheckCatalog::typeForCondition($key, $condition['service_type'] ?? null),
                'check_key'  => $key,
                'rule_key'   => $condition['rule_key'] ?? $this->ruleBucket($key),
                'source'     => $source,
                'severity'   => $condition['severity'] ?? 'routine',
                'cycle_key'  => (string) ($condition['cycle_key'] ?? $key),
                'reason_code'   => $this->reasonCodeFor($key, $condition),
                'reason_params' => $this->reasonParamsFor($key, $condition, $service),
                'detail_en'  => $condition['detail'] ?? null,
                'evidence'   => $this->evidenceFor($condition, $service),
            ]);
        }

        return array_values(array_filter($raised));
    }

    /**
     * Raise one obligation, or hand back the one that already covers this cycle.
     *
     * @param array{check_type:string,check_key:string,cycle_key:string,reason_code:string,...} $spec
     */
    public function raise(Vehicle $vehicle, array $spec, ?User $actor = null): VehicleCheckRequirement
    {
        $cycleKey  = (string) $spec['cycle_key'];
        $cycleHash = sha1($cycleKey);
        $checkKey  = (string) $spec['check_key'];
        $seq       = (int) ($spec['cycle_seq'] ?? 1);

        // Already owed for this exact cycle — say nothing, change nothing. This is the ordinary case
        // every single morning until the car is actually serviced.
        $existing = VehicleCheckRequirement::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('check_key', $checkKey)
            ->where('cycle_hash', $cycleHash)
            ->where('cycle_seq', $seq)
            ->first();

        if ($existing) {
            // A closed requirement whose `reraise_after` has passed is a 'monitor' answer coming back
            // round: the same cycle, a genuinely new question. It becomes the next sequence rather
            // than reopening the answered one, so the original answer stays exactly as it was given.
            if (! $existing->isOpen()
                && $existing->reraise_after !== null
                && $existing->reraise_after->isPast()
            ) {
                return $this->raise($vehicle, $spec + ['cycle_seq' => $seq + 1], $actor);
            }

            return $existing;
        }

        try {
            return DB::transaction(function () use ($vehicle, $spec, $cycleKey, $cycleHash, $checkKey, $seq, $actor) {
                $requirement = VehicleCheckRequirement::create([
                    'vehicle_id'      => $vehicle->id,
                    'check_type'      => $spec['check_type'],
                    'check_key'       => $checkKey,
                    'source'          => $spec['source'] ?? VehicleCheckRequirement::SOURCE_MONITOR,
                    'rule_key'        => $spec['rule_key'] ?? null,
                    // Stamped so a resolution recorded today is still readable against the options
                    // that existed when the question was asked, not against a later catalog.
                    'catalog_version' => VehicleCheckCatalog::version(),
                    'recommendation_id' => $spec['recommendation_id'] ?? null,
                    'reason_code'     => $spec['reason_code'],
                    'reason_params'   => $spec['reason_params'] ?? null,
                    'detail_en'       => $spec['detail_en'] ?? null,
                    'severity'        => $spec['severity'] ?? 'routine',
                    'evidence'        => $spec['evidence'] ?? null,
                    'cycle_key'       => $cycleKey,
                    'cycle_hash'      => $cycleHash,
                    'cycle_seq'       => $seq,
                    'status'          => VehicleCheckRequirement::STATUS_PENDING,
                ]);

                $this->event($requirement, VehicleCheckEvent::RAISED, $actor, $spec['reason_code'], [
                    'check_type' => $requirement->check_type,
                    'source'     => $requirement->source,
                    'evidence'   => $requirement->evidence,
                ]);

                $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_RAISED, $actor,
                    VehicleCheckCatalog::label($requirement->check_type) . ' check required'
                    . ($requirement->detail_en ? ' — ' . $requirement->detail_en : ''));

                return $requirement;
            });
        } catch (QueryException $e) {
            // Two writers raced on the UNIQUE index and the other one won. That is the constraint
            // doing its job, not an error: resolve to the row that exists and carry on.
            $winner = VehicleCheckRequirement::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('check_key', $checkKey)
                ->where('cycle_hash', $cycleHash)
                ->where('cycle_seq', $seq)
                ->first();

            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }

    // ── 2 · ATTACH ─────────────────────────────────────────────────────────────────────────────

    /**
     * Put every unanswered obligation for this car onto the inspection that is about to look at it.
     *
     * Attaching is what makes a check the INSPECTOR's to answer rather than a standing note about the
     * car. It is also how a check raised weeks ago (or reconstructed by the backfill) reaches the
     * next person who opens the bonnet, instead of only ever appearing on the ticket that happened to
     * be created in the same scheduler run.
     *
     * @return int how many were newly attached
     */
    public function attachToTicket(Maintenance $ticket, ?User $actor = null): int
    {
        if (! $ticket->vehicle_id) {
            return 0;
        }

        $pending = VehicleCheckRequirement::query()
            ->where('vehicle_id', $ticket->vehicle_id)
            ->unattached()
            ->get();

        $attached = 0;

        foreach ($pending as $requirement) {
            $requirement->forceFill([
                'status'         => VehicleCheckRequirement::STATUS_ATTACHED,
                'maintenance_id' => $ticket->id,
                'attached_at'    => Carbon::now(),
            ])->save();

            $this->event($requirement, VehicleCheckEvent::ATTACHED, $actor, 'check.attached', [
                'maintenance_id'  => $ticket->id,
                'workflow_status' => $ticket->workflow_status,
            ]);

            $attached++;
        }

        return $attached;
    }

    /** Everything this ticket must have answered before its report can be filed. */
    public function openForTicket(Maintenance $ticket)
    {
        return VehicleCheckRequirement::query()
            ->where('maintenance_id', $ticket->id)
            ->open()
            ->orderBy('severity')
            ->orderBy('id')
            ->get();
    }

    // ── 3 · THE INSPECTOR'S ANSWER ─────────────────────────────────────────────────────────────

    /**
     * Record a structured result, and let the catalog decide what follows.
     *
     * THE POINT OF THE WHOLE ENTITY IS THE `false` BRANCH. A result the catalog marks
     * `creates_action => false` resolves the obligation and creates nothing — no fault, no ticket, no
     * finding. "Check the oil" answered "level fine" is a complete, recorded, attributable answer, and
     * the car's history now says an inspector looked on this date and found nothing. Before this
     * existed the only way to close that loop was to log a fault that did not exist.
     *
     * @param  string|null  $decisionCode    required when the result carries an action; ignored otherwise
     * @param  string|null  $findingKeyword  the catalog keyword this becomes, when the check type has
     *                                       none of its own and a human named it instead
     * @return VehicleCheckRequirement the requirement in its new state
     */
    public function recordResult(
        VehicleCheckRequirement $requirement,
        string $resultCode,
        ?string $decisionCode,
        User $actor,
        ?string $findingKeyword = null,
    ): VehicleCheckRequirement {
        $type = $requirement->check_type;

        if (VehicleCheckCatalog::result($type, $resultCode) === null) {
            throw new \InvalidArgumentException(
                "[$resultCode] is not a result offered for a [$type] check."
            );
        }

        // Resolved ONCE, here, and stored. Re-deriving it later would read a catalog that may have
        // been retuned since, and the fault this obligation actually became is a historical fact.
        $keyword = VehicleCheckCatalog::findingKeyword($type, $resultCode) ?? $findingKeyword;

        return DB::transaction(function () use ($requirement, $resultCode, $decisionCode, $actor, $type, $keyword) {
            $requirement->forceFill([
                'status'          => VehicleCheckRequirement::STATUS_INSPECTED,
                'result_code'     => $resultCode,
                'finding_keyword' => $keyword,
                'inspected_by'    => $actor->id,
                'inspected_at'    => Carbon::now(),
            ])->save();

            $this->event($requirement, VehicleCheckEvent::INSPECTED, $actor, 'check.result.' . $resultCode, [
                'check_type'  => $type,
                'result_code' => $resultCode,
            ]);

            $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_INSPECTED, $actor,
                VehicleCheckCatalog::label($type) . ' checked — result: '
                . (VehicleCheckCatalog::result($type, $resultCode)['label'] ?? $resultCode));

            // ── No action needed. Resolve, create nothing, and say so plainly. ──
            if (! VehicleCheckCatalog::createsAction($type, $resultCode)) {
                $days = VehicleCheckCatalog::reraiseAfterDays($type, $resultCode);

                return $this->resolve(
                    $requirement,
                    VehicleCheckCatalog::resolutionCode($type, $resultCode)
                        ?? VehicleCheckRequirement::RESOLUTION_CONFIRMED_OK,
                    $actor,
                    // A 'monitor' answer is not a permanent all-clear — it buys a defined amount of
                    // time, after which the same cycle may legitimately ask again.
                    $days !== null ? Carbon::now()->addDays($days) : null,
                );
            }

            // ── Action needed: the inspector must also say what to do about it. ──
            if ($decisionCode === null || $decisionCode === '') {
                // Left at INSPECTED rather than rolled back: the observation is real and worth
                // keeping even when the decision has not been made. The submit gate refuses to let a
                // report be filed in this state, so it cannot become a quiet dead end.
                return $requirement->refresh();
            }

            return $this->decide($requirement, $decisionCode, $actor);
        });
    }

    /**
     * Apply the decision that follows an action-bearing result.
     *
     * `open_task` does NOT create a MaintenanceTask here. It marks the requirement as owing one and
     * hands the keyword back to the report pipeline, which promotes findings into faults through
     * MaintenanceTaskService exactly as it does for an inspector's own observations. One creation
     * path for faults, not two — see [[ticket-cost-journey]] on why a second write path is how
     * entities start disagreeing.
     */
    public function decide(VehicleCheckRequirement $requirement, string $decisionCode, User $actor): VehicleCheckRequirement
    {
        $type   = $requirement->check_type;
        $result = (string) $requirement->result_code;
        $action = VehicleCheckCatalog::decisionAction($type, $result, $decisionCode);

        return DB::transaction(function () use ($requirement, $decisionCode, $actor, $action, $type, $result) {
            $requirement->forceFill([
                'decision_code' => $decisionCode,
                'decided_by'    => $actor->id,
                'decided_at'    => Carbon::now(),
            ])->save();

            $this->event($requirement, VehicleCheckEvent::DECIDED, $actor, 'check.decision.' . $decisionCode, [
                'result_code'   => $result,
                'decision_code' => $decisionCode,
                'action'        => $action,
            ]);

            $decisionLabel = VehicleCheckCatalog::decision($type, $result, $decisionCode)['label'] ?? $decisionCode;
            $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_DECIDED, $actor,
                VehicleCheckCatalog::label($type) . ' — decision: ' . $decisionLabel);

            return match ($action) {
                // Awaiting a real maintenance action; linkAction() closes the loop once it exists.
                'open_task' => tap($requirement, fn (VehicleCheckRequirement $r) => $r->forceFill([
                    'status' => VehicleCheckRequirement::STATUS_ACTION_PENDING,
                ])->save()),

                'defer' => $this->resolve($requirement, VehicleCheckRequirement::RESOLUTION_DEFERRED, $actor),

                default => $this->resolve($requirement, VehicleCheckRequirement::RESOLUTION_DECLINED, $actor),
            };
        });
    }

    // ── 4 · THE EXISTING MAINTENANCE WORKFLOW OWNS IT FROM HERE ────────────────────────────────

    /**
     * Bind this requirement to the real maintenance action that will satisfy it.
     *
     * Called once the report pipeline has promoted the requirement's keyword into a MaintenanceTask.
     * From this moment the ordinary workflow — dispatch, garage, repair, QC, close — owns the outcome,
     * and the requirement simply watches for that task to finish.
     */
    public function linkAction(VehicleCheckRequirement $requirement, MaintenanceTask $task, ?User $actor = null): VehicleCheckRequirement
    {
        if ($requirement->action_id !== null) {
            return $requirement;   // already bound — a re-filed report must not fork the action
        }

        $requirement->forceFill([
            'status'            => VehicleCheckRequirement::STATUS_ACTION_PENDING,
            'action_type'       => MaintenanceTask::class,
            'action_id'         => $task->id,
            'action_created_at' => Carbon::now(),
        ])->save();

        $this->event($requirement, VehicleCheckEvent::ACTION_CREATED, $actor, 'check.action_created', [
            'action_type'    => MaintenanceTask::class,
            'action_id'      => $task->id,
            'symptom'        => $task->symptom,
            'maintenance_id' => $task->maintenance_id,
        ]);

        $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_DECIDED, $actor,
            'Maintenance action opened for ' . VehicleCheckCatalog::label($requirement->check_type)
            . ' — "' . $task->symptom . '"');

        return $requirement;
    }

    /**
     * Bind every requirement awaiting an action on this ticket to the fault that now satisfies it.
     *
     * Hooked into MaintenanceTaskService::syncFromFindings — the SINGLE place findings become faults —
     * rather than called from each of its callers, for the same reason bindRequiredParts() is hooked
     * there: a traceability loop that has to be remembered at three call sites is a loop that will be
     * open at the fourth.
     *
     * Matching is by symptom text against the keyword the catalog said the result becomes, which is
     * the very string the report pipeline injected. Anything unmatched is left ACTION_PENDING and
     * visible, never quietly resolved — an approved repair with no fault behind it is a finding about
     * the platform, not something to paper over.
     *
     * @return int how many were newly bound
     */
    public function bindActions(Maintenance $ticket, ?User $actor = null): int
    {
        $pending = VehicleCheckRequirement::query()
            ->where('maintenance_id', $ticket->id)
            ->where('status', VehicleCheckRequirement::STATUS_ACTION_PENDING)
            ->whereNull('action_id')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $tasks = $ticket->tasks()->get();
        $bound = 0;

        foreach ($pending as $requirement) {
            // The keyword resolved and stored when the answer was recorded — not re-derived from a
            // catalog that may have been retuned since.
            $keyword = $requirement->finding_keyword;

            $task = $tasks->first(fn (MaintenanceTask $t) => $keyword !== null
                && mb_strtolower(trim((string) $t->symptom)) === mb_strtolower(trim($keyword)));

            if (! $task) {
                continue;
            }

            $this->linkAction($requirement, $task, $actor);
            $bound++;
        }

        return $bound;
    }

    /**
     * The linked action finished, so the obligation is finally discharged.
     *
     * Hooked off the EXISTING task-resolution path rather than duplicating it: whatever route a fault
     * takes to done (repaired, cancelled, marked incorrect), the requirement follows it and records
     * which. Best-effort — a check bookkeeping failure must never sink a real repair sign-off.
     */
    public function completeActionFor(MaintenanceTask $task, ?User $actor = null): int
    {
        $requirements = VehicleCheckRequirement::query()
            ->where('action_type', MaintenanceTask::class)
            ->where('action_id', $task->id)
            ->where('status', VehicleCheckRequirement::STATUS_ACTION_PENDING)
            ->get();

        $closed = 0;

        foreach ($requirements as $requirement) {
            try {
                $requirement->forceFill(['action_completed_at' => Carbon::now()])->save();

                $this->event($requirement, VehicleCheckEvent::ACTION_COMPLETED, $actor, 'check.action_completed', [
                    'action_id'   => $task->id,
                    'task_status' => $task->status,
                ]);

                // How the fault ENDED decides how the check ended. A cancelled or mis-diagnosed fault
                // did not repair anything, and recording it as `repaired` would quietly inflate every
                // "recommendation led to a fix" number this feature exists to make trustworthy.
                $resolution = in_array($task->status, [MaintenanceTask::STATUS_CANCELLED, MaintenanceTask::STATUS_NOT_FOUND], true)
                    ? VehicleCheckRequirement::RESOLUTION_DECLINED
                    : VehicleCheckRequirement::RESOLUTION_REPAIRED;

                $this->resolve($requirement, $resolution, $actor);
                $closed++;
            } catch (\Throwable $e) {
                report($e);   // never let check bookkeeping break a repair sign-off
            }
        }

        return $closed;
    }

    // ── 5 · ENDINGS ────────────────────────────────────────────────────────────────────────────

    public function resolve(
        VehicleCheckRequirement $requirement,
        string $resolutionCode,
        ?User $actor = null,
        ?Carbon $reraiseAfter = null,
    ): VehicleCheckRequirement {
        $requirement->forceFill([
            'status'          => VehicleCheckRequirement::STATUS_RESOLVED,
            'resolution_code' => $resolutionCode,
            'resolved_at'     => Carbon::now(),
            'reraise_after'   => $reraiseAfter,
        ])->save();

        $this->event($requirement, VehicleCheckEvent::RESOLVED, $actor, 'check.resolved.' . $resolutionCode, [
            'resolution_code' => $resolutionCode,
            'reraise_after'   => $reraiseAfter?->toIso8601String(),
        ]);

        $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_RESOLVED, $actor,
            VehicleCheckCatalog::label($requirement->check_type) . ' check resolved — ' . $resolutionCode);

        return $requirement->refresh();
    }

    /**
     * Withdraw a requirement for a legitimate SYSTEM reason — never as a way of making an awkward
     * check go away. The reason code is mandatory and lands on the event, so a cancelled check always
     * says who cancelled it and why.
     */
    public function cancel(VehicleCheckRequirement $requirement, string $reasonCode, ?User $actor = null): VehicleCheckRequirement
    {
        $requirement->forceFill([
            'status'          => VehicleCheckRequirement::STATUS_CANCELLED,
            'resolution_code' => 'cancelled',
            'resolved_at'     => Carbon::now(),
        ])->save();

        $this->event($requirement, VehicleCheckEvent::CANCELLED, $actor, $reasonCode);

        $this->timeline($requirement, VehicleLogEvent::EVENT_CHECK_RESOLVED, $actor,
            VehicleCheckCatalog::label($requirement->check_type) . ' check cancelled — ' . $reasonCode);

        return $requirement->refresh();
    }

    /**
     * Retire obligations nobody ever answered, once their evidence is too old to act on.
     *
     * EXPIRED, never deleted. "This car had a battery check raised in May and no one ever looked at
     * it" is precisely the finding this whole mechanism was built to surface; erasing the row would
     * restore the blindness it replaced.
     */
    public function expireStale(): int
    {
        $cutoff = Carbon::now()->subDays(VehicleCheckCatalog::staleAfterDays());

        $stale = VehicleCheckRequirement::query()
            ->open()
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $requirement) {
            $requirement->forceFill([
                'status'          => VehicleCheckRequirement::STATUS_EXPIRED,
                'resolution_code' => 'expired',
                'resolved_at'     => Carbon::now(),
            ])->save();

            $this->event($requirement, VehicleCheckEvent::EXPIRED, null, 'check.expired', [
                'raised_at'  => $requirement->created_at?->toIso8601String(),
                'age_days'   => $requirement->created_at?->diffInDays(Carbon::now()),
                'never_seen' => ! $requirement->wasInspected(),
            ]);
        }

        return $stale->count();
    }

    // ── Presentation ───────────────────────────────────────────────────────────────────────────

    /**
     * One requirement shaped for the Decide step: the question, why it was asked, and the exact
     * options. Everything the inspector needs to answer in taps, and nothing he has to type.
     */
    public function present(VehicleCheckRequirement $r): array
    {
        return [
            'id'             => $r->id,
            'check_type'     => $r->check_type,
            'check_key'      => $r->check_key,
            'label'          => VehicleCheckCatalog::label($r->check_type),
            'label_ar'       => VehicleCheckCatalog::labelAr($r->check_type),
            'severity'       => $r->severity,
            'status'         => $r->status,
            'is_open'        => $r->isOpen(),
            'source'         => $r->source,
            // Codes + params so the sentence composes in Arabic; the gate's English rides along as
            // *_en, explicitly legacy and never the only copy ([[reason-code-contract]]).
            'reason_code'    => $r->reason_code,
            'reason_params'  => $r->reason_params,
            'detail_en'      => $r->detail_en,
            'evidence'       => $r->evidence,
            'raised_at'      => $r->created_at?->toIso8601String(),
            'result_options' => VehicleCheckCatalog::resultOptions($r->check_type),
            'result_code'    => $r->result_code,
            'decision_code'  => $r->decision_code,
            'inspected_at'   => $r->inspected_at?->toIso8601String(),
            'inspected_by'   => $r->inspector?->name,
            'resolution_code' => $r->resolution_code,
            'resolved_at'    => $r->resolved_at?->toIso8601String(),
            'action'         => $r->action_id ? [
                'type' => $r->action_type,
                'id'   => $r->action_id,
                'completed_at' => $r->action_completed_at?->toIso8601String(),
            ] : null,
            // Where this line came from, in one sentence, so the panel can always answer "why am I
            // being asked this?" without a round trip ([[traceability-visibility-requirement]]).
            'origin'         => $this->originSentence($r),
        ];
    }

    /** The full audit chain for one requirement — what the timeline and the drawer both render. */
    public function history(VehicleCheckRequirement $r): array
    {
        return $r->events()->with('actor:id,name')->get()->map(fn (VehicleCheckEvent $e) => [
            'event'       => $e->event,
            'reason_code' => $e->reason_code,
            'payload'     => $e->payload,
            'actor'       => $e->actor?->name,
            'at'          => $e->occurred_at?->toIso8601String(),
        ])->all();
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────

    private function event(
        VehicleCheckRequirement $requirement,
        string $event,
        ?User $actor = null,
        ?string $reasonCode = null,
        array $payload = [],
    ): VehicleCheckEvent {
        return VehicleCheckEvent::create([
            'vehicle_check_requirement_id' => $requirement->id,
            'event'       => $event,
            'actor_id'    => $actor?->id,
            'reason_code' => $reasonCode,
            'payload'     => $payload !== [] ? $payload : null,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Mirror a lifecycle step onto the vehicle's own timeline.
     *
     * The check trail above is the system of record; this makes the chain visible where people
     * actually investigate a car, next to the dispatch, the repair and the invoice, so six months
     * later the story reads end to end without opening a second screen. Best-effort, like every other
     * write to that log.
     */
    private function timeline(VehicleCheckRequirement $r, string $eventType, ?User $actor, string $description): void
    {
        $vehicle = $r->relationLoaded('vehicle') ? $r->vehicle : Vehicle::find($r->vehicle_id);
        if (! $vehicle) {
            return;
        }

        $this->log->recordVehicle($vehicle, $eventType, $actor, [
            'description' => $description,
            'source_tag'  => Maintenance::FINDING_INSPECTOR,
            'meta'        => [
                'check_requirement_id' => $r->id,
                'check_type'           => $r->check_type,
                'check_key'            => $r->check_key,
                'status'               => $r->status,
                'reason_code'          => $r->reason_code,
                'reason_params'        => $r->reason_params,
                'result_code'          => $r->result_code,
                'decision_code'        => $r->decision_code,
                'resolution_code'      => $r->resolution_code,
                'maintenance_id'       => $r->maintenance_id,
                'action_id'            => $r->action_id,
            ],
        ]);
    }

    /** The gate rule bucket a condition key belongs to — mirrors InspectionEngineService::ruleBucket. */
    private function ruleBucket(string $key): ?string
    {
        return match (true) {
            $key === 'oil_change'                 => 'oil',
            str_starts_with($key, 'reminder:')    => 'reminder',
            $key === 'battery'                    => 'battery',
            $key === 'downtime'                   => 'downtime',
            $key === 'inactivity'                 => 'inactivity',
            default                               => null,
        };
    }

    /** Reason CODE for a condition — never an English sentence ([[reason-code-contract]]). */
    private function reasonCodeFor(string $key, array $condition): string
    {
        return match (true) {
            $key === 'oil_change'              => 'check.oil_service_due',
            $key === 'battery'                 => 'check.battery_past_life',
            str_starts_with($key, 'reminder:') => 'check.reminder_overdue',
            $key === 'inactivity'              => 'check.inactive',
            default                            => 'check.condition_due',
        };
    }

    /** The measured numbers behind that code, so the UI composes the sentence in either language. */
    private function reasonParamsFor(string $key, array $condition, ?array $service): array
    {
        $params = array_filter([
            'label' => $condition['label'] ?? null,
            'axis'  => $condition['axis'] ?? null,
            'days'  => isset($condition['days']) ? (int) $condition['days'] : null,
        ], fn ($v) => $v !== null);

        if ($key === 'oil_change' && $service) {
            $params += array_filter([
                'overdue_km'  => isset($service['overdue_km']) ? (int) $service['overdue_km'] : null,
                'interval_km' => isset($service['interval']) ? (int) $service['interval'] : null,
                'current_km'  => isset($service['current']) ? (int) $service['current'] : null,
            ], fn ($v) => $v !== null);
        }

        return $params;
    }

    /** Everything the rule knew at detection, frozen so it cannot drift once the car is serviced. */
    private function evidenceFor(array $condition, ?array $service): array
    {
        return array_filter([
            'condition_key' => $condition['key'] ?? null,
            'directive'     => $condition['directive'] ?? null,
            'axis'          => $condition['axis'] ?? null,
            'days'          => $condition['days'] ?? null,
            'checklist'     => $condition['checklist'] ?? null,
            'service'       => $service ? array_filter([
                'current_km'  => $service['current'] ?? null,
                'interval_km' => $service['interval'] ?? null,
                'overdue_km'  => $service['overdue_km'] ?? null,
                'next_due_at' => $service['next_due_at'] ?? null,
                'status'      => $service['status'] ?? null,
            ], fn ($v) => $v !== null) : null,
            'detected_at'   => Carbon::now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** One plain sentence naming where this obligation came from. */
    private function originSentence(VehicleCheckRequirement $r): string
    {
        return match ($r->source) {
            VehicleCheckRequirement::SOURCE_MONITOR => 'Raised by the Proactive Diagnostic Monitor from this '
                . 'car\'s own service state (rule: ' . ($r->rule_key ?: 'condition') . ').',
            VehicleCheckRequirement::SOURCE_CAPABILITY => 'Raised from an Intelligence recommendation'
                . ($r->recommendation_id ? ' (#' . $r->recommendation_id . ')' : '') . '.',
            VehicleCheckRequirement::SOURCE_BACKFILL => 'Reconstructed from the trigger snapshot this '
                . 'inspection request was created with, before check requirements existed.',
            default => 'Raised by hand.',
        };
    }
}
