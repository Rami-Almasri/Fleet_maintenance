<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Team Presence — the "who's where right now" board.
 *
 * One card per active staff member showing whether they are AVAILABLE or BUSY, and when busy exactly
 * WHY: the live job they're on, the car involved, where it's headed and how long they've been on it.
 * There is no clock-in / GPS presence system in this app — "busy" is derived purely from the work a
 * person currently owns, which is the honest signal (someone with an open job is unavailable; nobody
 * has to remember to toggle a status). Three signals, in priority order:
 *
 *   1. an open LogisticsTask assigned to them  → they're driving a move (the canonical "car is out"),
 *   2. else a Maintenance ticket they're the assigned driver on (pickup / en route / at the garage),
 *   3. else a Maintenance ticket they're the inspector on (test-drive diagnostic / re-inspection).
 *
 * Everyone else is Available. Generalises LogisticsDispatchController::roster() (field drivers only)
 * to the WHOLE team, tagging each person with their role so a coordinator can scan drivers, inspectors
 * and supervisors in one place.
 */
class TeamPresenceController extends Controller
{
    /**
     * Display metadata per Spatie role — a human label + a Badge tone. `logistics` reads as "Driver"
     * (that's what the field pool is called on the board).
     */
    private const ROLE_META = [
        'super-admin' => ['label' => 'Admin',       'tone' => 'slate'],
        'manager'     => ['label' => 'Manager',     'tone' => 'indigo'],
        'supervisor'  => ['label' => 'Supervisor',  'tone' => 'violet'],
        'operations'  => ['label' => 'Operations',  'tone' => 'blue'],
        'maintenance' => ['label' => 'Maintenance', 'tone' => 'amber'],
        'inspector'   => ['label' => 'Inspector',   'tone' => 'cyan'],
        'logistics'   => ['label' => 'Driver',      'tone' => 'blue'],
        'finance'     => ['label' => 'Finance',     'tone' => 'emerald'],
        'viewer'      => ['label' => 'Viewer',      'tone' => 'gray'],
    ];

    /** Most-operational-first, so a multi-role user is labelled by the hat they actually wear on the floor. */
    private const ROLE_PRIORITY = [
        'supervisor', 'inspector', 'logistics', 'maintenance', 'operations', 'manager', 'finance', 'super-admin', 'viewer',
    ];

    public function roster()
    {
        try {
            $usersQuery = User::query()->with('roles:id,name')->orderBy('name');
            if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
                $usersQuery->where('status', 'active');
            }
            $users   = $usersQuery->get(['id', 'name', 'email']);
            $userIds = $users->pluck('id')->all();

            // Signal 1 — their open movement task(s): the canonical "car is out with this person".
            $tasksByUser = LogisticsTask::open()
                ->whereIn('assigned_to_id', $userIds)
                ->orderByDesc('status_changed_at')
                ->get()
                ->groupBy('assigned_to_id');

            // Signal 2 — a maintenance job they're the assigned DRIVER on (pickup / en route / at garage).
            $maintByDriver = Maintenance::query()
                ->whereIn('assigned_driver_id', $userIds)
                ->whereIn('workflow_status', [
                    Maintenance::WF_AWAITING_DISPATCH,
                    Maintenance::WF_IN_TRANSIT,
                    Maintenance::WF_UNDER_REPAIR,
                ])
                ->with('vehicle:id,plate_no,make,model')
                ->get()
                ->groupBy('assigned_driver_id');

            // Signal 3 — a car they're the INSPECTOR on, mid test-drive or awaiting their re-inspection.
            $maintByInspector = Maintenance::query()
                ->whereIn('inspected_by', $userIds)
                ->whereIn('workflow_status', [
                    Maintenance::WF_INSPECTION_DIAGNOSTIC,
                    Maintenance::WF_READY_REINSPECTION,
                ])
                ->with('vehicle:id,plate_no,make,model')
                ->get()
                ->groupBy('inspected_by');

            $team = $users->map(function ($u) use ($tasksByUser, $maintByDriver, $maintByInspector) {
                [$roleKey, $roleLabel, $roleTone] = $this->primaryRole($u->roles->pluck('name')->all());

                $person = [
                    'id'        => $u->id,
                    'name'      => $u->name ?: $u->email,
                    'role'      => $roleLabel,
                    'role_key'  => $roleKey,
                    'role_tone' => $roleTone,
                ];

                // Priority: on a move ▸ on a maintenance pickup ▸ inspecting ▸ available.
                $tasks = $tasksByUser->get($u->id, collect());
                if ($task = $tasks->first()) {
                    return $person + ['status' => 'busy', 'activity' => [
                        'kind'        => 'move',
                        'label'       => $task->phaseLabel(),
                        'vehicle'     => $task->vehicle_plate ?: $task->vehicle_label,
                        'destination' => $task->destination,
                        'since'       => optional($task->status_changed_at ?: $task->dispatched_at)->toIso8601String(),
                        'last_status' => $task->last_status,
                        'count'       => $tasks->count(),
                    ]];
                }

                if ($mt = $maintByDriver->get($u->id, collect())->first()) {
                    $label = match ($mt->workflow_status) {
                        Maintenance::WF_UNDER_REPAIR => 'At garage · ' . ($mt->garage ?: 'workshop'),
                        Maintenance::WF_IN_TRANSIT   => 'En route to ' . ($mt->garage ?: 'garage'),
                        default                      => 'Assigned pickup → ' . ($mt->garage ?: 'garage'),
                    };

                    return $person + ['status' => 'busy', 'activity' => [
                        'kind'        => 'maintenance',
                        'label'       => $label,
                        'vehicle'     => $mt->vehicle?->plate_no,
                        'destination' => $mt->garage,
                        'since'       => optional($mt->delegated_at ?: $mt->updated_at)->toIso8601String(),
                        'last_status' => null,
                        'count'       => 1,
                    ]];
                }

                if ($mt = $maintByInspector->get($u->id, collect())->first()) {
                    $reinspect = $mt->workflow_status === Maintenance::WF_READY_REINSPECTION;

                    return $person + ['status' => 'busy', 'activity' => [
                        'kind'        => 'inspection',
                        'label'       => $reinspect ? 'Re-inspecting on return' : 'Inspecting · test drive',
                        'vehicle'     => $mt->vehicle?->plate_no,
                        'destination' => null,
                        'since'       => optional($mt->updated_at)->toIso8601String(),
                        'last_status' => null,
                        'count'       => 1,
                    ]];
                }

                return $person + ['status' => 'available', 'activity' => null];
            })->values();

            return ResponseHelper::SuccessResponse([
                'team'    => $team,
                'summary' => [
                    'total'     => $team->count(),
                    'available' => $team->where('status', 'available')->count(),
                    'busy'      => $team->where('status', 'busy')->count(),
                ],
            ], 'Team presence retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Resolve a user's several roles down to the one label the board shows (most operational first). */
    private function primaryRole(array $roles): array
    {
        foreach (self::ROLE_PRIORITY as $r) {
            if (in_array($r, $roles, true)) {
                $meta = self::ROLE_META[$r] ?? ['label' => ucfirst($r), 'tone' => 'gray'];

                return [$r, $meta['label'], $meta['tone']];
            }
        }

        return ['none', 'No role', 'gray'];
    }
}
