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

        $user   = $this->confirmingTechnician();
        // Real GARAGES only (type = garage) — an insurance/parts vendor is not where a car gets repaired.
        $vendors = Vendor::query()->where('type', 'garage')->take(3)->get();
        if ($vendors->isEmpty()) {
            $vendors = Vendor::query()->take(3)->get(); // fallback if no garages are typed yet
        }
        // Prefer cars with real mileage so "distance since repair" renders; fall back to any vehicles.
        // Ten rather than four: the first four drive the live scenarios, the rest give the backfilled
        // history enough distinct cars for the "cars that keep coming back" ranking to mean something.
        $vehicles = Vehicle::query()->whereNotNull('odometer')->where('odometer', '>', 2000)
            ->orderByDesc('odometer')->take(10)->get();
        if ($vehicles->count() < 4) {
            $vehicles = Vehicle::query()->take(10)->get();
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

        // BACKFILLED HISTORY — 11 months of already-closed cases so the dashboard charts (12-month trend,
        // decision mix, days-to-return histogram, per-garage and per-car rankings) have a real shape
        // instead of a single spike in the current month.
        //
        // Written DIRECTLY rather than through RecurringFaultService: the detector only matches a previous
        // fix inside the 90-day recurrence window, so a case that opened 8 months ago cannot be reproduced
        // through the live path today. The four scenarios above DO go through the real service, so the
        // workflow itself stays honestly exercised.
        //
        // Runs LAST on purpose: the backfill plants extra completed faults on the same cars, and the
        // detector always matches the MOST RECENT prior fix — seeding it first would silently rewrite the
        // day counts the scenarios above are built to demonstrate.
        $this->history($vehicles, $vendors, $user);

        $this->command?->info('Recurring-fault demo seeded.');
        $this->command?->info('  • /recurring-fault-reviews now shows 3 cases (2 open, 1 decided).');
        $this->command?->line("  • In Workshop car to confirm yourself: {$vehicles[3]->plate_no} (fault \"Battery warning light\").");

        // Each confirmed scenario alerted management — say exactly whose bell to check, since the
        // confirming technician is deliberately excluded from their own alert.
        $audience = User::role(['super-admin', 'admin'])->where('status', 'active')
            ->where('id', '!=', $user->id)->pluck('email');
        $this->command?->line('  • Confirmed as: ' . $user->email . ' (the technician — excluded from the alerts).');
        $this->command?->line('  • 🔔 3 alerts sent to: ' . ($audience->isEmpty() ? 'nobody — no active admin/super-admin!' : $audience->implode(', ')));
        $this->command?->line('  • Teardown: php artisan tinker --execute="Database\\Seeders\\RecurringFaultDemoSeeder::teardown();"');
    }

    /**
     * Who CONFIRMS the fault in the demo. Deliberately a workshop person (supervisor / maintenance /
     * inspector) rather than the first user in the table: the alert excludes its own actor, so
     * confirming as an admin would leave the very bell you want to demo empty. Falls back to any user.
     */
    private function confirmingTechnician(): ?User
    {
        foreach (['supervisor', 'maintenance', 'inspector'] as $role) {
            $user = User::role($role)->where('status', 'active')
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['super-admin', 'admin']))
                ->first();
            if ($user) {
                return $user;
            }
        }

        return User::query()->first();
    }

    /**
     * Backfill 11 months of already-closed review cases so the dashboard has a trend to draw.
     *
     * Shaped on purpose, because a flat demo teaches nothing:
     *   • volume climbs through the year (rework getting worse — the thing the trend chart exists to show)
     *   • every decision code appears, with a slice left undecided so the backlog is visible
     *   • days-to-return spans all four histogram buckets, weighted toward fast failures
     *   • one car and one garage recur far more than the rest, so the rankings have a clear leader
     */
    private function history($vehicles, $vendors, User $user): void
    {
        $symptoms = array_keys(self::PARTS);

        // Cases per month, oldest → newest (11 months back through last month). Rising, with noise.
        $perMonth = [1, 2, 1, 3, 2, 4, 3, 5, 4, 6, 5];

        // Days-since-repair pool, weighted toward fast failures (a repair that never worked).
        $dayPool  = [3, 5, 6, 9, 12, 18, 24, 28, 35, 44, 52, 68, 75];
        $decPool  = [
            RecurringFaultReview::DECISION_SAME_REPAIR_FAILED,
            RecurringFaultReview::DECISION_WORKSHOP_RESPONSIBILITY,
            RecurringFaultReview::DECISION_SAME_REPAIR_FAILED,
            RecurringFaultReview::DECISION_NEW_UNRELATED_FAILURE,
            RecurringFaultReview::DECISION_CUSTOMER_MISUSE,
            RecurringFaultReview::DECISION_WORKSHOP_RESPONSIBILITY,
            RecurringFaultReview::DECISION_INVESTIGATION_REQUIRED,
            null, // a slice stays open, so the backlog shows up in the mix
        ];

        $n = 0;
        foreach ($perMonth as $monthIdx => $count) {
            $monthsAgo = 11 - $monthIdx;

            for ($i = 0; $i < $count; $i++, $n++) {
                // Deterministic spread — same demo every run, no randomness to chase.
                $symptom = $symptoms[$n % count($symptoms)];
                $days    = $dayPool[($n * 5) % count($dayPool)];

                // Car #0 and garage #0 take a double share, so the rankings have an obvious worst offender.
                $vehicle = $vehicles[$n % 3 === 0 ? 0 : ($n % $vehicles->count())];
                $garage  = $vendors[$n % 4 === 0 ? 0 : ($n % $vendors->count())];

                $openedAt = Carbon::now()->subMonths($monthsAgo)->startOfMonth()->addDays(($n * 7) % 26)->addHours(9);
                $decision = $decPool[$n % count($decPool)];
                $verified = $n % 3 === 0;
                $distance = 200 + (($n * 137) % 1800);

                $this->historicalCase($vehicle, $garage, $user, $symptom, $openedAt, $days, $distance, $verified, $decision);
            }
        }

        $this->command?->info('  • Backfilled ' . array_sum($perMonth) . ' historical cases across 11 months (for the charts).');
    }

    /**
     * One backdated review case, with the supporting previous/current tickets and faults so the detail
     * view and every drill-through link still resolve. Tagged with MARKER like everything else here.
     */
    private function historicalCase(
        Vehicle $v,
        Vendor $garage,
        User $user,
        string $symptom,
        Carbon $openedAt,
        int $daysSince,
        int $distance,
        bool $verified,
        ?string $decision,
    ): void {
        $repairedAt = $openedAt->copy()->subDays($daysSince);
        $currentOdo = (int) ($v->odometer ?? 0);
        $prevOdo    = $currentOdo > ($distance + 200) ? $currentOdo - $distance : null;

        $prevTicket = Maintenance::create([
            'vehicle_id'         => $v->id,
            'workflow_status'    => Maintenance::WF_CLOSED,
            'origin'             => 'manual',
            'vendor_id'          => $garage->id,
            'garage_feedback'    => self::MARKER,
            'reinspect_odometer' => $prevOdo,
        ]);

        $prevTask = MaintenanceTask::create([
            'maintenance_id'      => $prevTicket->id,
            'vehicle_id'          => $v->id,
            'symptom'             => $symptom,
            'status'              => MaintenanceTask::STATUS_COMPLETED,
            'current_vendor_id'   => $garage->id,
            'resolved_at'         => $repairedAt,
            'resolved_by'         => $user->id,
            'started_at'          => $repairedAt->copy()->subDays(2),
            'identified_by'       => $user->id,
            'identified_at'       => $repairedAt->copy()->subDays(3),
            'confirmation_status' => MaintenanceTask::CONFIRM_CONFIRMED,
            'confirmed_by'        => $user->id,
            'confirmed_at'        => $repairedAt->copy()->subDays(2),
        ]);

        $parts = [];
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
            $parts[] = ['description' => $desc, 'part_number' => $pn];
        }

        $inspection = null;
        if ($verified) {
            $inspection = RepairInspection::create([
                'maintenance_id'       => $prevTicket->id,
                'vehicle_id'           => $v->id,
                'fault_id'             => $prevTask->id,
                'inspector_id'         => $user->id,
                'result'               => RepairInspection::RESULT_FIXED,
                'previous_vendor_id'   => $garage->id,
                'previous_repaired_at' => $repairedAt,
                'days_since_repair'    => 0,
                'is_recurrence'        => false,
                'inspection_date'      => $repairedAt,
            ]);
        }

        // The car came BACK — this ticket is the recurrence, closed out by now.
        $curTicket = Maintenance::create([
            'vehicle_id'       => $v->id,
            'workflow_status'  => Maintenance::WF_CLOSED,
            'origin'           => 'manual',
            'vendor_id'        => $garage->id,
            'garage_feedback'  => self::MARKER,
            'receive_odometer' => $currentOdo,
        ]);

        $curTask = MaintenanceTask::create([
            'maintenance_id'      => $curTicket->id,
            'vehicle_id'          => $v->id,
            'symptom'             => $symptom,
            'status'              => $decision ? MaintenanceTask::STATUS_COMPLETED : MaintenanceTask::STATUS_PENDING,
            'source'              => Maintenance::FINDING_INSPECTOR,
            'severity'            => Maintenance::FAULT_SEVERITY_MODERATE,
            'current_vendor_id'   => $garage->id,
            'identified_by'       => $user->id,
            'identified_at'       => $openedAt,
            'confirmation_status' => MaintenanceTask::CONFIRM_CONFIRMED,
            'confirmed_by'        => $user->id,
            'confirmed_at'        => $openedAt,
            'resolved_at'         => $decision ? $openedAt->copy()->addDays(3) : null,
            'recurrence_flagged'  => true,
            'recurrence_previous_task_id' => $prevTask->id,
            // A ruled case had its repair released; an undecided one is still frozen.
            'repair_gate'         => $decision ? MaintenanceTask::GATE_APPROVED : MaintenanceTask::GATE_PENDING,
            'repair_gate_by'      => $decision ? $user->id : null,
            'repair_gate_at'      => $decision ? $openedAt->copy()->addHours(4) : null,
        ]);

        RecurringFaultReview::create([
            'status'                  => $decision ? RecurringFaultReview::STATUS_DECIDED : RecurringFaultReview::STATUS_OPEN,
            'decision'                => $decision,
            'vehicle_id'              => $v->id,
            'maintenance_id'          => $curTicket->id,
            'maintenance_task_id'     => $curTask->id,
            'previous_maintenance_id' => $prevTicket->id,
            'previous_task_id'        => $prevTask->id,
            'repair_inspection_id'    => $inspection?->id,
            'symptom'                 => $symptom,
            'previous_garage_id'      => $garage->id,
            'previous_garage_name'    => $garage->name,
            'previous_result'         => $verified ? RecurringFaultReview::RESULT_VERIFIED_FIXED : RecurringFaultReview::RESULT_FIXED,
            'previous_repaired_at'    => $repairedAt,
            'days_since_repair'       => $daysSince,
            'previous_odometer'       => $prevOdo,
            'current_odometer'        => $currentOdo,
            'distance_since_repair'   => $prevOdo !== null ? $distance : null,
            'occurrence_count'        => 2,
            'parts'                   => $parts,
            'context'                 => ['backfilled' => true, 'symptom' => $symptom],
            'opened_by'               => $user->id,
            'opened_by_name'          => $user->name ?: $user->email,
            'opened_at'               => $openedAt,
            'decided_by'              => $decision ? $user->id : null,
            'decided_by_name'         => $decision ? ($user->name ?: $user->email) : null,
            'decided_at'              => $decision ? $openedAt->copy()->addDays(1) : null,
            'decision_note'           => $decision ? 'Backfilled demo history.' : null,
        ]);
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
        // Pull the review ids BEFORE deleting them — their notification keys are how the demo's
        // bell entries are found. Without this, re-running the seeder piles up stale alerts pointing
        // at review cases that no longer exist.
        $reviewIds = RecurringFaultReview::whereIn('maintenance_id', $ids)
            ->orWhereIn('previous_maintenance_id', $ids)->pluck('id');
        if ($reviewIds->isNotEmpty()) {
            \Illuminate\Notifications\DatabaseNotification::query()
                ->whereIn('data->key', $reviewIds->map(fn ($id) => 'recurring_fault_review:' . $id)->all())
                ->delete();
        }

        RecurringFaultReview::whereIn('maintenance_id', $ids)
            ->orWhereIn('previous_maintenance_id', $ids)->delete();
        RepairInspection::whereIn('maintenance_id', $ids)->delete();
        MaintenanceLineItem::whereIn('maintenance_id', $ids)->delete();
        MaintenanceTask::whereIn('maintenance_id', $ids)->delete();
        Maintenance::whereIn('id', $ids)->delete();
    }
}
