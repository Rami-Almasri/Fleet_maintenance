<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\VehicleLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A LOOK-ONLY preview of the enriched part-request lifecycle on the Vehicle Timeline.
 *
 * This writes vehicle_log_events rows ONLY — no part_requests, no part_purchases, no line items, so no
 * money enters the cost chain and nothing rolls up into a ticket, a task or an invoice. It exists so the
 * team can SEE the new wording ("Part purchased from Al Noor Auto Parts: Alternator ×2 — 850.00 AED")
 * against a real page instead of reading it in a diff.
 *
 * Every row it writes carries meta.demo_preview = true, which is the ONLY thing that makes it removable:
 * --purge deletes exactly the rows bearing that flag and nothing else. A demo seeder polluting the live
 * database is a mistake this project has already paid for once, so the flag is written first and the
 * purge is written alongside it, not later.
 */
class PartsTimelinePreview extends Command
{
    protected $signature = 'parts:timeline-preview
        {--vehicle= : Vehicle id to write the preview onto (default: the plate below)}
        {--plate=0059709 : Plate to resolve when --vehicle is not given}
        {--purge : Delete every preview row previously written by this command}';

    protected $description = 'Write (or purge) a look-only part-lifecycle preview on a vehicle timeline';

    public function handle(VehicleLogService $log): int
    {
        if ($this->option('purge')) {
            return $this->purge();
        }

        $vehicle = $this->option('vehicle')
            ? Vehicle::find((int) $this->option('vehicle'))
            : Vehicle::where('plate_no', $this->option('plate'))->first();

        if (! $vehicle) {
            $this->error('No such vehicle.');

            return self::FAILURE;
        }

        // Three different people, so "requested by / approved by / purchased by" are visibly distinct
        // names on the page rather than the same account three times.
        $inspector = User::find(10) ?: User::first();
        $admin     = User::find(2)  ?: User::first();
        $buyer     = User::find(1)  ?: User::first();

        // Walked backwards from today so the rows read in order, one step per day.
        $day = fn (int $agoDays) => Carbon::now()->subDays($agoDays);

        $rows = [
            [$inspector, VehicleLogEvent::EVENT_PART_REQUIRED, 9,
                'Part required: Alternator — listed on the inspection report',
                ['part_name' => 'Alternator', 'category_key' => 'electrical']],

            [$inspector, VehicleLogEvent::EVENT_PART_REQUESTED, 8,
                'Part requested: Alternator (garage) — est. 900.00 AED',
                ['part_class' => 'mechanical', 'source' => 'garage', 'part_number' => 'ALT-2210-A',
                 'quantity' => 2.0, 'estimated_price' => 900.0, 'currency' => 'AED',
                 'reason' => 'Battery warning light, charging voltage low at idle',
                 'requested_by_name' => $inspector?->name]],

            [$admin, VehicleLogEvent::EVENT_PART_APPROVED, 7,
                'Part request approved: Alternator — by ' . ($admin?->name ?? 'Fleet Admin'),
                ['duplicate_ack' => false, 'approved_by_name' => $admin?->name]],

            [$buyer, VehicleLogEvent::EVENT_PART_PURCHASED, 6,
                'Part purchased from Al Noor Auto Parts: Alternator ×2 — 850.00 AED',
                ['source' => 'supplier', 'seller_name' => 'Al Noor Auto Parts', 'source_vendor_id' => null,
                 'po_number' => 'PO-4471', 'quantity' => 2.0, 'price' => 850.0, 'currency' => 'AED',
                 'duplicate' => false]],

            [$buyer, VehicleLogEvent::EVENT_PART_DELIVERED, 4,
                'Part delivered: Alternator (from Al Noor Auto Parts)',
                ['seller_name' => 'Al Noor Auto Parts', 'source_vendor_id' => null]],

            [$inspector, VehicleLogEvent::EVENT_PART_INSTALLED, 3,
                'Part installed: Alternator (success) — 850.00 AED from Al Noor Auto Parts',
                ['result' => 'success', 'seller_name' => 'Al Noor Auto Parts', 'source_vendor_id' => null,
                 'price' => 850.0, 'currency' => 'AED']],

            [$admin, VehicleLogEvent::EVENT_PART_COMPLETED, 2,
                'Part request completed: Alternator',
                []],
        ];

        $written = 0;
        foreach ($rows as [$actor, $event, $agoDays, $description, $meta]) {
            $created = $log->recordVehicle($vehicle, $event, $actor, [
                'source_tag'  => 'parts',
                'description' => $description,
                // The flag that makes this reversible. Never remove it.
                'meta'        => $meta + ['demo_preview' => true],
                'occurred_at' => $day($agoDays),
            ]);
            if ($created) {
                $written++;
            }
        }

        $this->info("Wrote {$written} preview rows onto vehicle {$vehicle->id} ({$vehicle->plate_no}).");
        $this->line("View:  http://localhost:3000/vehicles/{$vehicle->id}?tab=activity");
        $this->line('Undo:  php artisan parts:timeline-preview --purge');

        return self::SUCCESS;
    }

    /** Delete exactly the rows this command wrote — matched on the meta flag, never on event type. */
    private function purge(): int
    {
        $deleted = VehicleLogEvent::whereJsonContains('meta->demo_preview', true)->delete();
        $this->info("Purged {$deleted} preview rows.");

        return self::SUCCESS;
    }
}
