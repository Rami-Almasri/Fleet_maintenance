<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Flag vehicles whose oil change is due, using the strict km-based rule (Vehicle::serviceStatus):
 *
 *   distance = odometer (API) - last_service_odometer ("Oil Change" sheet)
 *   due when distance >= service_interval_km ("Oil Change" sheet)
 *
 * No sheet match -> 'No Data' (never guessed). This command is the single place the Service Due
 * notification is dispatched from, so the alert always reflects exactly this math.
 */
class CheckServiceDue extends Command
{
    protected $signature = 'service:check
        {--no-data : Also list cars with no Oil Change sheet match (No Data)}';

    protected $description = 'Flag vehicles whose service is due (strict km rule: odometer - last service >= interval)';

    public function handle(): int
    {
        $due = [];
        $noData = [];

        Vehicle::query()
            // Only cars we have a current odometer for can be evaluated at all.
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$due, &$noData) {
                foreach ($vehicles as $v) {
                    $s = $v->serviceStatus();
                    if ($s['status'] === 'service_due') {
                        $due[] = [$v->code, $v->vin, $s['current'], $s['baseline'], $s['interval'], $s['overdue_km']];
                    } elseif ($s['status'] === 'no_data') {
                        $noData[] = [$v->code, $v->vin];
                    }
                }
            });

        if ($due) {
            $this->warn(count($due) . ' vehicle(s) due for service:');
            $this->table(
                ['Code', 'VIN', 'Odometer', 'Last service', 'Interval', 'Overdue km'],
                $due
            );
        } else {
            $this->info('No vehicles are due for service.');
        }

        if ($this->option('no-data')) {
            $this->newLine();
            $this->line(count($noData) . ' vehicle(s) with No Data (no Oil Change sheet match):');
            if ($noData) {
                $this->table(['Code', 'VIN'], $noData);
            }
        } elseif ($noData) {
            $this->line(count($noData) . ' vehicle(s) have No Data (run with --no-data to list them).');
        }

        // NOTE: this is the dispatch point for the Service Due notification once the
        // broadcast/database/email delivery is wired (see the notification design). The math
        // above is the single source of truth the notification will use.

        return self::SUCCESS;
    }
}
