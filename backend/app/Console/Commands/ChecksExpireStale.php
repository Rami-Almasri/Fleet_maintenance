<?php

namespace App\Console\Commands;

use App\Services\VehicleCheckService;
use App\Support\VehicleCheckCatalog;
use Illuminate\Console\Command;

/**
 * Retire obligations nobody ever answered, once their evidence is too old to act on.
 *
 * EXPIRED, NEVER DELETED. A battery check raised in May that no inspector ever opened is one of the
 * most valuable rows in the system — it is the platform reporting on itself. Erasing it would restore
 * exactly the blindness [[VehicleCheckRequirement]] was built to remove, so the row stays, its status
 * says `expired`, and its event trail records that it was closed without ever being looked at.
 *
 * What expiry buys is honesty in the OTHER direction: a 90-day-old check is asking about a car that
 * has since been driven, rented and possibly repaired, so leaving it on the Decide step would make an
 * inspector answer a question about a vehicle that no longer exists in that state.
 */
class ChecksExpireStale extends Command
{
    protected $signature = 'checks:expire-stale';

    protected $description = 'Expire system check requirements nobody answered within the staleness window (kept, never deleted).';

    public function handle(VehicleCheckService $checks): int
    {
        $days     = VehicleCheckCatalog::staleAfterDays();
        $expired  = $checks->expireStale();

        $this->info("Expired {$expired} unanswered check requirement(s) older than {$days} days.");
        $this->line('They are retained with status `expired` — "nobody ever checked it" is a finding, not noise.');

        return self::SUCCESS;
    }
}
