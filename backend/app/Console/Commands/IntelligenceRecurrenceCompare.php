<?php

namespace App\Console\Commands;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LEGACY vs CANONICAL — the report that must be read before any consumer is repointed.
 *
 * ── WHY THIS IS A GATE, NOT A DIAGNOSTIC ─────────────────────────────────────────────────────────
 * Repointing a consumer changes numbers people make decisions on. "The new calculation is better"
 * is not sufficient grounds to move a garage's score in front of the person who chooses garages;
 * the difference has to be quantified, attributed and understood FIRST. This command produces that
 * evidence, per garage, with the difference decomposed into its two causes:
 *
 *   DEDUPLICATION   legacy counted duplicate label rows as separate repairs (2.62x on average,
 *                   1.33x-6.93x per garage). Because a heavily-labelled event that did NOT recur
 *                   contributes several "held" rows, this DILUTED the rate downward.
 *   CENSORING       legacy counted repairs too recent to have failed as repairs that held, which
 *                   flatters every garage and flatters the busiest ones most.
 *
 * Run it, attach the output to the PR, and only then repoint.
 *
 * It reads BOTH definitions — the legacy self-join over raw signatures and the canonical repository
 * — which is the one place in the codebase permitted to do so, and only because its entire purpose
 * is to compare them. It is allowlisted in the architecture guard for exactly that reason, and it
 * becomes deletable once Phase 2 completes.
 *
 * @see docs/Recurrence-Convergence-Implementation-Plan.md  §4 rollout verification
 */
class IntelligenceRecurrenceCompare extends Command
{
    protected $signature = 'intelligence:recurrence-compare
                            {--min=30 : Only report garages with at least this many legacy repairs}
                            {--limit=25 : Rows in the per-garage table}
                            {--json= : Also write the full report to this path}';

    protected $description = 'Compare the legacy raw-signature recurrence with the canonical deduplicated metric, per garage';

