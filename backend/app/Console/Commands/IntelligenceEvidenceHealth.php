<?php

namespace App\Console\Commands;

use App\Models\CapabilityPromotion;
use App\Models\Maintenance;
use App\Services\Intelligence\Readiness\EvidenceLedger;
use App\Services\Intelligence\Readiness\EvidenceRequirement;
use App\Services\Intelligence\Readiness\PromotionGate;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The intelligence platform's operating KPI.
 *
 * Written after a backtest killed a capability that looked obviously buildable. Garage
 * Recommendation had 31,120 attributed jobs and a fault-mix-standardised spread far beyond chance —
 * and a record that does not persist between periods, so it cannot inform a dispatch decision.
 * Nothing in the codebase could have told us that; only measuring did.
 *
 * So readiness is reported, not argued. Every capability — shipped, planned, or blocked — appears on
 * one page with the same six columns, because a set of measures that cannot be compared is how a
 * platform ends up believing whichever capability has the loudest advocate is closest to ready.
 *
 *   php artisan intelligence:evidence-health
 *   php artisan intelligence:evidence-health --gaps        which tickets closed without a verdict
 *   php artisan intelligence:evidence-health --promote     run the promotion gate and record it
 *   php artisan intelligence:evidence-health --promote --dry-run --force   compare without recording
 */
class IntelligenceEvidenceHealth extends Command
{
    protected $signature = 'intelligence:evidence-health
                            {--gaps : list the closed tickets missing a QC verdict}
                            {--promote : run the proxy→measured promotion gate}
                            {--force : run the comparison even below the evidence threshold}
                            {--dry-run : with --promote, compare but record nothing}
                            {--alert : notify the maintenance managers if verdict capture has stalled}';

    protected $description = 'Evidence readiness KPI for every intelligence capability, and the promotion gate';

    public function handle(EvidenceLedger $ledger, PromotionGate $gate, NotificationScanner $notifier): int
    {
        $this->verdictPipeline($ledger, $notifier);
        $this->healthScores($ledger);
        $this->qcCoverage($ledger);
        $this->readiness($ledger);
        $this->freshness($ledger);
        $this->promotionHistory($gate);

        if ($this->option('promote')) {
            $this->runPromotion($gate);
        }

        if ($this->option('gaps')) {
            $this->gapList();
        }

        return self::SUCCESS;
    }

    /**
     * THE ONE ALERT THIS PLATFORM NEEDS.
     *
     * Everything else here is a dashboard someone chooses to read. This is the thing that has to
     * reach a person unprompted, because the failure it catches is silent: verdict capture stopping.
     * The intelligence layer does not break when that happens — it keeps producing cards from proxy
     * evidence, looking exactly as healthy as before, while the dataset that would let it improve
     * quietly stops growing.
     *
     * Only fires with --alert (the scheduled run) so an operator checking the dashboard by hand never
     * pages the whole management team by accident.
     */
    private function verdictPipeline(EvidenceLedger $ledger, NotificationScanner $notifier): void
    {
        $alert = $ledger->verdictPipelineAlert();

        if ($alert === null) {
            if ($this->option('alert')) {
                $this->info('QC verdict capture is healthy — no alert raised.');
            }

            return;
        }

        $this->newLine();
        $critical = $alert['severity'] === 'critical';
        $render = $critical ? fn ($m) => $this->error($m) : fn ($m) => $this->warn($m);

        $render('  '.strtoupper($alert['severity']).' — '.$alert['headline']);
        $this->line('  '.$alert['detail']);
        $this->line(sprintf(
            '  <fg=gray>%.1f/week now vs %.1f/week before · %d closed without a verdict in 14 days · gate %s</>',
            $alert['recent_rate'], $alert['prior_rate'], $alert['closed_without_verdict'],
            $alert['gate_enabled'] ? 'ON' : 'OFF',
        ));

        if (! $this->option('alert')) {
            $this->line('  <fg=gray>(run with --alert to notify the maintenance managers)</>');

            return;
        }

        // Managers, not inspectors: the fix is a scheduling or configuration decision, and paging the
        // people already doing the work would be noise to them.
        $sent = $notifier->notifyByPermission('maintenance.manage', [
            'type'     => 'intel_verdict_capture',
            'category' => 'maintenance',
            'severity' => $critical ? 'critical' : 'warning',
            'title'    => $alert['headline'],
            'body'     => $alert['detail'],
            'url'      => '/maintenance-workflow',
            // Keyed by severity and week so a persistent problem re-raises weekly rather than either
            // spamming daily or being deduplicated into silence forever.
            'key'      => 'intel:verdict-capture:'.$alert['severity'].':'.now()->format('o-W'),
            'icon'     => 'alert',
            'meta'     => [
                'recent_rate'            => round($alert['recent_rate'], 2),
                'prior_rate'             => round($alert['prior_rate'], 2),
                'closed_without_verdict' => $alert['closed_without_verdict'],
                'gate_enabled'           => $alert['gate_enabled'],
            ],
        ]);

        $this->line(sprintf('  <fg=gray>notified %d manager%s</>', $sent, $sent === 1 ? '' : 's'));
    }

