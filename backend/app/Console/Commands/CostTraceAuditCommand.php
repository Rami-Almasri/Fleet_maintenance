<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\CostSourceResolver;
use Illuminate\Console\Command;

/**
 * "Can every dirham on every ticket be traced to a document?" — asked of the whole fleet, and answered
 * with a number rather than an impression.
 *
 * This is the standing check behind the audit rule: no figure may appear on a ticket that cannot be tied
 * to a supplier invoice, a garage invoice, a credit note or an approved adjustment. It reports coverage,
 * names the worst offenders, and separates the two ways money goes untraceable, because they need
 * different fixes:
 *
 *   LUMP SUM  — a ticket cost typed straight onto the ticket, with no lines at all. Fixed by itemising
 *               the ticket against its invoices.
 *   UNSOURCED — real lines that were never attached to any document. Fixed by recording the invoice, or
 *               by recording an adjustment that explains the figure.
 *
 * Run it as a gate before trusting any cost report:
 *
 *     php artisan cost:trace-audit
 *     php artisan cost:trace-audit --since=2026-01-01 --limit=40
 *     php artisan cost:trace-audit --only-untraceable
 */
class CostTraceAuditCommand extends Command
{
    protected $signature = 'cost:trace-audit
                            {--since= : Only tickets created on or after this date (Y-m-d)}
                            {--limit=25 : How many offending tickets to list}
                            {--only-untraceable : List only tickets with money that has no document}';

    protected $description = 'Verify every ticket cost traces to a source document (invoice, credit note or adjustment)';

    public function handle(CostSourceResolver $resolver): int
    {
        $query = Maintenance::query()
            ->where(fn ($q) => $q->where('cost', '>', 0)->orWhereHas('lineItems'))
            ->with(['lineItems', 'vehicle:id,plate_no']);

        if ($since = $this->option('since')) {
            $query->whereDate('created_at', '>=', $since);
        }

        $totalTraceable = 0.0;
        $totalUntraceable = 0.0;
        $offenders = [];
        $bySource = [];
        $lumpSumTickets = 0;
        $lumpSumMoney = 0.0;
        $checked = 0;

        $this->info('Tracing every ticket cost to its source document…');
        $bar = $this->output->createProgressBar($query->count());

        $query->chunkById(200, function ($tickets) use (
            $resolver, &$totalTraceable, &$totalUntraceable, &$offenders, &$bySource,
            &$lumpSumTickets, &$lumpSumMoney, &$checked, $bar
        ) {
            foreach ($tickets as $ticket) {
                $audit = $resolver->auditTicket($ticket);
                $checked++;

                $totalTraceable   += $audit['traceable'];
                $totalUntraceable += $audit['untraceable'];

                foreach ($audit['by_source'] as $type => $amount) {
                    $bySource[$type] = round(($bySource[$type] ?? 0) + $amount, 2);
                }

                $lump = collect($audit['untraceable_items'])->firstWhere('kind', 'lump_sum');
                if ($lump) {
                    $lumpSumTickets++;
                    $lumpSumMoney += $lump['amount'];
                }

                if ($audit['untraceable'] != 0.0) {
                    $offenders[] = [
                        'id'          => $ticket->id,
                        'plate'       => $ticket->vehicle?->plate_no ?: '—',
                        'untraceable' => $audit['untraceable'],
                        'coverage'    => $audit['coverage_pct'],
                        'why'         => $lump ? 'lump sum' : 'unsourced lines',
                    ];
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $total = round($totalTraceable + $totalUntraceable, 2);
        $coverage = $total != 0.0 ? round(($totalTraceable / $total) * 100, 1) : 100.0;

        $this->line("Tickets checked:  <options=bold>{$checked}</>");
        $this->line('Total cost:       AED ' . number_format($total, 2));
        $this->line('<fg=green>Traceable:        AED ' . number_format($totalTraceable, 2) . "</> ({$coverage}%)");
        $this->line('<fg=red>Untraceable:      AED ' . number_format($totalUntraceable, 2) . '</> ('
            . round(100 - $coverage, 1) . '%)');
        $this->newLine();

        if ($lumpSumTickets > 0) {
            $this->warn("  {$lumpSumTickets} tickets carry a hand-typed lump sum (AED "
                . number_format($lumpSumMoney, 2) . ') with no lines and no document behind it.');
        }
        $unsourcedLines = round($totalUntraceable - $lumpSumMoney, 2);
        if ($unsourcedLines != 0.0) {
            $this->warn('  AED ' . number_format($unsourcedLines, 2)
                . ' sits on real lines that were never attached to any invoice.');
        }

        $this->newLine();
        $this->line('<options=bold>Money by source document</>');
        $rows = [];
        foreach ($bySource as $type => $amount) {
            $rows[] = [CostSourceResolver::LABELS[$type] ?? $type, 'AED ' . number_format($amount, 2)];
        }
        $this->table(['Source', 'Amount'], $rows ?: [['(nothing recorded)', '—']]);

        if ($offenders) {
            usort($offenders, fn ($a, $b) => $b['untraceable'] <=> $a['untraceable']);
            $limit = max(1, (int) $this->option('limit'));

            $this->newLine();
            $this->line('<options=bold>Worst offenders</> (top ' . min($limit, count($offenders))
                . ' of ' . count($offenders) . ')');
            $this->table(
                ['Ticket', 'Plate', 'Untraceable', 'Coverage', 'Why'],
                collect($offenders)->take($limit)->map(fn ($o) => [
                    '#' . $o['id'], $o['plate'], 'AED ' . number_format($o['untraceable'], 2),
                    $o['coverage'] . '%', $o['why'],
                ])->all(),
            );
        }

        $this->newLine();
        if ($totalUntraceable == 0.0) {
            $this->info('✓ Every dirham on every ticket traces to a source document.');

            return self::SUCCESS;
        }

        // A non-zero exit so this can gate a deploy or a report: the failure is the point of the command.
        $this->error('✗ ' . count($offenders) . ' tickets carry money with no source document.');
        $this->line('  Fix by recording the invoice it came from, or an adjustment that explains it.');

        return self::FAILURE;
    }
}
