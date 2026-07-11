<?php

namespace App\Console\Commands;

use App\Http\Controllers\TripDashboardController;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;

/**
 * Pre-computes the Delivery Command / Orders board payload from the Main Trip
 * Dashboard sheet and stores it as the last-good cache copy, so live page loads
 * are always instant cache hits (the ~3s sheet read happens here, off the
 * request path). Scheduled in routes/console.php.
 */
class WarmTripDashboard extends Command
{
    protected $signature = 'trips:warm';

    protected $description = 'Refresh the cached Trip Dashboard payload (Delivery Command / Orders board)';

    public function handle(TripDashboardController $controller, GoogleSheetsService $sheets): int
    {
        $t0 = microtime(true);
        $payload = $controller->warm($sheets);
        $ms = round((microtime(true) - $t0) * 1000);

        $this->info(sprintf(
            'Trip dashboard warmed in %dms — %s trips, %s recent, %d activity days.',
            $ms,
            number_format($payload['kpis']['total_trips'] ?? 0),
            count($payload['recent'] ?? []),
            count($payload['activity'] ?? [])
        ));

        return self::SUCCESS;
    }
}