    public function handle(RecurrenceRepository $repo): int
    {
        $window = RecurrenceWindow::fromContract();
        $w      = $window->windowDays;

        $this->info("Recurrence: legacy (raw signatures) vs canonical (contract v{$window->version})");
        $this->newLine();

        // ── Fleet ───────────────────────────────────────────────────────────────────────────────
        $legacyFleet    = $this->legacyFleet($w, false);
        $legacyCensored = $this->legacyFleet($w, true);
        $canonUncensored = $repo->fleet(RecurrenceWindow::legacy($w, RecurrenceWindow::HORIZON_NONE));
        $canonical      = $repo->fleet($window);

        $this->line('<options=bold>FLEET — the difference decomposed</>');
        $this->table(['definition', 'n', 'comeback %', 'change'], [
            ['legacy: raw rows, no horizon', number_format($legacyFleet['n']), $this->pct($legacyFleet['pct']), '—'],
            ['+ deduplication', number_format($canonUncensored->n), $this->pct($canonUncensored->rate()),
                $this->delta($legacyFleet['pct'], $canonUncensored->rate())],
            ['+ censoring  = CANONICAL', number_format($canonical->n), $this->pct($canonical->rate()),
                $this->delta($canonUncensored->rate(), $canonical->rate())],
            ['(legacy with censoring only)', number_format($legacyCensored['n']), $this->pct($legacyCensored['pct']), '—'],
        ]);

        $this->line('  <fg=gray>Deduplication RAISES the rate: a duplicated event that did not recur contributed</>');
        $this->line('  <fg=gray>several "held" rows, diluting the measurement downward.</>');
        $this->newLine();

        // ── Per garage ──────────────────────────────────────────────────────────────────────────
        $min       = (int) $this->option('min');
        $floor     = (int) config('metrics.recurrence.min_sample.garage', 30);
        $legacyRows = $this->legacyByGarage($w);
        $canonRows  = $repo->byGarage($window);
        $names      = DB::table('vendors')->pluck('name', 'id');

        $report = [];
        foreach ($legacyRows as $vid => $legacy) {
            if ($legacy['n'] < $min) {
                continue;
            }

            $canon = $canonRows[$vid] ?? null;
            $canonN = $canon?->n ?? 0;

            $report[] = [
                'vendor_id'    => $vid,
                'garage'       => (string) ($names[$vid] ?? "#{$vid}"),
                'legacy_n'     => $legacy['n'],
                'legacy_pct'   => $legacy['pct'],
                'canonical_n'  => $canonN,
                'canonical_pct' => $canon?->rate(),
                'inflation'    => $canonN > 0 ? round($legacy['n'] / $canonN, 2) : null,
                'pct_change'   => ($canon?->rate() !== null && $legacy['pct'] !== null)
                    ? round($canon->rate() - $legacy['pct'], 1) : null,
                'was_scored'   => $legacy['n'] >= $floor,
                'now_scored'   => $canonN >= $floor,
                'reason'       => $this->reason($legacy['n'] >= $floor, $canonN >= $floor),
            ];
        }

        usort($report, fn ($a, $b) => $b['legacy_n'] <=> $a['legacy_n']);

        $this->line('<options=bold>PER GARAGE</>');
        $this->table(
            ['garage', 'legacy n', 'canon n', 'infl.', 'legacy %', 'canon %', 'Δpts', 'scoring'],
            array_map(fn ($r) => [
                mb_substr($r['garage'], 0, 24),
                number_format($r['legacy_n']),
                number_format($r['canonical_n']),
                $r['inflation'] ? $r['inflation'] . 'x' : '—',
                $this->pct($r['legacy_pct']),
                $this->pct($r['canonical_pct']),
                $r['pct_change'] === null ? '—' : sprintf('%+.1f', $r['pct_change']),
                $r['reason'],
            ], array_slice($report, 0, (int) $this->option('limit'))),
        );

        // ── Scoring impact ──────────────────────────────────────────────────────────────────────
        $lost = array_values(array_filter($report, fn ($r) => $r['was_scored'] && ! $r['now_scored']));

        $this->newLine();
        $this->line('<options=bold>SCORING IMPACT</>');
        $this->table(['measure', 'value'], [
            ['garages scored under legacy', count(array_filter($report, fn ($r) => $r['was_scored']))],
            ['garages scored under canonical', count(array_filter($report, fn ($r) => $r['now_scored']))],
            ['LOSE their score', count($lost)],
            ['sample floor', $floor . ' real repairs'],
        ]);

        if ($lost !== []) {
            $this->newLine();
            $this->warn('These garages fall below the floor. They were scored on duplicated rows:');
            foreach (array_slice($lost, 0, 12) as $r) {
                $this->line(sprintf(
                    '  · %-26s legacy n=%-5s canonical n=%-4s (%sx inflated)',
                    mb_substr($r['garage'], 0, 26), $r['legacy_n'], $r['canonical_n'], $r['inflation'] ?? '?'
                ));
            }
        }

        $this->newLine();
        $this->line('<fg=gray>Every difference above is attributable to deduplication or censoring.</>');
        $this->line('<fg=gray>Review this before repointing any consumer — docs/Recurrence-Convergence-Implementation-Plan.md §4.</>');

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'generated_at'   => now()->toIso8601String(),
                'metric_version' => $window->version,
                'window_days'    => $w,
                'fleet' => [
                    'legacy'              => $legacyFleet,
                    'canonical_no_horizon' => ['n' => $canonUncensored->n, 'pct' => $canonUncensored->rate()],
                    'canonical'           => ['n' => $canonical->n, 'pct' => $canonical->rate()],
                ],
                'garages' => $report,
            ], JSON_PRETTY_PRINT));
            $this->info("Full report written to {$path}");
        }

        return self::SUCCESS;
    }

    private function reason(bool $was, bool $now): string
    {
        return match (true) {
            $was && ! $now => 'LOSES score',
            ! $was && $now => 'gains score',
            $now           => 'scored',
            default        => 'unscored',
        };
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2) . '%';
    }

    private function delta(?float $from, ?float $to): string
    {
        return ($from === null || $to === null) ? '—' : sprintf('%+.2f pts', $to - $from);
    }

    /**
     * The retired definition, reproduced verbatim so the comparison is against what actually shipped
     * rather than against a description of it.
     *
     * @return array{n:int, returned:int, pct:?float}
     */
    private function legacyFleet(int $window, bool $withHorizon): array
    {
        $horizon = $withHorizon
            ? ' AND a.occurred_at <= DATE_SUB((SELECT MAX(occurred_at) FROM maintenance_signatures), INTERVAL ? DAY)'
            : '';

        $bindings = $withHorizon ? [$window, $window] : [$window];

        $row = DB::selectOne('
            SELECT COUNT(*) AS n,
                   SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM maintenance_signatures b
                        WHERE b.vehicle_id = a.vehicle_id
                          AND b.signature  = a.signature
                          AND b.occurred_at >  a.occurred_at
                          AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                   ) THEN 1 ELSE 0 END) AS returned
            FROM maintenance_signatures a
            WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0'
            . $horizon, $bindings);

        $n = (int) $row->n;

        return [
            'n'        => $n,
            'returned' => (int) $row->returned,
            'pct'      => $n > 0 ? round($row->returned / $n * 100, 2) : null,
        ];
    }

    /** @return array<int, array{n:int, returned:int, pct:?float}> */
    private function legacyByGarage(int $window): array
    {
        $rows = DB::select('
            SELECT m.vendor_id, COUNT(*) AS n,
                   SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM maintenance_signatures b
                        WHERE b.vehicle_id  = a.vehicle_id
                          AND b.signature   = a.signature
                          AND b.occurred_at > a.occurred_at
                          AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                   ) THEN 1 ELSE 0 END) AS returned
            FROM maintenance_signatures a
            JOIN maintenances m ON m.id = a.maintenance_id
            WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0
              AND m.vendor_id IS NOT NULL
            GROUP BY m.vendor_id', [$window]);

        $out = [];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            $out[(int) $r->vendor_id] = [
                'n'        => $n,
                'returned' => (int) $r->returned,
                'pct'      => $n > 0 ? round($r->returned / $n * 100, 2) : null,
            ];
        }

        return $out;
    }
}
