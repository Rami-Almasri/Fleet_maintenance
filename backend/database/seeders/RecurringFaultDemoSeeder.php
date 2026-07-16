<?php

namespace Database\Seeders;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Models\RepairInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Services\MaintenanceTaskService;
use App\Services\RecurringFaultService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * DEMO data for the Recurring-Fault Intelligence feature. NOT wired into DatabaseSeeder — run it on
 * demand:  php artisan db:seed --class=RecurringFaultDemoSeeder
 *
 * It is idempotent: every run first WIPES its own prior demo rows (tagged via garage_feedback = MARKER)
 * and rebuilds them, so re-running never piles up. To remove the demo entirely, see the teardown snippet
 * printed at the end (or run RecurringFaultDemoSeeder::teardown()).
 *
 * Creates, on real fleet vehicles:
 *   • 3 ready-made review cases on the /recurring-fault-reviews page (2 open, 1 decided; 1 verified-fixed,
 *     1 with 3 occurrences) — so the page is populated the moment you open it.
 *   • 1 car sitting In Workshop with a flagged fault you can CONFIRM yourself to watch a review appear.
 *
 * No vehicle odometers are mutated: the previous-repair odometer is derived as (current − distance).
 */
class RecurringFaultDemoSeeder extends Seeder
{
    /** Tag written to maintenances.garage_feedback so demo rows are findable for cleanup. */
    public const MARKER = 'RECUR_DEMO';

    /** Symptom → the parts a previous repair replaced (shown on the review). */
    private const PARTS = [
        'Brake Failure'         => [['Brake Pads', 'BP-2201'], ['Brake Disc', 'BD-8890']],
        'AC not cooling'        => [['AC Compressor', 'ACC-5521'], ['Cabin Filter', 'CF-1180']],
        'Engine overheating'    => [['Radiator', 'RAD-7742'], ['Coolant Hose', 'CH-3310']],
        'Battery warning light' => [['Battery 12V', 'BAT-090'], ['Alternator', 'ALT-4471']],
    ];

    public function run(): void
    {
        $this->teardown(); // idempotent: clear any previous demo run first

        $user   = User::query()->first();
        // Real GARAGES only (type = garage) — an insurance/parts vendor is not where a car gets repaired.
        $vendors = Vendor::query()->where('type', 'garage')->take(3)->get();
        if ($vendors->isEmpty()) {
            $vendors = Vendor::query()->take(3)->get(); // fallback if no garages are typed yet
        }
        // Prefer cars with real mileage so "distance since repair" renders; fall back to any 4 vehicles.
        $vehicles = Vehicle::query()->whereNotNull('odometer')->where('odometer', '>', 2000)
            ->orderByDesc('odometer')->take(4)->get();
        if ($vehicles->count() < 4) {
            $vehicles = Vehicle::query()->take(4)->get();
        }

        if (! $user || $vendors->isEmpty() || $vehicles->count() < 4) {
            $this->command?->error('Need at least 1 user, 1 vendor and 4 vehicles to seed the demo. Aborted.');
            return;
        }

        // Scenario 1 — OPEN review, brakes, 8 days, 1 prior fix.
        $this->scenario($vehicles[0], $vendors[0], $user, 'Brake Failure', 8, [
            'distance' => 420, 'occurrences' => 1, 'confirm' => true,
        ]);

        // Scenario 2 — DECIDED review, AC, 15 days, VERIFIED fixed, repair APPROVED, decision recorded.
        $this->scenario($vehicles[1], $vendors[1 % $vendors->count()], $user, 'AC not cooling', 15, [
            'distance' => 680, 'occurrences' => 1, 'verified' => true, 'confirm' => true, 'gate' => 'approve',
            'decision' => RecurringFaultReview::DECISION_SAME_REPAIR_FAILED,
        ]);

        // Scenario 3 — OPEN review, engine, 40 days, 3 occurrences (2 prior fixes), verified.
        $this->scenario($vehicles[2], $vendors[2 % $vendors->count()], $user, 'Engine overheating', 40, [
            'distance' => 1500, 'occurrences' => 2, 'verified' => true, 'confirm' => true,
        ]);

        // Scenario 4 — INTERACTIVE: car In Workshop, fault flagged but NOT yet confirmed. Open its fault
        // panel on the Workflow board and click "Confirmed" to watch a review get created.
        $this->scenario($vehicles[3], $vendors[0], $user, 'Battery warning light', 12, [
            'distance' => 300, 'occurrences' => 1, 'confirm' => false, 'stage' => Maintenance::WF_UNDER_REPAIR,
        ]);

        $this->command?->info('Recurring-fault demo seeded.');
        $this->command?->info('  • /recurring-fault-reviews now shows 3 cases (2 open, 1 decided).');
        $this->command?->line("  • In Workshop car to confirm yourself: {$vehicles[3]->plate_no} (fault \"Battery warning light\").");
        $this->command?->line('  • Teardown: php artisan tinker --execute="Database\\Seeders\\RecurringFaultDemoSeeder::teardown();"');
    }

