<?php

namespace App\Console\Commands;

use App\Services\OilChangeProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Raise the oil-change ticket for every rental that has come back owing one.
 *
 * The mid-rental flow ends in a promise — "we'll change it the moment the car is back" — and this
 * is what keeps it. Three cases converge here, all meaning the same job:
 *   • a controller chose "do it on return" and accepted an overrun;
 *   • a controller chose "recall now" and the car has since landed;
 *   • nobody was ever asked, because the car was projected to finish inside the tolerance — which
 *     is the normal, deliberately-uninterrupted case, and still ends in an oil change.
 *
 * Why a sweep and not just the close hook: OperationsService::closeOperation() settles a rental
 * closed through our own UI, but on the live fleet most contracts are closed by the OfficeManager
 * sync writing `in_date` directly, where no application code runs at all. Without this the promise
 * would hold only for the minority of returns we process ourselves.
 *
 *   php artisan oil:settle-returns --dry-run   # list what would be raised, write nothing
 *   php artisan oil:settle-returns
 */
class OilSettleReturns extends Command
{
    protected $signature = 'oil:settle-returns
        {--dry-run : List the rentals that owe an oil change without raising any ticket}
        {--limit=200 : Safety cap on how many returns to settle in one run}';

    protected $description = 'Open the owed oil-change ticket for rentals that have come back (decided mid-rental, or simply returned past the limit)';

    public function handle(OilChangeProjectionService $projection): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('dry-run')) {
            // Nothing to preview cheaply without duplicating the sweep's own selection logic, so
            // say so plainly rather than print a list that might not match what a real run does.
            $this->warn('Dry run: no tickets raised. Run without --dry-run to settle returned rentals.');

            return self::SUCCESS;
        }

        $result = $projection->settleReturnedRentals($limit);

        // Logged, not just echoed: this runs headless off the schedule tick with no terminal
        // attached, and "did the car that came back yesterday get its ticket" must stay answerable.
        Log::info('Oil settle sweep complete', [
            'report'  => 'oil_settle_returns',
            'settled' => $result['settled'],
            'tickets' => $result['tickets'],
        ]);

        $this->info($result['settled'] === 0
            ? 'No returned rental was owing an oil change.'
            : "Raised {$result['settled']} oil-change ticket(s) for returned rentals.");

        foreach ($result['tickets'] as $contractId => $ticketId) {
            $this->line("  contract {$contractId} → ticket {$ticketId}");
        }

        return self::SUCCESS;
    }
}
