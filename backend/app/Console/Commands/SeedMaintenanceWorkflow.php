<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\Vendor;
use App\Services\OperationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * TEST-DATA SEEDER for the Maintenance Workflow board.
 *
 * The counterpart to `maintenance:reset-workflow`: it populates the board with one representative
 * ticket in each lifecycle stage so the pipeline, SLA clocks, role queues and the operational_status
 * cascade all have something to render for an end-to-end trial. Every row is a hand-entered ticket
 * (origin = 'manual') on a DISTINCT active car (status ready/rented) that has no open ticket yet, with
 * stage-appropriate fields filled in (findings + fault severity from inspection_pending onward, a garage
 * + dispatch odometer once dispatched, a receive odometer under repair). Cars from the ticket stages are
 * then reconciled to `maintenance`; the two pre-ticket diagnostic
 * lanes deliberately leave the car's status alone (they are not yet committed tickets).
 *
 * This is a mirror of the reset command's contract — seed, trial, reset, repeat.
 *
 * Safety:
 *   --dry-run   build the plan inside a rolled-back transaction and print it, writing nothing
 *   --fresh     wipe the existing board first (runs maintenance:reset-workflow) before seeding
 *   --count=N   seed only the first N stages (default: one ticket per stage, the whole pipeline)
 */
class SeedMaintenanceWorkflow extends Command
{
    protected $signature = 'maintenance:seed-workflow
                            {--dry-run : Build inside a rolled-back transaction and report, writing nothing}
                            {--fresh : Wipe the existing board (maintenance:reset-workflow) before seeding}
                            {--count= : Seed only the first N pipeline stages (default: all)}';

    protected $description = 'Seed representative maintenance-workflow tickets across every lifecycle stage (test data)';

    public function __construct(private OperationsService $operations)
    {
        parent::__construct();
    }

