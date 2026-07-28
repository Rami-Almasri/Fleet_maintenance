<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\MaintenanceMedia;
use App\Models\Vehicle;
use App\Services\MaintenanceCheckpointService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Maintenance Checkpoint — the progress-tracking API. Responsible users (Waleed/Abdullah, or a ticket's
 * assigned owners) file dated updates with evidence; managers set who's responsible and the promised
 * completion date. Route middleware only enforces `maintenance.view`; the finer submit/manage authority
 * (permission OR assigned-responsible OR admin) is enforced here via MaintenanceCheckpointService, since
 * "an assigned owner may report on their own job" can't be expressed as a single permission gate.
 *
 * See [[maintenance-workflow-engine]].
 */
class MaintenanceCheckpointController extends Controller
{
    public function __construct(private MaintenanceCheckpointService $checkpoints)
    {
    }

    /** A ticket's checkpoint timeline (newest first) + its live monitoring state + responsible users. */
    public function index(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $items = $ticket->checkpoints()->with('media')->get()
                ->map(fn ($c) => $this->checkpointArray($c))->all();

            return ResponseHelper::SuccessResponse([
                'ticket_id'     => $ticket->id,
                'monitor'       => $this->checkpoints->monitorState($ticket),
                'responsibles'  => $this->checkpoints->recipientsFor($ticket)
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                'assigned'      => $ticket->responsibles()->pluck('users.id')->all(),
                // The ticket's faults — so the modal can surface Repair Intelligence per fault (read-only,
                // the same panel used in the drawer). Excludes planned service / inspection tasks.
                'faults'        => $ticket->tasks()
                    ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', 'fault'))
                    ->get(['id', 'symptom'])
                    ->map(fn ($x) => ['id' => $x->id, 'symptom' => $x->symptom])->all(),
                'checkpoints'   => $items,
                'can_submit'    => optional(request()->user()) ? $this->checkpoints->canSubmit(request()->user(), $ticket) : false,
                'can_manage'    => optional(request()->user()) ? $this->checkpoints->canManage(request()->user()) : false,
            ], 'Checkpoints retrieved', 200);
        });
    }

    /**
     * File a progress update (multipart): the required new EXPECTED COMPLETION DATE, the REASON it moved
     * (required only when the date actually changes), a workshop status, a progress note, and any number
     * of photo/video files. One save. No manual "outcome" — the status is derived downstream from the ETA.
     */
    public function store(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $user = $request->user();
            if (! $user || ! $this->checkpoints->canSubmit($user, $ticket)) {
                return ResponseHelper::FailureResponse(null, 'You are not a responsible user for this ticket.', 403);
            }

            $data = $request->validate([
                // The new ETA — the point of the update. Always required.
                'next_expected_date' => ['required', 'date'],
                'status'             => ['nullable', Rule::in(MaintenanceCheckpoint::STATUSES)],
                // WHY the ETA moved — validated against the catalogue; the required-when-it-changes rule is
                // enforced below (it depends on the ticket's current ETA, which a static rule can't see).
                'delay_reason'       => ['nullable', Rule::in(MaintenanceCheckpoint::DELAY_REASONS)],
                // Free-text explanation is REQUIRED only when the reason is "other".
                'delay_reason_other' => ['nullable', 'required_if:delay_reason,other', 'string', 'max:255'],
                'summary'            => ['nullable', 'string', 'max:2000'],
                // Evidence — photos/videos, up to 256 MB each (same allow-list as repair videos).
                'files'              => ['nullable', 'array', 'max:10'],
                'files.*'            => ['file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:262144'],
            ]);

            // Did the ETA actually move? Compare the new date to the promise currently in force. When it
            // changes, a reason is mandatory (so managers always see WHY a car slipped); when it doesn't,
            // any stray reason is dropped so the row stays clean.
            $previous = $ticket->effectiveExpectedCompletion()?->startOfDay();
            $next     = \Carbon\Carbon::parse($data['next_expected_date'])->startOfDay();
            $etaChanged = $previous === null || ! $previous->equalTo($next);

            if ($etaChanged && empty($data['delay_reason'])) {
                return ResponseHelper::FailureResponse(null, 'Select a reason for the changed completion date.', 422);
            }
            if (! $etaChanged) {
                $data['delay_reason'] = null;
                $data['delay_reason_other'] = null;
            } elseif (($data['delay_reason'] ?? null) !== 'other') {
                $data['delay_reason_other'] = null;
            }

            $checkpoint = $this->checkpoints->submit($ticket, $user, $data);

            foreach ((array) $request->file('files', []) as $file) {
                $this->storeCheckpointMedia($ticket, $checkpoint, $file, $user);
            }

            $checkpoint->load('media');

            return ResponseHelper::SuccessResponse([
                'checkpoint' => $this->checkpointArray($checkpoint),
                'monitor'    => $this->checkpoints->monitorState($ticket->refresh()),
            ], 'Checkpoint saved', 201);
        });
    }

    /**
     * A vehicle's full checkpoint timeline (across every ticket) + the live monitoring state of its
     * currently-open in-shop ticket, if any. Powers the Vehicle Profile "Maintenance Progress" tab.
     */
    public function vehicleTimeline(Vehicle $vehicle)
    {
        return $this->run(function () use ($vehicle) {
            $items = MaintenanceCheckpoint::where('vehicle_id', $vehicle->id)
                ->with('media')->latest()->get()
                ->map(fn ($c) => $this->checkpointArray($c))->all();

            $active = Maintenance::where('vehicle_id', $vehicle->id)
                ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
                ->with('checkpoints')
                ->orderByDesc('last_state_change_at')
                ->first();

            return ResponseHelper::SuccessResponse([
                'vehicle_id'       => $vehicle->id,
                'active_ticket_id' => $active?->id,
                'monitor'          => $active ? $this->checkpoints->monitorState($active) : null,
                'checkpoints'      => $items,
            ], 'Vehicle checkpoints retrieved', 200);
        });
    }

    /**
     * Ensure the maintenance ticket a contract-sourced Maintenance-Progress row files its checkpoints
     * against. Open type-U maintenance contracts have no workflow ticket of their own; the queue shows them
     * with a null ticket_id, so the first time the supervisors file a checkpoint we lazily link one
     * lightweight ticket to the contract (idempotent — keyed on contract_id) and hand back its id. The row
     * (origin 'contract', no workflow_status) stays out of the workshop-log / open-workflow queries, so it
     * never double-counts anywhere else. See DashboardService::contractProgressRows().
     */
    public function ensureForContract(Request $request, \App\Models\Contract $contract)
    {
        return $this->run(function () use ($request, $contract) {
            $user = $request->user();
            // Same submit authority as store(): the create permission, or an admin. (A contract car has no
            // assigned responsibles until its ticket exists, so only the permission path applies here.)
            if (! $user || ! $user->can('maintenance.checkpoint.create')) {
                return ResponseHelper::FailureResponse(null, 'You are not allowed to file checkpoints.', 403);
            }

            $ticket = Maintenance::firstOrCreate(
                ['contract_id' => $contract->id],
                [
                    'vehicle_id' => $contract->vehicle_id,
                    'origin'     => 'contract',
                    'out_date'   => $contract->out_date,
                ],
            );

            return ResponseHelper::SuccessResponse(['ticket_id' => (int) $ticket->id], 'Ticket ready', 200);
        });
    }

    /** Delete a checkpoint (+ its media). Manage authority. */
    public function destroy(Maintenance $ticket, MaintenanceCheckpoint $checkpoint, Request $request)
    {
        return $this->run(function () use ($ticket, $checkpoint, $request) {
            abort_unless((int) $checkpoint->maintenance_id === (int) $ticket->id, 404);
            if (! $request->user() || ! $this->checkpoints->canManage($request->user())) {
                return ResponseHelper::FailureResponse(null, 'You cannot manage checkpoints.', 403);
            }
            foreach ($checkpoint->media as $m) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($m->disk ?: 'public')->delete($m->s3_key);
                } catch (\Throwable $e) {
                    // best-effort — remove the row even if the object is already gone
                }
                $m->delete();
            }
            $checkpoint->delete();

            return ResponseHelper::SuccessResponse(null, 'Checkpoint removed', 200);
        });
    }

    /**
     * Set a ticket's expected completion. Accepts a duration in days (we derive the date) OR an explicit
     * date (which then wins as the source of truth). Manage authority (managers/supervisors). Resets the
     * escalation naturally, since everything measures against the new date.
     */
    public function setExpected(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            if (! $request->user() || ! $this->checkpoints->canManage($request->user())) {
                return ResponseHelper::FailureResponse(null, 'You cannot set the expected completion.', 403);
            }

            $data = $request->validate([
                'expected_duration_days'   => ['nullable', 'integer', 'min:1', 'max:365'],
                'expected_completion_date' => ['nullable', 'date'],
            ]);

            if (! empty($data['expected_completion_date'])) {
                // Hand-entered date wins outright.
                $ticket->expected_completion_date = \Carbon\Carbon::parse($data['expected_completion_date'])->startOfDay();
                $ticket->expected_duration_days   = $data['expected_duration_days'] ?? $ticket->expected_duration_days;
            } elseif (! empty($data['expected_duration_days'])) {
                // Derive the date from the duration off the in-shop start anchor.
                $start = $ticket->repair_started_at ?? $ticket->out_date ?? $ticket->dispatched_at ?? $ticket->created_at ?? now();
                $ticket->expected_duration_days   = (int) $data['expected_duration_days'];
                $ticket->expected_completion_date = $start->copy()->startOfDay()->addDays((int) $data['expected_duration_days']);
            }
            $ticket->save();

            return ResponseHelper::SuccessResponse([
                'expected_completion_date' => optional($ticket->expected_completion_date)->toDateString(),
                'expected_duration_days'   => $ticket->expected_duration_days,
                'monitor'                  => $this->checkpoints->monitorState($ticket),
            ], 'Expected completion updated', 200);
        });
    }

    /**
     * The users assignable as checkpoint responsibles — active users holding the fallback follow-up
     * permission (the Supervisors). Powers the per-ticket responsible-user picker.
     */
    public function candidates()
    {
        return $this->run(function () {
            $permission = (string) config('maintenance.checkpoint.fallback_permission', 'maintenance.delegate');
            $query = \App\Models\User::permission($permission);
            if (\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasColumn('users', 'status')) {
                $query->where('status', 'active');
            }
            $users = $query->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();

            return ResponseHelper::SuccessResponse($users, 'Candidates retrieved', 200);
        });
    }

    /** Replace a ticket's responsible follow-up users. Manage authority. */
    public function setResponsibles(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            if (! $request->user() || ! $this->checkpoints->canManage($request->user())) {
                return ResponseHelper::FailureResponse(null, 'You cannot set responsible users.', 403);
            }

            $data = $request->validate([
                'user_ids'   => ['present', 'array'],
                'user_ids.*' => ['integer', Rule::exists('users', 'id')],
            ]);

            $recipients = $this->checkpoints->setResponsibles($ticket, $data['user_ids'], $request->user());

            return ResponseHelper::SuccessResponse([
                'responsibles' => $recipients->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                'assigned'     => $ticket->responsibles()->pluck('users.id')->all(),
            ], 'Responsible users updated', 200);
        });
    }

    /** Store one uploaded photo/video against a checkpoint (mirrors the repair-video ingest). */
    private function storeCheckpointMedia(Maintenance $ticket, MaintenanceCheckpoint $checkpoint, \Illuminate\Http\UploadedFile $file, ?\App\Models\User $user): ?MaintenanceMedia
    {
        $disk = 'public';
        $mime = (string) $file->getClientMimeType();
        $kind = str_starts_with($mime, 'image/') ? 'image' : 'video';
        $ext  = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'mp4'));
        $key  = $file->storeAs("maintenance-videos/ticket-{$ticket->id}", (string) Str::uuid() . '.' . $ext, $disk);
        if (! $key) {
            return null;
        }

        return MaintenanceMedia::create([
            'maintenance_id'            => $ticket->id,
            'maintenance_checkpoint_id' => $checkpoint->id,
            'kind'                      => $kind,
            'disk'                      => $disk,
            's3_key'                    => $key,
            'content_type'              => $mime,
            'original_name'             => $file->getClientOriginalName(),
            'file_size'                 => $file->getSize(),
            'uploaded_by'               => $user?->id,
            'uploaded_by_name'          => $user?->name,
        ]);
    }

    /** Serialise one checkpoint (+ its media) for the timeline. */
    private function checkpointArray(MaintenanceCheckpoint $c): array
    {
        return [
            'id'                     => $c->id,
            'status'                 => $c->status,
            'delay_reason'           => $c->delay_reason,
            'delay_reason_other'     => $c->delay_reason_other,
            'summary'                => $c->summary,
            'previous_expected_date' => optional($c->previous_expected_date)->toDateString(),
            'next_expected_date'     => optional($c->next_expected_date)->toDateString(),
            'submitted_by_name'      => $c->submitted_by_name,
            'created_at'             => optional($c->created_at)->toIso8601String(),
            'media'              => $c->media->map(fn ($m) => [
                'id'            => $m->id,
                'kind'          => $m->kind,
                'note'          => $m->note,
                'original_name' => $m->original_name,
                'url'           => $m->viewUrl(),
            ])->values()->all(),
        ];
    }

    /** One unified error path (validation → 422, else mapped + logged). Mirrors the workflow controller. */
    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