    /**
     * The triage line — which capability to open first.
     *
     * Printed above the detail on purpose, and never instead of it. Each component is shown beside
     * the total so the score can always be taken apart, and the "next" column names a fact about the
     * fleet rather than a target: the way to raise these numbers is to capture better evidence, not
     * to tune the scorer.
     */
    private function healthScores(EvidenceLedger $ledger): void
    {
        $health = $ledger->health();

        $this->newLine();
        $this->line('<comment>CAPABILITY HEALTH — worst first</comment>');

        $rows = [];
        foreach ($health as $h) {
            $d = $h->dimensions;
            $rows[] = [
                $h->capabilityId,
                $this->band($h->band(), $h->score),
                $this->bar($d['coverage']),
                $this->bar($d['volume']),
                $this->bar($d['freshness']),
                $this->bar($d['evaluation']),
                $this->bar($d['trust']),
                mb_substr($h->attention, 0, 44),
            ];
        }

        $this->table(['Capability', 'Health', 'Cov', 'Vol', 'Fresh', 'Eval', 'Trust', 'Next'], $rows);

        // The lowest score is usually a data blocker, and pointing at one every week trains people to
        // ignore the line. Name the worst capability someone can actually do something about.
        $first = $ledger->attentionFirst();

        if ($first !== null) {
            $this->line(sprintf('  <fg=yellow>→</> Act on this first: <options=bold>%s</> — %s', $first->label, $first->attention));
        } else {
            $this->line('  <fg=gray>→ Nothing actionable: every capability is waiting on data the fleet does not yet record.</>');
        }

        $blocked = count(array_filter($health, fn ($h) => $h->blocker !== null));
        if ($blocked > 0) {
            $this->line(sprintf('  <fg=gray>  (%d blocked by data — those need different record-keeping, not engineering.)</>', $blocked));
        }

        $this->line('  <fg=gray>A blocker CAPS the score rather than averaging away — a capability that cannot work cannot be healthy.</>');
    }

    private function band(string $band, float $score): string
    {
        $colour = match ($band) {
            'healthy' => 'green',
            'watch'   => 'yellow',
            default   => 'red',
        };

        return sprintf('<fg=%s>%-8s %3.0f</>', $colour, $band, $score);
    }

    /** Five blocks per dimension — precise enough to compare, coarse enough not to invite tuning. */
    private function bar(float $value): string
    {
        $filled = (int) round(max(0.0, min(1.0, $value)) * 5);

        return str_repeat('█', $filled).str_repeat('·', 5 - $filled);
    }

    /**
     * The headline, because it is the one number that moves everything else. Every ticket that closes
     * without a verdict is a repair outcome nobody can reconstruct — the car has gone.
     */
    private function qcCoverage(EvidenceLedger $ledger): void
    {
        $qc = $ledger->qcCoverage();

        $this->newLine();
        $this->line('<comment>QC VERDICT COVERAGE — the platform\'s only ground truth</comment>');
        $conclusive = $qc['with_verdict'] - ($qc['unverifiable'] ?? 0);

        $this->table(['Metric', 'Value'], [
            ['repaired tickets closed',   number_format($qc['closed'])],
            ['…with a human QC verdict',  number_format($qc['with_verdict'])],
            ['   of which conclusive',    number_format($conclusive).'  (usable for statistics)'],
            ['   could not verify',       number_format($qc['unverifiable'] ?? 0).'  (honest, counts as covered)'],
            ['coverage',                  sprintf('%.0f%%', $qc['coverage'] * 100)],
            ['evidence permanently lost', number_format($qc['lost'])],
        ]);

        // A climbing unverifiable share is its own problem: the queue is being worked, but cars are
        // leaving before anyone can check them. That is a scheduling fix, not a workshop one.
        if ($qc['with_verdict'] > 0 && ($qc['unverifiable'] ?? 0) / $qc['with_verdict'] > 0.25) {
            $this->warn(sprintf(
                '  ⚠ %.0f%% of inspections could not verify the repair — cars are leaving before QC can reach them.',
                ($qc['unverifiable'] / $qc['with_verdict']) * 100,
            ));
        }

        if ($qc['coverage'] < 0.8 && $qc['closed'] > 0) {
            $this->warn('  ⚠ Below 80%. A verdict missed at close cannot be recovered later.');
        }
    }

