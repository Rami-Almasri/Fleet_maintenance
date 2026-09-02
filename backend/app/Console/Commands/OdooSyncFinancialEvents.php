<?php

namespace App\Console\Commands;

use App\Models\FinancialEvent;
use App\Services\Odoo\FinancialEventSyncService;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Console\Command;

/**
 * Send READY events to Odoo, retry FAILED ones, and resolve anything stranded mid-flight.
 *
 * Deliberately NOT scheduled by default. Posting to an accounting system is a decision somebody owns,
 * and an unattended job that pushes every ready obligation on a timer is how a mis-mapped account turns
 * into three hundred wrong bills before anyone looks. Register it in routes/console.php once the
 * mappings are settled and finance is happy for it to run unattended.
 *
 * Every mode is safe against duplication for the same reason: each push searches Odoo for the event's
 * idempotency ref before it creates anything (see OdooDocumentPusher), so a retry can only ever adopt a
 * document that already exists.
 */
class OdooSyncFinancialEvents extends Command
{
    protected $signature = 'odoo:sync-events
        {--mode=ready : ready|failed|stranded|all}
        {--limit=50 : how many events to process}
        {--dry-run : list what would be sent, send nothing}';

    protected $description = 'Send ready financial events to Odoo (and retry failed / reconcile stranded ones)';

    public function handle(FinancialEventSyncService $sync): int
    {
        $mode  = (string) $this->option('mode');
        $limit = max(1, (int) $this->option('limit'));

        $statuses = match ($mode) {
            'ready'    => [Status::READY],
            'failed'   => [Status::FAILED],
            'stranded' => [Status::SENDING],
            'all'      => [Status::READY, Status::FAILED, Status::SENDING],
            default    => null,
        };

        if ($statuses === null) {
            $this->error("Unknown mode {$mode}. Use ready|failed|stranded|all.");

            return self::FAILURE;
        }

        $events = FinancialEvent::with(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance'])
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($events as $event) {
                $this->line(sprintf(
                    '#%d  %-12s  %-12s  %10s %s  %s',
                    $event->id,
                    $event->status,
                    $event->expense_type,
                    number_format((float) $event->amount, 2),
                    $event->currency,
                    $event->description
                ));
            }
            $this->info($events->count() . ' event(s) would be processed. Nothing was sent.');

            return self::SUCCESS;
        }

        $synced = $failed = 0;

        foreach ($events as $event) {
            $result = $sync->sync($event);

            if ($result->status === Status::SYNCED) {
                $synced++;
                $this->info(sprintf(
                    '#%d → %s %d',
                    $event->id,
                    $result->odoo_document_model,
                    $result->odoo_document_id
                ));
            } else {
                $failed++;
                $this->warn(sprintf('#%d → %s (%s)', $event->id, $result->status, $result->failure_code ?: '—'));
            }
        }

        $this->newLine();
        $this->info("{$synced} synced, {$failed} not.");

        // A run where nothing succeeded is worth a non-zero exit so a cron wrapper notices.
        return $synced === 0 && $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
