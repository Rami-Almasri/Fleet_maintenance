<?php

namespace App\Console\Commands;

use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\VehicleLogEvent;
use App\Services\PartIntelligenceService;
use App\Services\PartWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEMO-ONLY seeder for the "warn before approve" duplicate gate. It leaves a handful of part requests
 * sitting in the REQUESTED state, each backed by a matching earlier purchase on the same vehicle, so that
 * clicking Approve on /parts pops the duplicate warning modal. Covers every flavour:
 *
 *   • Alternator      → MAJOR part, bought 20 days ago  → HIGH   (red modal)
 *   • Blower Motor     → STANDARD part, bought 3 days ago → HIGH  (red — the "twice in days" short-window rule)
 *   • Window Regulator → STANDARD part, bought 25 days ago → MEDIUM (amber modal)
 *   • Suspension Arm   → no prior purchase                → CLEAN  (approves straight through, no modal)
 *
 * Every row is tagged [APPROVAL-DEMO]; run with --clean to delete the lot. Throwaway dev tool: it seeds
 * DATA only through the real service, never touches workflow logic, and the file can be deleted afterwards.
 */
class PartsApprovalDemoSeed extends Command
{
    use Concerns\GuardsDemoWrites;

    protected $signature = 'parts:demo-approval {--clean : Remove all [APPROVAL-DEMO] data instead of creating it}';

    protected $description = 'Seed (or --clean) part requests that trip the approve-time duplicate warning.';

    private const TAG = '[APPROVAL-DEMO]';

    public function handle(PartWorkflowService $svc, PartIntelligenceService $intel): int
    {
        // A demo tool may not manufacture financial rows in the live schema — see GuardsDemoWrites.
        if (! $this->demoWritesAllowed()) {
            return self::FAILURE;
        }

        if ($this->option('clean')) {
            return $this->clean();
        }

        if (PartRequest::where('notes', 'like', '%' . self::TAG . '%')->exists()) {
            $this->warn('Approval-demo data already exists. Run `php artisan parts:demo-approval --clean` first to reset.');
            return self::FAILURE;
        }

        $vehicle = \App\Models\Vehicle::query()->orderBy('id')->first();
        $actor   = User::orderBy('id')->first();
        if (! $vehicle || ! $actor) {
            $this->error('Need at least one vehicle and one user to seed against.');
            return self::FAILURE;
        }
        $now = Carbon::now();

        // Each scenario: [label, part_name, sku, category_key, price, days_since_previous_buy|null].
        // days_since = null → no earlier purchase → a CLEAN request (approves with no modal).
        $scenarios = [
            ['HIGH  (major, 20d ago)',     'Alternator',            'ALT-APPRDEMO', null,         620, 20],
            ['HIGH  (short window, 3d)',   'Blower Motor',          'BLM-APPRDEMO', 'electrical', 180, 3],
            ['MEDIUM(standard, 25d ago)',  'Window Regulator',      'WRG-APPRDEMO', 'electrical', 240, 25],
            ['CLEAN (no prior purchase)',  'Front Suspension Arm',  'SUS-APPRDEMO', null,         310, null],
        ];

        $rows = DB::transaction(function () use ($svc, $intel, $vehicle, $actor, $now, $scenarios) {
            $out = [];
            foreach ($scenarios as [$label, $name, $sku, $cat, $price, $daysAgo]) {
                if ($daysAgo !== null) {
                    $this->priorBuy($intel, $vehicle, $actor, $name, $sku, $cat, $price, $now->copy()->subDays($daysAgo));
                }

                // The live request the user will click Approve on — left in REQUESTED state.
                $req = $svc->createRequest([
                    'source'          => PartRequest::SOURCE_CUSTOMER,
                    'vehicle_id'      => $vehicle->id,
                    'part_name'       => $name,
                    'part_number'     => $sku,
                    'category_key'    => $cat,
                    'repair_location' => PartRequest::LOCATION_GARAGE,
                    'quantity'        => 1,
                    'reason'          => self::TAG . ' ' . $name . ' — approval-gate demo',
                    'estimated_price' => $price,
                    'currency'        => 'AED',
                    'notes'           => self::TAG,
                ], $actor);

                $out[] = [$label, $req->id, $name];
            }
            return $out;
        });

        $this->info('✔ Approval-gate demo data created on vehicle #' . $vehicle->id
            . ' (' . trim($vehicle->make . ' ' . $vehicle->model) . ', plate ' . $vehicle->plate_no . ').');
        $this->line('');
        $this->line('  Open /parts, filter to "Requested", and click Approve on each:');
        foreach ($rows as [$label, $id, $name]) {
            $this->line(sprintf('    request #%-5s %-22s → %s', $id, $name, $label));
        }
        $this->line('');
        $this->line('  Remove everything:  php artisan parts:demo-approval --clean');

        return self::SUCCESS;
    }

    /** A completed request + successful purchase in the past — the "already received this part" trigger. */
    private function priorBuy(PartIntelligenceService $intel, $vehicle, User $actor, string $name, string $sku, ?string $cat, float $price, Carbon $at): void
    {
        $class = $intel->classify($name, $sku, $cat);

        $req = PartRequest::create([
            'source' => PartRequest::SOURCE_CUSTOMER, 'status' => PartRequest::STATUS_COMPLETED,
            'vehicle_id' => $vehicle->id,
            'part_name' => $name, 'part_number' => $sku, 'category_key' => $cat, 'part_class' => $class,
            'repair_location' => PartRequest::LOCATION_GARAGE, 'quantity' => 1,
            'reason' => self::TAG . ' previous ' . $name, 'estimated_price' => $price, 'currency' => 'AED',
            'notes' => self::TAG,
            'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'requested_at' => $at->copy()->subDay(),
            'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => $at->copy()->subDay(),
        ]);

        PartPurchase::create([
            'part_request_id' => $req->id, 'vehicle_id' => $vehicle->id,
            'part_name' => $name, 'part_number' => $sku, 'category_key' => $cat, 'part_class' => $class,
            'purchase_source' => 'supplier', 'source_name' => 'Old Supplier', 'repair_location' => 'garage',
            'purchase_price' => $price, 'currency' => 'AED', 'quantity' => 1,
            'purchased_by' => $actor->id, 'purchased_by_name' => $actor->name, 'purchased_at' => $at,
            'installed_by' => $actor->id, 'installed_by_name' => $actor->name, 'installed_at' => $at,
            'result' => PartPurchase::RESULT_SUCCESS, 'notes' => self::TAG,
        ]);
    }

    /** Delete every [APPROVAL-DEMO] row (and its request-log events) in FK-safe order. Idempotent. */
    private function clean(): int
    {
        DB::transaction(function () {
            $reqIds = PartRequest::where('notes', 'like', '%' . self::TAG . '%')->pluck('id');
            $purIds = PartPurchase::where('notes', 'like', '%' . self::TAG . '%')->pluck('id');

            $logs = VehicleLogEvent::whereIn('meta->part_request_id', $reqIds->all())->delete();
            $pur  = PartPurchase::whereIn('id', $purIds)->delete();
            $rq   = PartRequest::whereIn('id', $reqIds)->delete();

            $this->info("✔ Removed approval-demo data: $rq request(s), $pur purchase(s), $logs log event(s).");
        });

        return self::SUCCESS;
    }
}
