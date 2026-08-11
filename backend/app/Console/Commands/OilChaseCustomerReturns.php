<?php

namespace App\Console\Commands;

use App\Services\OilChangeProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Chase every car whose oil change is DONE but which is still standing in our yard.
 *
 * This is the blind spot a recall creates. We take a car off a paying customer, change the oil, and
 * at that moment every signal in the system says "finished": the ticket closes, the workshop moves
 * on, the follow-up board goes green. Meanwhile the customer is still paying for a car parked at our
 * place, and nothing anywhere is asking anybody to give it back.
 *
 * So this rings — every few minutes, to the people standing next to the car and to the Controllers
 * who will field the phone call — until somebody presses "Returned to the customer". Deliberately
 * more insistent than anything else in the fleet: a daily digest would be useless for a gap measured
 * in hours of a customer's rental.
 *
 * Stateless: it re-reads the world each run and skips anyone rung inside the window, so it is safe
 * to run every minute, to overlap, or to restart mid-sweep.
 *
 *   php artisan oil:chase-returns
 */
class OilChaseCustomerReturns extends Command
{
    protected $signature = 'oil:chase-returns';

    protected $description = 'Remind the fleet to hand back cars whose oil change is done but which are still with us';

    public function handle(OilChangeProjectionService $projection): int
    {
        try {
            $result = $projection->chaseCustomerReturns();
        } catch (\Throwable $e) {
            // A failing chase must never take the scheduler down with it.
            report($e);
            $this->error('Return chase failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result['chased'] > 0) {
            $this->info(sprintf(
                '%d car(s) still with us after the oil change — %d reminder(s) sent.',
                $result['chased'],
                $result['notified'],
            ));
            Log::info('Oil return chase', $result);
        } else {
            $this->info('No cars are waiting to go back.');
        }

        return self::SUCCESS;
    }
}
