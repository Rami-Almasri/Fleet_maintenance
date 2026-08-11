<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\LogisticsTaskResource;
use App\Models\InspectionRecord;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\LogisticsDispatchService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Logistics Dispatch — the data-driven replacement for WhatsApp coordination. Coordinators raise moves;
 * drivers claim them from a pool and walk them through Picked Up → Delivered → Returned/Arrived.
 *
 *   index()      : every open dispatch — the team-wide oversight board ("where are the cars?").
 *   pool()       : unclaimed moves up for grabs — the driver's "available to claim" list.
 *   myQueue()    : the moves the signed-in driver has claimed — their personal "My Queue".
 *   assignees()  : people a move can be pre-assigned to + destination presets (the create modal).
 *   store()      : a coordinator raises a move (pooled, or pre-assigned to one driver).
 *   claim()      : a driver takes a pooled move (first one wins).
 *   pickup/deliver/markReturned() : the assignee steps the trip along (GPS captured on return).
 *   complete()/cancel() : close a move (back-compat one-tap / coordinator call-off).
 *   ping()/respondStatus() : "where is the car?" ask + reply.
 */
class LogisticsDispatchController extends Controller
{
    public function __construct(
        private LogisticsDispatchService $service,
        private \App\Services\NotificationScanner $notifier,
    ) {}

    /** Eager-load shape shared by every list endpoint — vehicle basics + the audit trail, newest first. */
    private function withRelations($query)
    {
        return $query->with([
            // `odometer` is NOT optional here: the driver's pre-trip capture is checked against it
            // (Odometer Continuity), and without the column the card silently shows no baseline at
            // all — the reading is then typed against nothing.
            // `odometer` and `service_interval_km` are NOT optional here: the driver's pre-trip
            // capture is checked against the first (Odometer Continuity), and a collection card
            // shows the second to preview what the next service point becomes ("18,900 + 7,000").
            // Omit either and the card silently renders a blank where a number belongs.
            'vehicle:id,plate_no,make,model,odometer,service_interval_km',
            'events' => fn ($q) => $q->orderByDesc('occurred_at'),
        ]);
    }

