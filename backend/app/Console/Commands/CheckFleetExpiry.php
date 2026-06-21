<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

class CheckFleetExpiry extends Command
{
    protected $signature = 'fleet:check-expiry';

    protected $description = 'Flag active vehicles with expired registration/insurance for review (stop renting, trigger sale/maintenance prep).';

    /** Where expired-doc cars are moved so you can review them. */
    public const REVIEW_STATUS = 'for_sale';

    public function handle(): int
    {
        $today = now()->startOfDay()->toDateString();

        $expired = Vehicle::where('status', 'active')
            ->whereHas('registration', function ($q) use ($today) {
                $q->where(function ($q2) use ($today) {
                    $q2->where('expiry_date', '<', $today)
                       ->orWhere('insurance_expiry', '<', $today);
                });
            })
            ->with('registration')
            ->get();

        foreach ($expired as $vehicle) {
            $vehicle->update(['status' => self::REVIEW_STATUS]);

            $reg = $vehicle->registration;
            $why = [];
            if ($reg?->expiry_date && $reg->expiry_date->isPast()) {
                $why[] = 'registration ' . $reg->expiry_date->toDateString();
            }
            if ($reg?->insurance_expiry && $reg->insurance_expiry->isPast()) {
                $why[] = 'insurance ' . $reg->insurance_expiry->toDateString();
            }

            $this->line(sprintf(
                '  flagged %s %s (%s) -> %s [expired: %s]',
                $vehicle->make,
                $vehicle->model,
                $vehicle->plate_no,
                self::REVIEW_STATUS,
                implode(', ', $why)
            ));
        }

        $this->info("Flagged {$expired->count()} vehicle(s) with expired documents as '" . self::REVIEW_STATUS . "'.");

        return self::SUCCESS;
    }
}