    /**
     * Build one scenario: N previous FIXED tickets + a current ticket with the same fault, flag it, and
     * optionally confirm (which opens the review) and decide.
     *
     * @param array{distance?:int, occurrences?:int, verified?:bool, confirm?:bool, decision?:?string, stage?:string} $opts
     */
    private function scenario(Vehicle $v, Vendor $garage, User $user, string $symptom, int $daysAgo, array $opts): void
    {
        $distance    = $opts['distance'] ?? 500;
        $occurrences = max(1, $opts['occurrences'] ?? 1);
        $verified    = $opts['verified'] ?? false;
        $confirm     = $opts['confirm'] ?? false;
        $decision    = $opts['decision'] ?? null;
        $stage       = $opts['stage'] ?? Maintenance::WF_CLOSED;

        $currentOdo = (int) ($v->odometer ?? 0);
        $prevOdo    = $currentOdo > ($distance + 200) ? $currentOdo - $distance : null;

        // --- previous FIXED occurrences (newest first is the one the detector matches) ---
        $latestPrevTask = null;
        for ($i = 0; $i < $occurrences; $i++) {
            $resolvedAt = Carbon::now()->subDays($daysAgo + $i * 20);

            $prevTicket = Maintenance::create([
                'vehicle_id'        => $v->id,
                'workflow_status'   => Maintenance::WF_CLOSED,
                'origin'            => 'manual',
                'vendor_id'         => $garage->id,
                'garage_feedback'   => self::MARKER,
                'reinspect_odometer' => $i === 0 ? $prevOdo : null,
            ]);

            $prevTask = MaintenanceTask::create([
                'maintenance_id'   => $prevTicket->id,
                'vehicle_id'       => $v->id,
                'symptom'          => $symptom,
                'status'           => MaintenanceTask::STATUS_COMPLETED,
                'current_vendor_id' => $garage->id,
                'resolved_at'      => $resolvedAt,
                'resolved_by'      => $user->id,
                'started_at'       => $resolvedAt->copy()->subDays(3), // → "Repair duration: 3 days"
                'identified_by'    => $user->id,
                'identified_at'    => $resolvedAt->copy()->subDays(4),
                'confirmation_status' => MaintenanceTask::CONFIRM_CONFIRMED,
                'confirmed_by'     => $user->id,
                'confirmed_at'     => $resolvedAt->copy()->subDays(3),
            ]);

            if ($i === 0) {
                $latestPrevTask = $prevTask;
                foreach (self::PARTS[$symptom] ?? [] as [$desc, $pn]) {
                    MaintenanceLineItem::create([
                        'maintenance_id'      => $prevTicket->id,
                        'maintenance_task_id' => $prevTask->id,
                        'vehicle_id'          => $v->id,
                        'kind'                => MaintenanceLineItem::KIND_PART,
                        'description'         => $desc,
                        'part_number'         => $pn,
                        'quantity'            => 1,
                        'unit_price'          => 150,
                        'line_total'          => 150,
                    ]);
                }

                if ($verified) {
                    RepairInspection::create([
                        'maintenance_id'       => $prevTicket->id,
                        'vehicle_id'           => $v->id,
                        'fault_id'             => $prevTask->id,
                        'inspector_id'         => $user->id,
                        'result'               => RepairInspection::RESULT_FIXED,
                        'previous_vendor_id'   => $garage->id,
                        'previous_repaired_at' => $resolvedAt,
                        'days_since_repair'    => 0,
                        'is_recurrence'        => false,
                        'inspection_date'      => $resolvedAt,
                    ]);
                }
            }
        }

        // --- current ticket with the SAME fault ---
        $curTicket = Maintenance::create([
            'vehicle_id'      => $v->id,
            'workflow_status' => $stage,
            'origin'          => 'manual',
            'vendor_id'       => $garage->id,
            'garage_feedback' => self::MARKER,
            'receive_odometer' => $stage === Maintenance::WF_UNDER_REPAIR ? $currentOdo : null,
        ]);

        $curTask = MaintenanceTask::create([
            'maintenance_id'    => $curTicket->id,
            'vehicle_id'        => $v->id,
            'symptom'           => $symptom,
            'status'            => MaintenanceTask::STATUS_PENDING,
            'source'            => Maintenance::FINDING_INSPECTOR,
            'severity'          => Maintenance::FAULT_SEVERITY_MODERATE,
            'current_vendor_id' => $garage->id,
            'identified_by'     => $user->id,
            'identified_at'     => Carbon::now(),
        ]);

        // Report-time background flag (silent).
        app(RecurringFaultService::class)->flagPossibleRecurrence($curTask);

        if ($confirm) {
            // Real path: confirming a recurring fault opens the review AND blocks the repair (gate=pending).
            app(MaintenanceTaskService::class)->confirmFault($curTask, MaintenanceTask::CONFIRM_CONFIRMED, $user);
            $curTask->refresh();

            // Optionally clear the repair gate so the demo shows an approved case too.
            if (($opts['gate'] ?? null) === 'approve' && $curTask->repair_gate === MaintenanceTask::GATE_PENDING) {
                app(MaintenanceTaskService::class)->resolveRepairGate($curTask, $user, true, 'Approved for repair — demo.');
            }

            if ($decision) {
                $review = RecurringFaultReview::where('maintenance_task_id', $curTask->id)->first();
                if ($review) {
                    app(RecurringFaultService::class)->decide($review, $user, $decision, 'Demo decision — recorded by seeder.');
                }
            }
        }
    }

    /** Remove every row this seeder created (found via the MARKER tag). Safe to run anytime. */
    public static function teardown(): void
    {
        $ids = Maintenance::where('garage_feedback', self::MARKER)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        RecurringFaultReview::whereIn('maintenance_id', $ids)
            ->orWhereIn('previous_maintenance_id', $ids)->delete();
        RepairInspection::whereIn('maintenance_id', $ids)->delete();
        MaintenanceLineItem::whereIn('maintenance_id', $ids)->delete();
        MaintenanceTask::whereIn('maintenance_id', $ids)->delete();
        Maintenance::whereIn('id', $ids)->delete();
    }
}