    /** Every open dispatch, newest first — the shared oversight board. */
    public function index()
    {
        try {
            $tasks = $this->withRelations(LogisticsTask::open())
                ->orderByDesc('dispatched_at')->get();

            return ResponseHelper::SuccessResponse([
                'tasks'   => LogisticsTaskResource::collection($tasks),
                'summary' => [
                    'open'      => $tasks->count(),
                    'unclaimed' => $tasks->where('status', LogisticsTask::STATUS_DISPATCHED)->count(),
                ],
            ], 'Logistics dispatches retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Unclaimed moves up for grabs — the pool any driver can take. */
    public function pool()
    {
        try {
            $tasks = $this->withRelations(
                LogisticsTask::open()
                    ->where('status', LogisticsTask::STATUS_DISPATCHED)
                    ->whereNull('assigned_to_id')
            )->orderBy('dispatched_at')->get(); // oldest first → fairest to claim

            return ResponseHelper::SuccessResponse([
                'tasks'   => LogisticsTaskResource::collection($tasks),
                'summary' => ['unclaimed' => $tasks->count()],
            ], 'Claimable dispatches retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The signed-in user's claimed dispatches — their personal queue (scoped to them, auth-only). */
    public function myQueue(Request $request)
    {
        try {
            $tasks = $this->withRelations(
                LogisticsTask::open()->where('assigned_to_id', $request->user()->id)
            )->orderByDesc('dispatched_at')->get();

            return ResponseHelper::SuccessResponse([
                'tasks'   => LogisticsTaskResource::collection($tasks),
                'summary' => ['mine' => $tasks->count()],
            ], 'My logistics queue retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * CARS TO COLLECT FROM CUSTOMERS — the driver's own panel on /my-maintenance-queue.
     *
     * Deliberately NOT just "open tasks assigned to me". A collection's job is not finished when the
     * car is parked: the oil change it was raised for still has to be recorded, and the driver is
     * often the one who does it. A one-way move CLOSES the moment he marks it delivered, so serving
     * only open tasks would make the card vanish one step before its last step.
     *
     * So the list is: every open collection he could act on (unclaimed, or his), PLUS his recently
     * delivered ones whose oil change is still outstanding. Bounded to 48 hours — a collection from
     * last week whose oil nobody recorded is a supervision problem for the board, not a live card in
     * a driver's queue.
     */
    public function myCollections(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $since  = now()->subHours(48);

            // Abu Maroof does the change himself when the car comes to our parking, so those jobs are
            // HIS card too — regardless of which driver happened to fetch the car. Without this the
            // one person who has to act never sees the button, because the trip was somebody else's.
            $parkingOwner = app(\App\Services\OilChangeProjectionService::class)
                ->parkingOwners()->contains('id', $userId);

            // An UNCLAIMED job is up for grabs — but only by someone who can actually drive it.
            // Without this, Abu Maroof (who owns the parking lane, not the wheel) gets every pooled
            // garage collection on his queue as well, which is noise he can do nothing about.
            $canClaim = $request->user()->can('logistics.claim');

            $tasks = $this->withRelations(
                LogisticsTask::where('purpose', LogisticsTask::PURPOSE_CUSTOMER_COLLECTION)
                    ->where(function ($q) use ($userId, $since, $parkingOwner, $canClaim) {
                        $q->where(fn ($open) => $open->whereNull('completed_at')
                            ->where(function ($mine) use ($userId, $canClaim) {
                                $mine->where('assigned_to_id', $userId);
                                if ($canClaim) {
                                    $mine->orWhereNull('assigned_to_id');
                                }
                            }))
                          ->orWhere(fn ($done) => $done->whereNotNull('completed_at')
                            ->where('assigned_to_id', $userId)
                            ->where('completed_at', '>=', $since));

                        if ($parkingOwner) {
                            // Every parking job still owing work, whoever drove it.
                            $q->orWhereIn('vehicle_id', \App\Models\ContractOilDecision::query()
                                ->where('service_location', \App\Models\ContractOilDecision::LOCATION_PARKING)
                                ->where(fn ($d) => $d->whereNull('oil_changed_at')->orWhereNull('returned_to_customer_at'))
                                ->select('vehicle_id'));
                        }
                    })
            )->orderByDesc('dispatched_at')->limit(50)->get();

            // A finished trip is only finished when the car is back with the customer. Drop it once
            // the oil is recorded AND the keys are handed over — before that there is still a
            // button on it, and dropping it early is how a car goes quiet in our yard.
            $tasks = $tasks->reject(function (LogisticsTask $t) {
                if ($t->isActive()) {
                    return false;
                }
                $oil = LogisticsTaskResource::make($t)->resolve()['oil_followup'] ?? null;

                return $oil && $oil['oil_changed'] && ! $oil['owes_return'];
            });

            return ResponseHelper::SuccessResponse([
                'tasks'   => LogisticsTaskResource::collection($tasks->values()),
                'summary' => ['mine' => $tasks->count()],
            ], 'Customer collections retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Active users who can be pre-assigned a dispatch (hold logistics.view) — the assignee dropdown. */
    public function assignees()
    {
        try {
            $query = User::permission('logistics.view')->orderBy('name');
            if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
                $query->where('status', 'active');
            }
            $people = $query->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name ?: $u->email])
                ->values();

            return ResponseHelper::SuccessResponse([
                'assignees'    => $people,
                'destinations' => LogisticsTask::COMMON_DESTINATIONS,
            ], 'Assignees retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The DRIVER ROSTER — every field driver (logistics.claim) and what they're doing RIGHT NOW:
     * "available", or busy on a move / a maintenance pickup. Powers the availability panel so a
     * coordinator can see at a glance who is free to take the next job. Activity is derived from the
     * driver's open LogisticsTask (the canonical "car is out" system); if they have none, we fall back
     * to any maintenance ticket they're the assigned driver on (awaiting pickup / at the garage).
     */
    public function roster()
    {
        try {
            $query = User::permission('logistics.claim')->orderBy('name');
            if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
                $query->where('status', 'active');
            }
            $drivers   = $query->get(['id', 'name', 'email']);
            $driverIds = $drivers->pluck('id')->all();

            // Primary signal: their open movement task(s).
            $tasksByDriver = LogisticsTask::open()
                ->whereIn('assigned_to_id', $driverIds)
                ->orderByDesc('status_changed_at')
                ->get()
                ->groupBy('assigned_to_id');

            // Fallback: a maintenance pickup assigned to them that isn't a movement task yet.
            $maintByDriver = Maintenance::query()
                ->whereIn('assigned_driver_id', $driverIds)
                ->whereIn('workflow_status', [Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_UNDER_REPAIR])
                ->with('vehicle:id,plate_no,make,model')
                ->get()
                ->groupBy('assigned_driver_id');

            $roster = $drivers->map(function ($d) use ($tasksByDriver, $maintByDriver) {
                $name  = $d->name ?: $d->email;
                $tasks = $tasksByDriver->get($d->id, collect());
                $task  = $tasks->first();

                if ($task) {
                    return [
                        'id'       => $d->id,
                        'name'     => $name,
                        'status'   => 'busy',
                        'activity' => [
                            'kind'        => 'move',
                            'label'       => $task->phaseLabel(),
                            'vehicle'     => $task->vehicle_plate ?: $task->vehicle_label,
                            'destination' => $task->destination,
                            'since'       => optional($task->status_changed_at ?: $task->dispatched_at)->toIso8601String(),
                            'last_status' => $task->last_status,
                            'tasks'       => $tasks->count(),
                        ],
                    ];
                }

                $mt = $maintByDriver->get($d->id, collect())->first();
                if ($mt) {
                    $atGarage = $mt->workflow_status === Maintenance::WF_UNDER_REPAIR;
                    return [
                        'id'       => $d->id,
                        'name'     => $name,
                        'status'   => 'busy',
                        'activity' => [
                            'kind'        => 'maintenance',
                            'label'       => $atGarage
                                ? 'At garage · ' . ($mt->garage ?: 'workshop')
                                : 'Assigned pickup → ' . ($mt->garage ?: 'garage'),
                            'vehicle'     => $mt->vehicle?->plate_no,
                            'destination' => $mt->garage,
                            'since'       => optional($mt->delegated_at ?: $mt->updated_at)->toIso8601String(),
                            'last_status' => null,
                            'tasks'       => 1,
                        ],
                    ];
                }

                return ['id' => $d->id, 'name' => $name, 'status' => 'available', 'activity' => null];
            })->values();

            return ResponseHelper::SuccessResponse([
                'drivers' => $roster,
                'summary' => [
                    'total'     => $roster->count(),
                    'available' => $roster->where('status', 'available')->count(),
                    'busy'      => $roster->where('status', 'busy')->count(),
                ],
            ], 'Driver roster retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Raise a move. body: { vehicle_id, destination, round_trip?, assigned_to_id?, maintenance_id?, notes? } */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'     => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'destination'    => ['required', 'string', 'max:255'],
                'round_trip'     => ['nullable', 'boolean'],
                'assigned_to_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                'maintenance_id' => ['nullable', 'integer', Rule::exists('maintenances', 'id')],
                'notes'          => ['nullable', 'string', 'max:1000'],
            ]);

            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            $task = $this->service->dispatch($vehicle, $data, $request->user());

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Vehicle dispatched', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** A driver claims a pooled move — first one wins; the rest get a clear "already taken". */
    public function claim(Request $request, LogisticsTask $logisticsTask)
    {
        return $this->run(fn () => $this->service->claim($logisticsTask, $request->user()), 'Move claimed');
    }

    /**
     * The assignee marks the car PICKED UP (now with the driver, in transit). This is the PRE-trip
     * odometer checkpoint: on a garage round trip (round_trip, or a maintenance-linked move) the reading
     * AND its photo are mandatory — the "before" half of the strict before/after pair. The photo is
     * ingested server-side (stored as a 'pre' odometer InspectionRecord) so the driver needs no
     * inspections.* permission; it's saved best-effort after the transition commits.
     */
    public function pickup(Request $request, LogisticsTask $logisticsTask)
    {
        try {
            $needs = $this->requiresOdometer($logisticsTask);
            $data  = $request->validate([
                'odometer'       => [$needs ? 'required' : 'nullable', 'integer', 'min:1'],
                'odometer_photo' => [$needs ? 'required' : 'nullable', 'image', 'max:8192'],
                'odometer_note'  => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $task = $this->service->pickup($logisticsTask, $request->user(), ['odometer' => $data['odometer'] ?? null, 'odometer_note' => $data['odometer_note'] ?? null]);
            $this->maybeStorePhoto($request, $task, 'pre');
            $this->notifySupervisorsOfMove($task, 'picked_up', $request->user());

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Marked picked up', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The assignee marks the car DELIVERED (at the destination). For a ONE-WAY move this is the terminal
     * step, so it doubles as the POST odometer checkpoint — reading + photo mandatory on a maintenance-
     * linked one-way move. On a round trip the post reading is captured at "Returned" instead, so nothing
     * is required here.
     */
    public function deliver(Request $request, LogisticsTask $logisticsTask)
    {
        try {
            // One-way moves close here, so the post-reading lands now; round trips defer it to return.
            $needs = $this->requiresOdometer($logisticsTask) && ! $logisticsTask->round_trip;
            $data  = $request->validate([
                'odometer'       => [$needs ? 'required' : 'nullable', 'integer', 'min:1'],
                'odometer_photo' => [$needs ? 'required' : 'nullable', 'image', 'max:8192'],
                'odometer_note'  => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $task = $this->service->deliver($logisticsTask, $request->user(), ['odometer' => $data['odometer'] ?? null, 'odometer_note' => $data['odometer_note'] ?? null]);
            $this->maybeStorePhoto($request, $task, 'post');
            $this->notifySupervisorsOfMove($task, 'delivered', $request->user());

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Marked delivered', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The assignee marks the car RETURNED / ARRIVED back at base — the terminal step of a round trip. It
     * captures both the GPS fix (proof the car is physically home) and the POST odometer reading + photo
     * (the "after" half of the strict before/after pair on a garage trip).
     */
    public function markReturned(Request $request, LogisticsTask $logisticsTask)
    {
        try {
            $needs = $this->requiresOdometer($logisticsTask);
            $data  = $request->validate([
                'lat'            => ['nullable', 'numeric', 'between:-90,90'],
                'lng'            => ['nullable', 'numeric', 'between:-180,180'],
                'accuracy'       => ['nullable', 'numeric', 'min:0'],
                'odometer'       => [$needs ? 'required' : 'nullable', 'integer', 'min:1'],
                'odometer_photo' => [$needs ? 'required' : 'nullable', 'image', 'max:8192'],
                'odometer_note'  => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $task = $this->service->returnToBase($logisticsTask, $request->user(), [
                'lat'      => $data['lat'] ?? null,
                'lng'      => $data['lng'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'odometer' => $data['odometer'] ?? null,
                'odometer_note' => $data['odometer_note'] ?? null,
            ]);
            $this->maybeStorePhoto($request, $task, 'post');

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Marked returned / arrived', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * When a MAINTENANCE-linked move is stepped along (picked up / delivered), ping the SUPERVISORS
     * (Waleed/Abdullah, maintenance.delegate) so they can immediately follow up with the garage — the
     * "notify management the moment the driver finishes a pickup/delivery" rule. Best-effort: a failure
     * here never blocks the transition the driver just completed.
     */
    private function notifySupervisorsOfMove(LogisticsTask $task, string $verb, ?User $actor): void
    {
        if (! $task->maintenance_id) {
            return; // only maintenance trips concern the supervisors
        }
        try {
            $ticket  = Maintenance::with('vehicle:id,plate_no,make,model')->find($task->maintenance_id);
            $vehicle = $ticket?->vehicle;
            $name    = $vehicle
                ? trim($vehicle->make . ' ' . $vehicle->model) . ($vehicle->plate_no ? ' (' . $vehicle->plate_no . ')' : '')
                : 'A vehicle';
            $dest      = $task->destination ?: 'the garage';
            $delivered = $verb === 'delivered';
            $this->notifier->notifyByPermission('maintenance.delegate', [
                'type'     => 'logistics_maint_' . $verb,
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => ($delivered ? '🚗 Delivered · ' : '📦 Picked up · ') . $name,
                'body'     => trim(($actor?->name ?: 'The driver') . ' ' . ($delivered
                                ? 'delivered ' . $name . ' to ' . $dest . ' — follow up with the garage.'
                                : 'picked up ' . $name . ' — on the way to ' . $dest . '.')),
                'url'      => $ticket ? '/maintenance-workflow/' . $ticket->id : '/logistics',
                'key'      => 'logistics_move:' . $task->id . ':' . $verb,
                'icon'     => 'wrench',
                'meta'     => ['task_id' => $task->id, 'maintenance_id' => $task->maintenance_id, 'plate' => $vehicle?->plate_no],
            ], $actor?->id);
        } catch (\Throwable $e) {
            report($e); // logged — never surfaced, the move already succeeded
        }
    }

    /**
     * Supervisor override — hand the move to a different driver at any point in the cycle (one driver
     * takes the car out, another brings it back). Coordinator-only (gated on the route).
     */
    public function reassign(Request $request, LogisticsTask $logisticsTask)
    {
        try {
            $data   = $request->validate(['assigned_to_id' => ['required', 'integer', Rule::exists('users', 'id')]]);
            $driver = User::findOrFail($data['assigned_to_id']);
            $task   = $this->service->reassign($logisticsTask, $driver, $request->user());

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Driver reassigned', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Mark a dispatch closed at its terminal (back-compat). Only the assignee or a dispatcher may. */
    public function complete(Request $request, LogisticsTask $logisticsTask)
    {
        $user = $request->user();
        $isAssignee = $logisticsTask->assigned_to_id && (int) $logisticsTask->assigned_to_id === (int) $user->id;
        if (! $isAssignee && ! $user->can('logistics.dispatch')) {
            return ResponseHelper::FailureResponse(null, 'Only the assignee or a dispatcher can complete this move.', 403);
        }

        return $this->run(fn () => $this->service->complete($logisticsTask, $user), 'Dispatch completed');
    }

    /** Cancel a dispatch (dispatcher only). */
    public function cancel(Request $request, LogisticsTask $logisticsTask)
    {
        return $this->run(fn () => $this->service->cancel($logisticsTask, $request->user()), 'Dispatch cancelled');
    }

    /** "Ping location" — a dispatcher asks the assignee where the car is. */
    public function ping(Request $request, LogisticsTask $logisticsTask)
    {
        return $this->run(fn () => $this->service->ping($logisticsTask, $request->user()), 'Location request sent');
    }

    /** The assignee replies with the car's current status (one-click preset or free text). */
    public function respondStatus(Request $request, LogisticsTask $logisticsTask)
    {
        try {
            $data = $request->validate(['status' => ['required', 'string', 'max:120']]);
            $task = $this->service->respondStatus($logisticsTask, $data['status'], $request->user());

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), 'Status updated', 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::FailureResponse(null, $e->validator->errors()->first(), 422);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Shared runner for the one-line action endpoints (claim / cancel / ping). */
    private function run(callable $action, string $message)
    {
        try {
            $task = $action();

            return ResponseHelper::SuccessResponse(LogisticsTaskResource::make($task), $message, 200);
        } catch (\RuntimeException $e) {
            return ResponseHelper::fromException($e);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Odometer (Pre/Post) integrity shots ────────────────────────────────────

    /**
     * Is a mandatory odometer reading + photo expected on this move? Garage trips — anything brought
     * back (round_trip) or tied to a maintenance ticket — must carry the before/after odometer pair;
     * a plain one-way move (e.g. to the showroom) does not.
     */
    private function requiresOdometer(LogisticsTask $task): bool
    {
        // A collection from a customer ALWAYS demands it: the reading at the doorstep is the reason
        // the trip exists (it is what the oil follow-up has been chasing by phone for days), and it
        // is the last chance to capture it before the car is ours and the number is history.
        return (bool) ($task->round_trip || $task->maintenance_id || $task->isCustomerCollection());
    }

    /** Store the odometer photo (best-effort) once the transition has committed, if one was sent. */
    private function maybeStorePhoto(Request $request, LogisticsTask $task, string $phase): void
    {
        if (! $request->hasFile('odometer_photo')) {
            return;
        }
        try {
            $this->storeOdometerPhoto($task, $request->file('odometer_photo'), $request->user(), $phase);
        } catch (\Throwable $e) {
            report($e); // logged, never surfaced — the transition already succeeded
        }
    }

    /**
     * Persist an odometer photo as an InspectionRecord (zone 'odometer'), anchored to the car — the
     * 'pre' shot at pick-up, the 'post' shot at delivery/return. Bytes go to S3 when configured, else
     * the local `public` disk, so it works on a demo machine with no AWS env. Mirrors the maintenance
     * workflow's odometer capture so both flows share one before/after photo trail per vehicle.
     */
    private function storeOdometerPhoto(LogisticsTask $task, UploadedFile $file, $user, string $phase): ?InspectionRecord
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $dir  = "inspections/vehicle-{$task->vehicle_id}/{$phase}/odometer";

        // Snapshot metadata BEFORE the move (the temp file is gone after storeAs).
        $mime = $file->getMimeType();
        $size = $file->getSize();
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $key = $file->storeAs($dir, (string) Str::uuid() . '.' . $ext, $disk);
        if (! $key) {
            return null;
        }

        return InspectionRecord::create([
            'vehicle_id'            => $task->vehicle_id,
            'inspection_session_id' => (string) Str::uuid(),
            'phase'                 => $phase,
            'body_part'             => 'odometer',
            'checkpoint_type'       => 'interior',
            's3_disk'               => $disk,
            's3_key'                => $key,
            'mime_type'             => $mime,
            'file_size'             => $size,
            'width'                 => $width ?: null,
            'height'                => $height ?: null,
            'inspector_id'          => $user?->id,
            'inspector_name'        => $user?->name,
            'captured_at'           => now(),
        ]);
    }
}
