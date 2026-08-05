<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceTask;
use App\Models\PartInvestigation;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\User;
use App\Services\PartIntelligenceService;
use App\Services\PartWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEMO-ONLY seeder for the Parts Purchase workflow. Creates a self-contained, fully removable dataset on
 * the FORD MUSTANG (the vehicle behind ticket #170770): one live "under repair" ticket carrying two faults
 * with parts in every lifecycle stage, plus a historical purchase of the same part for the same fault so
 * the "Same Part — Same Fault" duplicate intelligence fires. Every row is tagged [PARTS-DEMO]; run with
 * --clean to delete the lot. This is a throwaway dev tool — it seeds DATA only, it never touches workflow
 * logic, and the command file itself can be deleted afterwards.
 */
class PartsDemoSeed extends Command
{
    use Concerns\GuardsDemoWrites;

    protected $signature = 'parts:demo-seed {--clean : Remove all [PARTS-DEMO] data instead of creating it}';

    protected $description = 'Seed (or --clean) realistic demo data for the Parts Purchase workflow on the Ford Mustang.';

    private const TAG = '[PARTS-DEMO]';

    public function handle(PartWorkflowService $svc, PartIntelligenceService $intel): int
    {
        // A demo tool may not manufacture financial rows in the live schema — see GuardsDemoWrites.
        if (! $this->demoWritesAllowed()) {
            return self::FAILURE;
        }

        if ($this->option('clean')) {
            return $this->clean();
        }

        // Anchor on ticket #170770 → the Ford Mustang; fall back to a make/model lookup if it's gone.
        $template = Maintenance::find(170770);
        $vehicle  = $template?->vehicle
            ?? \App\Models\Vehicle::where('make', 'like', '%FORD%')->where('model', 'like', '%MUSTANG%')->first();

        if (! $vehicle) {
            $this->error('Could not resolve the Ford Mustang vehicle (ticket #170770 missing and no make/model match).');
            return self::FAILURE;
        }
        if (! $template) {
            $this->error('Ticket #170770 not found — needed as the template for a valid under-repair ticket.');
            return self::FAILURE;
        }
        if (Maintenance::where('customer_complaint', 'like', '%' . self::TAG . '%')->exists()) {
            $this->warn('Demo data already exists. Run `php artisan parts:demo-seed --clean` first to reset.');
            return self::FAILURE;
        }

        $actor  = User::orderBy('id')->first();
        $vendor = $template->vendor_id;              // ABDULQADER garage (id 8) on #170770
        $now    = Carbon::now();

        $result = DB::transaction(function () use ($svc, $intel, $template, $vehicle, $actor, $vendor, $now) {

            // ── 1. HISTORICAL closed ticket + fault + purchase (the duplicate trigger) ──────────────
            $histTicket = $template->replicate();
            $histTicket->workflow_status    = Maintenance::WF_CLOSED;
            $histTicket->customer_complaint  = self::TAG . ' Historical brake repair (30 days ago)';
            $histTicket->findings            = [$this->finding('Brake Failure', 'high', $actor)];
            $histTicket->wf_closed_at        = $now->copy()->subDays(30);
            $histTicket->wf_closed_by        = $actor->id;
            $histTicket->last_state_change_at = $now->copy()->subDays(30);
            $histTicket->save();

            $histTask = MaintenanceTask::create([
                'maintenance_id' => $histTicket->id,
                'vehicle_id'     => $vehicle->id,
                'symptom'        => 'Brake Failure',
                'category_key'   => 'brakes',
                'source'         => 'inspector',
                'severity'       => Maintenance::FAULT_SEVERITY_HIGH,
                'status'         => MaintenanceTask::STATUS_COMPLETED,
                'current_vendor_id' => $vendor,
                'identified_by'  => $actor->id,
                'identified_at'  => $now->copy()->subDays(31),
                'resolved_at'    => $now->copy()->subDays(30),
                'notes'          => self::TAG,
            ]);

            // The previous Brake Pads buy — same vehicle, same fault, same part identity (SKU BP-MUS-001).
            $histReq = PartRequest::create([
                'source' => PartRequest::SOURCE_GARAGE, 'status' => PartRequest::STATUS_COMPLETED,
                'vehicle_id' => $vehicle->id, 'maintenance_id' => $histTicket->id, 'maintenance_task_id' => $histTask->id,
                'part_name' => 'Brake Pads', 'part_number' => 'BP-MUS-001', 'category_key' => 'brakes',
                'part_class' => $intel->classify('Brake Pads', 'BP-MUS-001', 'brakes'),
                'repair_location' => PartRequest::LOCATION_GARAGE, 'quantity' => 1,
                'reason' => self::TAG . ' Previous brake pad replacement', 'estimated_price' => 220, 'currency' => 'AED',
                'notes' => self::TAG,
                'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'requested_at' => $now->copy()->subDays(31),
                'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => $now->copy()->subDays(31),
            ]);
            $histPur = PartPurchase::create([
                'part_request_id' => $histReq->id, 'vehicle_id' => $vehicle->id,
                'maintenance_id' => $histTicket->id, 'maintenance_task_id' => $histTask->id,
                'part_name' => 'Brake Pads', 'part_number' => 'BP-MUS-001', 'category_key' => 'brakes',
                'part_class' => $intel->classify('Brake Pads', 'BP-MUS-001', 'brakes'),
                'purchase_source' => 'supplier', 'source_name' => 'ABC Parts', 'repair_location' => 'garage',
                'purchase_price' => 650, 'currency' => 'AED', 'quantity' => 1,
                'purchased_by' => $actor->id, 'purchased_by_name' => $actor->name, 'purchased_at' => $now->copy()->subDays(30),
                'installed_by' => $actor->id, 'installed_by_name' => $actor->name, 'installed_at' => $now->copy()->subDays(30),
                'result' => PartPurchase::RESULT_SUCCESS, 'notes' => self::TAG,
            ]);

            // ── 2. LIVE demo ticket (clone of #170770 so the timeline/handoffs are realistic) ───────
            $ticket = $template->replicate();
            $ticket->workflow_status     = Maintenance::WF_UNDER_REPAIR;
            $ticket->customer_complaint   = self::TAG . ' Parts workflow demo ticket';
            $ticket->findings             = [
                $this->finding('Brake Failure', 'high', $actor),
                $this->finding('Engine Noise', 'moderate', $actor),
            ];
            $ticket->fault_severity       = Maintenance::FAULT_SEVERITY_HIGH;
            $ticket->last_state_change_at = $now;
            $ticket->save();

            $brakeTask = MaintenanceTask::create([
                'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicle->id,
                'symptom' => 'Brake Failure', 'category_key' => 'brakes', 'source' => 'inspector',
                'severity' => Maintenance::FAULT_SEVERITY_HIGH, 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
                'current_vendor_id' => $vendor, 'identified_by' => $actor->id, 'identified_at' => $now, 'notes' => self::TAG,
            ]);
            $engineTask = MaintenanceTask::create([
                'maintenance_id' => $ticket->id, 'vehicle_id' => $vehicle->id,
                'symptom' => 'Engine Noise', 'category_key' => 'engine', 'source' => 'inspector',
                'severity' => Maintenance::FAULT_SEVERITY_MODERATE, 'status' => MaintenanceTask::STATUS_IN_PROGRESS,
                'current_vendor_id' => $vendor, 'identified_by' => $actor->id, 'identified_at' => $now, 'notes' => self::TAG,
            ]);

            // ── 3. LIVE parts, driven through the REAL service so statuses/costs/logs are consistent ─
            // Fault 1 — Brake Failure
            $this->part($svc, $vehicle, $ticket, $brakeTask, $actor, $vendor, [
                'part_name' => 'Brake Pads', 'part_number' => 'BP-MUS-001', 'category_key' => 'brakes', 'price' => 245,
            ], 'installed');   // same SKU + same fault as history → duplicate investigation auto-opens
            $this->part($svc, $vehicle, $ticket, $brakeTask, $actor, $vendor, [
                'part_name' => 'Brake Disc', 'part_number' => 'BD-MUS-002', 'category_key' => 'brakes', 'price' => 480,
            ], 'purchased');
            $this->part($svc, $vehicle, $ticket, $brakeTask, $actor, $vendor, [
                'part_name' => 'Brake Fluid', 'part_number' => null, 'category_key' => 'fluids', 'price' => 60,
            ], 'requested');

            // Fault 2 — Engine Noise
            $this->part($svc, $vehicle, $ticket, $engineTask, $actor, $vendor, [
                'part_name' => 'Engine Mount', 'part_number' => 'EM-MUS-003', 'category_key' => 'engine', 'price' => 900,
            ], 'installed');
            $this->part($svc, $vehicle, $ticket, $engineTask, $actor, $vendor, [
                'part_name' => 'Engine Oil', 'part_number' => null, 'category_key' => 'fluids', 'price' => 180,
            ], 'requested');

            $investigation = PartInvestigation::where('vehicle_id', $vehicle->id)
                ->where('type', PartInvestigation::TYPE_DUPLICATE_PURCHASE)->latest('id')->first();

            return compact('ticket', 'histTicket', 'brakeTask', 'engineTask', 'histTask', 'histPur', 'investigation');
        });

        $this->report($vehicle, $result);

        return self::SUCCESS;
    }

    /** Drive one part request to the requested / purchased / installed stage via the real service. */
    private function part(PartWorkflowService $svc, $vehicle, Maintenance $ticket, MaintenanceTask $task, User $actor, ?int $vendor, array $p, string $stage): void
    {
        $req = $svc->createRequest([
            'source' => PartRequest::SOURCE_GARAGE, 'vehicle_id' => $vehicle->id,
            'maintenance_id' => $ticket->id, 'maintenance_task_id' => $task->id,
            'part_name' => $p['part_name'], 'part_number' => $p['part_number'], 'category_key' => $p['category_key'],
            'repair_location' => PartRequest::LOCATION_GARAGE, 'quantity' => 1,
            'reason' => self::TAG . ' ' . $p['part_name'] . ' needed for ' . $task->symptom,
            'estimated_price' => $p['price'], 'currency' => 'AED', 'notes' => self::TAG,
        ], $actor);

        if ($stage === 'requested') {
            return;
        }

        $svc->approve($req, $actor);
        $res = $svc->purchase($req, [
            'purchase_source' => 'garage', 'source_vendor_id' => $vendor, 'purchase_price' => $p['price'],
            'currency' => 'AED', 'quantity' => 1, 'repair_location' => 'garage', 'notes' => self::TAG,
        ], $actor);

        if ($stage === 'installed') {
            $svc->installPurchase($res['purchase'], [
                'installed_odometer' => $ticket->receive_odometer, 'warranty_months' => 12,
                'result' => PartPurchase::RESULT_SUCCESS, 'notes' => self::TAG,
            ], $actor);
        }
    }

    /** A findings-JSON entry (marked demo=>true so cleanup can strip it if ever appended to a real ticket). */
    private function finding(string $text, string $severity, User $actor): array
    {
        return [
            'text' => $text, 'source' => 'inspector', 'severity' => $severity,
            'root_cause' => null, 'root_cause_id' => null,
            'by' => $actor->name, 'at' => Carbon::now()->toIso8601String(), 'demo' => true,
        ];
    }

    private function report($vehicle, array $r): void
    {
        $this->info('✔ Parts demo data created.');
        $this->line('');
        $this->line("  Vehicle ID ............ {$vehicle->id}  ({$vehicle->make} {$vehicle->model}, plate {$vehicle->plate_no})");
        $this->line("  Live demo ticket ...... #{$r['ticket']->id}   (under repair)");
        $this->line("  Historical ticket ..... #{$r['histTicket']->id}   (closed, 30 days ago)");
        $this->line('');
        $this->line("  Fault #1 (Brake Failure) task ID .. {$r['brakeTask']->id}");
        $this->line("     • Brake Pads  → Installed   (SKU BP-MUS-001 — trips Same Part / Same Fault)");
        $this->line("     • Brake Disc  → Purchased");
        $this->line("     • Brake Fluid → Requested");
        $this->line("  Fault #2 (Engine Noise) task ID ... {$r['engineTask']->id}");
        $this->line("     • Engine Mount → Installed");
        $this->line("     • Engine Oil   → Requested");
        $this->line('');
        $this->line("  Historical fault task ID .......... {$r['histTask']->id}");
        $this->line("  Historical purchase ID ............ {$r['histPur']->id}  (Brake Pads, ABC Parts, 650 AED, 30d ago)");
        $this->line('  Duplicate investigation ID ........ ' . ($r['investigation']->id ?? '(none opened)'));
        $this->line('');
        $this->line("  Open it in the UI:  /maintenance-workflow/{$r['ticket']->id}");
        $this->line('  Remove everything:  php artisan parts:demo-seed --clean');
    }

    /** Delete every [PARTS-DEMO] row in FK-safe order. Idempotent. */
    private function clean(): int
    {
        DB::transaction(function () {
            $ticketIds = Maintenance::where('customer_complaint', 'like', '%' . self::TAG . '%')->pluck('id');
            $taskIds   = MaintenanceTask::where('notes', 'like', '%' . self::TAG . '%')->pluck('id');
            $reqIds    = PartRequest::where('notes', 'like', '%' . self::TAG . '%')->pluck('id');
            $purIds    = PartPurchase::where('notes', 'like', '%' . self::TAG . '%')->pluck('id');

            $inv = PartInvestigation::whereIn('part_purchase_id', $purIds)
                ->orWhereIn('previous_purchase_id', $purIds)->delete();
            $pur = PartPurchase::whereIn('id', $purIds)->delete();
            $li  = MaintenanceLineItem::whereIn('maintenance_task_id', $taskIds)->where('entry_source', 'purchase')->delete();
            $rq  = PartRequest::whereIn('id', $reqIds)->delete();
            $tk  = MaintenanceTask::whereIn('id', $taskIds)->delete();
            \App\Models\VehicleLogEvent::whereIn('maintenance_id', $ticketIds)->delete();
            $mt  = Maintenance::whereIn('id', $ticketIds)->delete();

            $this->info('✔ Removed demo data: '
                . "$mt ticket(s), $tk fault(s), $rq request(s), $pur purchase(s), $li line-item(s), $inv investigation(s).");
        });

        return self::SUCCESS;
    }
}