    /**
     * The pipeline, one entry per board lane. `apply` mutates the ticket array for that stage; each stage
     * inherits an ever-growing set of fields (a car at re-inspection has been inspected, dispatched,
     * repaired …), so later stages layer more on. `age` backdates last_state_change_at so the chips vary.
     */
    private function stages(): array
    {
        return [
            ['status' => Maintenance::WF_INSPECTION_REQUESTED,  'age' => 1,  'apply' => fn (&$t) => $t = array_merge($t, $this->chain('requested'))],
            ['status' => Maintenance::WF_INSPECTION_DIAGNOSTIC, 'age' => 2,  'apply' => fn (&$t) => $t = array_merge($t, $this->chain('requested'), ['test_started_at' => now()->subHours(2)])],
            ['status' => Maintenance::WF_INSPECTION_PENDING,    'age' => 5,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->chain('inspected'))],
            ['status' => Maintenance::WF_AWAITING_DISPATCH,     'age' => 3,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->dispatched(), $this->chain('inspected'))],
            ['status' => Maintenance::WF_IN_TRANSIT,            'age' => 1,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->dispatched(), $this->chain('dispatched'))],
            ['status' => Maintenance::WF_UNDER_REPAIR,          'age' => 8,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->dispatched(), $this->atGarage(), $this->chain('repair'))],
            ['status' => Maintenance::WF_READY_REINSPECTION,    'age' => 2,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->dispatched(), $this->atGarage(), $this->finished(), $this->chain('ready'))],
            ['status' => Maintenance::WF_REINSPECTION_FAILED,   'age' => 6,  'apply' => fn (&$t) => $t = array_merge($t, $this->decided(), $this->dispatched(), $this->atGarage(), $this->finished(), $this->chain('ready'))],
        ];
    }

    /** Fields present once the inspector has decided "requires maintenance" (findings + mandatory grade). */
    private function decided(bool $complex = false): array
    {
        return [
            'trigger_reason'  => Maintenance::TRIGGER_TEST_DRIVE,
            'maintenance_type' => $complex ? Maintenance::TYPE_BREAKDOWN : Maintenance::TYPE_ROUTINE,
            'fault_severity'  => $complex ? Maintenance::FAULT_SEVERITY_CRITICAL : Maintenance::FAULT_SEVERITY_MODERATE,
            'test_odometer'   => 45000,
            'findings'        => [[
                'text'     => $complex ? 'Engine misfire under load' : 'Brake pads worn, squealing',
                'source'   => Maintenance::FINDING_INSPECTOR,
                'severity' => $complex ? 'critical' : 'moderate',
            ]],
            'test_drive_report' => ['summary' => 'Seeded diagnostic — for board testing.'],
        ];
    }

    /** Fields present once the Supervisor has picked a garage + driver and captured the dispatch odometer. */
    private function dispatched(): array
    {
        return [
            'vendor_id'         => $this->vendorId(),
            'garage'            => $this->vendorName(),
            'dispatch_odometer' => 45010,
            'assigned_driver_id' => $this->driverId(),
        ];
    }

    /** Fields present once the car has arrived and been checked in at the garage. */
    private function atGarage(): array
    {
        return ['receive_odometer' => 45015];
    }

    /** Fields present once the garage reports the repair finished. */
    private function finished(): array
    {
        return ['cost' => 350.00, 'garage_feedback' => 'Seeded — repair complete.'];
    }

    /**
     * A coherent, chronological set of HANDOFF timestamps (who + when) up to a given milestone, so the
     * ticket's journey — and the per-step durations the command view derives from it — looks real. The
     * gaps are deliberately uneven (a long repair, quick hand-offs) so the "how long each step took"
     * breakdown shows variety. The last milestone included is the ticket's current stage: it has no
     * next handoff, so the command view counts it as still running ("… so far").
     */
    private function chain(string $upTo): array
    {
        $points = [
            'requested'  => ['requested_at' => now()->subHours(30), 'requested_by' => $this->driverId],
            // test_started_at anchors the "Test drive" timing (dispatched_at − test_started_at); it's the
            // moment the inspector began the road test, just after the request and before the report.
            'inspected'  => ['test_started_at' => now()->subHours(29), 'inspected_at' => now()->subHours(28), 'inspected_by' => $this->inspectorId],
            'dispatched' => ['dispatched_at' => now()->subHours(26), 'dispatched_by' => $this->driverId],
            'repair'     => ['repair_started_at' => now()->subHours(24), 'repair_started_by' => $this->driverId],
            // ready = garage finished; returned_at = the car physically back at base, which closes the
            // "At garage" (returned_at − dispatched_at) and "Total downtime" (returned_at − test_started_at)
            // timings. Only set from here on — a car still under_repair has NOT returned, so those stay blank.
            'ready'      => ['ready_at' => now()->subHours(6), 'ready_by' => $this->inspectorId, 'returned_at' => now()->subHours(5)],
        ];

        $fields = [];
        foreach ($points as $key => $set) {
            $fields = array_merge($fields, $set);
            if ($key === $upTo) {
                break;
            }
        }

        return $fields;
    }

    /**
     * Write the append-only workflow log for a seeded ticket — one VehicleLogEvent per handoff the ticket
     * actually reached (derived from the *_at timestamps chain() stamped), each with its real actor + time
     * so the Vehicle-Status per-car history shows who did each stage and how long the car sat in it.
     */
    private function emitEvents(Maintenance $m): void
    {
        // milestone timestamp → [event_type, actor, description]
        $plan = [
            [$m->requested_at,     VehicleLogEvent::EVENT_INSPECTION_REQUESTED, $this->driverId,    'Driver flagged an issue — inspection requested'],
            [$m->inspected_at,     VehicleLogEvent::EVENT_REPORT_FILED,         $this->inspectorId, 'Diagnosis filed — repair required'],
            // Garage assigned an hour before the driver actually collects the car, so the stage has a real dwell.
            [$m->dispatched_at?->copy()->subHour(), VehicleLogEvent::EVENT_GARAGE_ASSIGNED, $this->inspectorId, 'Garage assigned: ' . ($m->garage ?: 'workshop')],
            [$m->dispatched_at,    VehicleLogEvent::EVENT_DISPATCHED,           $this->driverId,    'Picked up · en route to ' . ($m->garage ?: 'the garage')],
            [$m->repair_started_at, VehicleLogEvent::EVENT_UNDER_REPAIR,        $this->driverId,    'Arrived at ' . ($m->garage ?: 'the garage') . ' — under repair'],
            [$m->ready_at,         VehicleLogEvent::EVENT_READY,                $this->inspectorId, 'Repair finished — awaiting sign-off'],
        ];

        foreach ($plan as [$at, $type, $actorId, $desc]) {
            if ($at === null) {
                continue;
            }
            VehicleLogEvent::create([
                'vehicle_id'      => $m->vehicle_id,
                'maintenance_id'  => $m->id,
                'event_type'      => $type,
                'source_tag'      => VehicleLogEvent::SOURCE_BY_EVENT[$type] ?? Maintenance::FINDING_GARAGE,
                'workflow_status' => $m->workflow_status,
                'description'     => $desc,
                'meta'            => $m->garage ? ['garage' => $m->garage] : null,
                'actor_id'        => $actorId,
                'occurred_at'     => $at,
            ]);
        }
    }

    // Lazily-resolved shared references (a vendor / inspector / driver to hang the tickets off).
    private ?int $vendorId = null;
    private ?string $vendorName = null;
    private ?int $inspectorId = null;
    private ?int $driverId = null;

    private function vendorId(): ?int
    {
        return $this->vendorId;
    }

    private function vendorName(): ?string
    {
        return $this->vendorName;
    }

    private function driverId(): ?int
    {
        return $this->driverId;
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('fresh') && ! $dryRun) {
            $this->line('Wiping the existing board first…');
            Artisan::call('maintenance:reset-workflow', [], $this->getOutput());
        }

        // Shared references. A real GARAGE (not the first vendor, which may be an insurer/parts supplier)
        // + an inspector + a driver are enough to make every stage look real.
        $vendor = Vendor::where('type', 'garage')->orderBy('id')->first()
            ?? Vendor::query()->orderBy('id')->first();
        $this->vendorId   = $vendor?->id;
        $this->vendorName = $vendor?->name;
        $this->inspectorId = User::query()->orderBy('id')->value('id');
        $this->driverId    = User::query()->orderBy('id')->value('id');

        if (! $this->inspectorId) {
            $this->error('No users found to attribute tickets to. Seed a user first.');
            return self::FAILURE;
        }

        // Curated pipeline, optionally truncated to the first N stages.
        $stages = $this->stages();
        if ($count = (int) $this->option('count')) {
            $stages = array_slice($stages, 0, max(1, $count));
        }

        // Distinct active cars with NO open workflow ticket — one per stage.
        $busy = Maintenance::openWorkflow()->whereNotNull('vehicle_id')->pluck('vehicle_id')->all();
        $vehicles = Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->whereNotIn('id', $busy)
            ->orderBy('id')
            ->limit(count($stages))
            ->get(['id', 'plate_no']);

        if ($vehicles->count() < count($stages)) {
            $this->warn(sprintf(
                'Only %d free active car(s) for %d stage(s) — seeding what fits.',
                $vehicles->count(), count($stages)
            ));
            $stages = array_slice($stages, 0, $vehicles->count());
        }

        if (empty($stages)) {
            $this->error('No free active vehicles available to seed. Run maintenance:reset-workflow --all first.');
            return self::FAILURE;
        }

        $rows = [];
        try {
            DB::transaction(function () use ($stages, $vehicles, $dryRun, &$rows) {
                foreach ($stages as $i => $stage) {
                    $vehicle = $vehicles[$i];

                    // Base row; the stage's apply() layers on stage-appropriate fields AND the handoff
                    // timeline (chain()), which owns inspected_by/at etc. so pre-ticket lanes that were
                    // never inspected don't get a phantom inspection stamp.
                    $ticket = [
                        'origin'     => Maintenance::ORIGIN_MANUAL,
                        'vehicle_id' => $vehicle->id,
                    ];
                    ($stage['apply'])($ticket);
                    $ticket['workflow_status'] = $stage['status'];

                    /** @var Maintenance $m */
                    $m = Maintenance::create($ticket);

                    // Backdate the stage anchor so the time-in-stage / SLA chips show variety. Quiet write:
                    // it doesn't touch workflow_status, so the booted() anchor stamp isn't re-triggered.
                    $m->last_state_change_at = now()->subHours($stage['age']);
                    $m->saveQuietly();

                    // Emit the append-only VehicleLogEvent trail so the Vehicle-Status history / timeline
                    // (who did each stage + how long it took) has real data for the seeded car.
                    $this->emitEvents($m);

                    $status = $this->operations->reconcileVehicleOperationalStatus($vehicle);
                    $rows[] = [$m->id, $vehicle->plate_no ?? $vehicle->id, $stage['status'], $status];
                }

                if ($dryRun) {
                    throw new SeedDryRunRollback();
                }
            });
        } catch (SeedDryRunRollback $e) {
            // expected: the transaction rolled back, nothing was written.
        }

        $this->table(['ticket_id', 'car', 'workflow_status', 'car_status'], $rows);
        $this->line(sprintf('Seeded %d ticket(s)%s.', count($rows), $dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->warn('DRY RUN — everything above was rolled back. Re-run without --dry-run to apply.');
        } else {
            $this->info('Done. Refresh the maintenance-workflow board.');
        }

        return self::SUCCESS;
    }
}

/** Internal marker used to roll back a --dry-run transaction. */
class SeedDryRunRollback extends \RuntimeException
{
}
