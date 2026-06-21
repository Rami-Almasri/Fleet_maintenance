<?php

namespace App\Console\Commands;

use App\Services\NotificationScanner;
use Illuminate\Console\Command;

/**
 * Raise notifications for every current fleet condition (overdue rentals, maintenance
 * overruns, expiring documents, service-due cars, pending approvals). Idempotent — safe to
 * run on a tight schedule; only genuinely new conditions are pushed to each user.
 */
class NotificationsScan extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'Detect live fleet conditions and raise in-app notifications for new ones';

    public function handle(NotificationScanner $scanner): int
    {
        $r = $scanner->scan();

        $this->info(sprintf(
            'Scan complete — %d live condition(s), %d recipient(s), %d new notification(s) raised.',
            $r['alerts'],
            $r['recipients'],
            $r['created']
        ));

        return self::SUCCESS;
    }
}