    private function readiness(EvidenceLedger $ledger): void
    {
        $this->newLine();
        $this->line('<comment>CAPABILITY EVIDENCE READINESS</comment>');

        $rows = [];
        foreach ($ledger->all() as $r) {
            $rows[] = [
                $r->label,
                $r->coverage !== null ? sprintf('%.0f%%', $r->coverage * 100) : '—',
                number_format($r->current).' / '.number_format($r->threshold),
                $r->weeklyRate > 0 ? sprintf('%.1f/wk', $r->weeklyRate) : '—',
                $this->tint($r),
                strtoupper($r->quality),
            ];
        }

        $this->table(['Capability', 'Coverage', 'Evidence', 'Rate', 'Ready', 'Quality'], $rows);

        foreach ($ledger->all() as $r) {
            if ($r->blocker !== null) {
                $this->line(sprintf('  <fg=red>✗</> %-32s %s', $r->capabilityId, $r->blocker));
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>PROXY    = reasoning from a stand-in (a car came back ≠ the repair failed)</>');
        $this->line('  <fg=gray>MEASURED = a human recorded the outcome. Only the promotion gate moves a capability between them.</>');
    }

    /**
     * Evidence FRESHNESS — the failure mode that volume hides.
     *
     * A capability can clear its threshold and still be reasoning about a fleet that no longer
     * exists: 5,000 observations with a two-year median is well-evidenced about the past. Nothing
     * here promotes or demotes anything. It says only that a previous conclusion may have stopped
     * describing today, which is a prompt to re-run the gate, not a verdict on the answer.
     */
    private function freshness(EvidenceLedger $ledger): void
    {
        $this->newLine();
        $this->line('<comment>EVIDENCE FRESHNESS — is what we know still about today\'s fleet?</comment>');

        $rows = [];
        foreach ($ledger->all() as $r) {
            $rows[] = [
                $r->capabilityId,
                $r->medianAgeDays !== null ? $this->days($r->medianAgeDays) : '—',
                $r->oldestAt?->toDateString() ?? '—',
                $r->newestAt?->toDateString() ?? '—',
                $r->datasetAgeDays !== null ? $this->days($r->datasetAgeDays) : '—',
                $r->lastEvaluatedAt?->toDateString() ?? 'never',
            ];
        }

        $this->table(
            ['Capability', 'Median age', 'Oldest', 'Newest', 'Dataset age', 'Last evaluation'],
            $rows
        );

        $flagged = false;
        foreach ($ledger->all() as $r) {
            if ($reason = $r->reevaluationReason()) {
                $this->line(sprintf('  <fg=yellow>↻</> %-24s re-evaluate: %s', $r->capabilityId, $reason));
                $flagged = true;
            }
            if ($r->isEvidenceStale()) {
                $this->line(sprintf('  <fg=yellow>⌛</> %-24s evidence median is over a year old', $r->capabilityId));
                $flagged = true;
            }
            // A dead feed and a healthy one look identical on a count alone.
            if ($r->feedIsQuiet() && $r->current > 0) {
                $this->line(sprintf('  <fg=yellow>◌</> %-24s no new evidence in over 30 days', $r->capabilityId));
                $flagged = true;
            }
        }

        if (! $flagged) {
            $this->line('  <fg=gray>Nothing stale — every standing evaluation still describes the current corpus.</>');
        }
    }

    private function days(int $n): string
    {
        return match (true) {
            $n >= 730 => sprintf('%.1f yrs', $n / 365),
            $n >= 365 => sprintf('%.1f yr', $n / 365),
            $n >= 60  => sprintf('%d mo', (int) round($n / 30.44)),
            default   => "{$n} d",
        };
    }

    private function tint(EvidenceRequirement $r): string
    {
        return match ($r->status()) {
            EvidenceRequirement::STATUS_READY   => '<fg=green>READY</>',
            EvidenceRequirement::STATUS_BLOCKED => '<fg=red>BLOCKED</>',
            default                             => $r->readinessLabel(),
        };
    }

    private function promotionHistory(PromotionGate $gate): void
    {
        if (! DB::getSchemaBuilder()->hasTable('capability_promotions')) {
            return;
        }

        $rows = CapabilityPromotion::orderByDesc('decided_at')->limit(5)->get();

        if ($rows->isEmpty()) {
            return;
        }

        $current = $gate->provenance();

        $this->newLine();
        $this->line('<comment>PROMOTION DECISIONS (append-only — refusals included, deliberately)</comment>');
        $this->table(
            ['When', 'Capability', 'Decision', 'Dataset', 'Backtest', 'Reason'],
            $rows->map(fn ($p) => [
                $p->decided_at?->toDateString(),
                $p->capability_id,
                $p->promoted ? '<fg=green>PROMOTED</>' : 'refused',
                $p->dataset_version ?? '—',
                $p->backtest_version ?? '—',
                mb_substr($p->reason, 0, 46),
            ])->all()
        );

        // A decision is not wrong when the ground moves — it was correct for what it evaluated. But it
        // has stopped being evidence about the present, and should be re-run rather than trusted.
        $latest = $rows->first();
        if ($latest->isSupersededBy($current)) {
            $this->warn('  ⚠ The standing decision was made on a different dataset or methodology — re-run --promote.');
            $this->line('    <fg=gray>then: '.($latest->dataset_version ?? '—').' / backtest '.($latest->backtest_version ?? '—').'</>');
            $this->line('    <fg=gray>now:  '.$current['dataset_version'].' / backtest '.$current['backtest_version'].'</>');
        }
    }

    private function runPromotion(PromotionGate $gate): void
    {
        $this->newLine();
        $this->line('<comment>PROMOTION GATE — proxy → measured</comment>');

        $result = $gate->evaluate(
            capabilityId: 'comeback-warning',
            force: (bool) $this->option('force'),
            persist: ! $this->option('dry-run'),
        );

        if ($result['proxy'] !== null && $result['measured'] !== null) {
            $this->table(
                ['Model', 'Opportunities', 'Fired', 'Precision', 'Base rate', 'Lift'],
                [
                    $this->metricRow('proxy (return rate)', $result['proxy']),
                    $this->metricRow('measured (QC verdict)', $result['measured']),
                ]
            );
        }

        $line = '  '.$result['reason'];
        $result['promoted'] ? $this->info($line) : $this->warn($line);

        // The provenance of the evaluation itself — what was compared, on what data, by what method.
        // Without it, a refusal today and a promotion next quarter are indistinguishable from a
        // change of mind, when they may be two correct answers to two different questions.
        if (! empty($result['provenance'])) {
            $this->newLine();
            $this->line('  <fg=gray>evaluation provenance</>');
            foreach ($result['provenance'] as $k => $v) {
                $this->line(sprintf('    <fg=gray>%-24s %s</>', $k, $v));
            }
        }

        if ($this->option('dry-run')) {
            $this->line('  <fg=gray>Dry run — no decision recorded.</>');
        }
    }

    private function metricRow(string $label, array $m): array
    {
        return [
            $label,
            number_format($m['opportunities']),
            number_format($m['fired']),
            sprintf('%.1f%%', $m['precision']),
            sprintf('%.1f%%', $m['base_rate']),
            sprintf('%.2f×', $m['lift']),
        ];
    }

    /** The chase list — closed tickets whose verdict was never recorded. */
    private function gapList(): void
    {
        $rows = Maintenance::whereNotNull('workflow_status')
            ->whereIn('workflow_status', [Maintenance::WF_CLOSED, Maintenance::WF_AWAITING_INVOICE])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('repair_inspections')
                ->whereColumn('repair_inspections.maintenance_id', 'maintenances.id'))
            ->orderByDesc('id')->limit(50)
            ->get(['id', 'vehicle_id', 'garage', 'workflow_status', 'out_date']);

        $this->newLine();
        $this->line('<comment>CLOSED WITHOUT A VERDICT (most recent 50)</comment>');

        if ($rows->isEmpty()) {
            $this->info('  None — every closed ticket carries a verdict.');

            return;
        }

        $this->table(['Ticket', 'Vehicle', 'Garage', 'State', 'Closed'], $rows->map(fn ($r) => [
            $r->id, $r->vehicle_id, mb_substr((string) $r->garage, 0, 26), $r->workflow_status, $r->out_date,
        ])->all());
    }
}
