<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\GarageRoutingService;
use Illuminate\Console\Command;

/**
 * Dry-run the Smart Routing Engine against a ticket and print the ranked garage suggestion + the full
 * scoring breakdown. A tuning aid: create rules in `garage_routing_rules` by hand, run this, and read
 * exactly why each garage scored what it did before wiring the engine into the dispatch UI.
 *
 *   php artisan maintenance:route-suggest 123     # explain the suggestion for ticket 123
 *   php artisan maintenance:route-suggest         # pick the newest ticket awaiting dispatch
 */
class MaintenanceRouteSuggest extends Command
{
    protected $signature = 'maintenance:route-suggest {ticket? : Maintenance ticket id (defaults to the newest inspection_pending ticket)}';

    protected $description = 'Dry-run the garage routing engine for a ticket and show the scoring breakdown';

    public function handle(GarageRoutingService $router): int
    {
        $ticket = $this->resolveTicket();
        if (! $ticket) {
            $this->error('No ticket found. Pass a ticket id, or open one to inspection_pending first.');
            return self::FAILURE;
        }

        $ticket->loadMissing('tasks', 'vehicle');
        $result = $router->suggest($ticket);
        $in     = $result['inputs'];

        $this->info("Ticket #{$ticket->id} — {$ticket->workflow_status}");
        $this->line('  Vehicle class : ' . $in['vehicle_class_label'] . " ({$in['vehicle_class']})");
        $this->line('  Fault types   : ' . (empty($in['fault_categories']) ? '(none resolved)' : implode(', ', $in['fault_categories'])));
        $this->line('  Fault severity: ' . ($in['fault_severity'] ?? '—'));
        $this->newLine();

        if (empty($result['candidates'])) {
            $this->warn('No active garages to route to.');
            return self::SUCCESS;
        }

        $this->table(
            ['', 'Garage', 'Score', 'Rating', 'Headline reason', 'Warn'],
            collect($result['candidates'])->map(fn ($c) => [
                $c['is_suggested'] ? '➜' : '',
                $c['garage'],
                $c['score'],
                $c['rating'] ? $c['rating'] . '★' : '—',
                $c['headline_reason'],
                $c['warn'] ? '⚠️' : '',
            ])->all()
        );

        $top = $result['candidates'][0];
        $this->newLine();
        $this->info("Suggested: {$top['garage']} — {$top['headline_reason']}  (score {$top['score']})");

        // Full per-reason breakdown for the winner, so weight tuning is transparent.
        foreach ($top['reasons'] as $r) {
            $sign = $r['points'] >= 0 ? '+' : '';
            $this->line("    {$sign}{$r['points']}  {$r['text']}");
        }
        foreach ($top['warnings'] as $w) {
            $this->warn("    ⚠️  {$w}");
        }

        return self::SUCCESS;
    }

    private function resolveTicket(): ?Maintenance
    {
        $id = $this->argument('ticket');

        if ($id) {
            return Maintenance::find($id);
        }

        return Maintenance::where('workflow_status', Maintenance::WF_INSPECTION_PENDING)
            ->latest('id')
            ->first();
    }
}
