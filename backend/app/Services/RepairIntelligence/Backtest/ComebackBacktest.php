<?php

namespace App\Services\RepairIntelligence\Backtest;

use Illuminate\Support\Facades\DB;

/**
 * Scores the comeback rule against history — under either definition of "was it right?".
 *
 * This is the scratch analysis that set the card's operating point, moved into the application so
 * the promotion decision can be made by running it rather than by remembering it.
 *
 * TWO OUTCOME MEASURES, and the whole promotion question is which one to trust:
 *
 *   OUTCOME_RECURRENCE  the same signature reappears on the car within 90 days.
 *                       Available for 27,455 opportunities. A PROXY: a car coming back may be a
 *                       botched repair, or a second unrelated fault in the same system.
 *
 *   OUTCOME_VERDICT     an inspector recorded `still_exists` at the QC gate.
 *                       MEASURED, and scarce — it only exists for tickets that passed the gate.
 *
 * A capability is promoted from the first to the second only if the second scores at least as well.
 * More trustworthy evidence that predicts worse is still worse, and the platform should find that
 * out by measuring rather than by assuming that better data must mean better results.
 */
class ComebackBacktest
{
    public const OUTCOME_RECURRENCE = 'recurrence';
    public const OUTCOME_VERDICT    = 'verdict';

    /**
     * The METHODOLOGY's version — not the data's and not the capability's.
     *
     * Bump when what counts as an opportunity, a positive, or a sufficient sample changes. Two
     * decisions reached under different methodology versions are answers to different questions, and
     * comparing them without noticing is how a platform talks itself into a false reversal.
     */
    public const VERSION = 'v1';

    /** Forward window for the recurrence outcome, matching the card's own methodology. */
    private const FORWARD_DAYS = 90;

    /**
     * @return array{
     *   outcome:string, opportunities:int, positives:int, base_rate:float,
     *   fired:int, tickets:int, precision:float, recall:float, lift:float,
     *   fpr:float, fnr:float, sufficient:bool
     * }
     */
    public function run(string $outcome = self::OUTCOME_RECURRENCE, ?int $window = null, ?int $minPriors = null, ?array $excluded = null): array
    {
        $window    = $window    ?? (int) config('features.intelligence.comeback.window_days', 14);
        $minPriors = $minPriors ?? (int) config('features.intelligence.comeback.min_priors', 1);
        $excluded  = $excluded  ?? (array) config('features.intelligence.comeback.excluded_signatures', []);

        $pairs = $this->opportunities($outcome, $window);

        $tp = $fp = $fn = $tn = 0;
        $tickets = [];

        foreach ($pairs as $p) {
            $fires = ! in_array($p['signature'], $excluded, true) && $p['priors'] >= $minPriors;

            if ($fires) {
                $tickets[$p['ticket']] = true;
            }

            if ($fires && $p['positive'])        { $tp++; }
            elseif ($fires)                      { $fp++; }
            elseif ($p['positive'])              { $fn++; }
            else                                 { $tn++; }
        }

        $n         = count($pairs);
        $positives = $tp + $fn;
        $fired     = $tp + $fp;
        $base      = $n > 0 ? $positives / $n * 100 : 0.0;
        $precision = $fired > 0 ? $tp / $fired * 100 : 0.0;

        return [
            'outcome'       => $outcome,
            'opportunities' => $n,
            'positives'     => $positives,
            'base_rate'     => $base,
            'fired'         => $fired,
            'tickets'       => count($tickets),
            'precision'     => $precision,
            'recall'        => $positives > 0 ? $tp / $positives * 100 : 0.0,
            'lift'          => $base > 0 ? $precision / $base : 0.0,
            'fpr'           => ($fp + $tn) > 0 ? $fp / ($fp + $tn) * 100 : 0.0,
            'fnr'           => $positives > 0 ? $fn / $positives * 100 : 0.0,
            // Below this the comparison is noise, and a promotion decided on noise is worse than no
            // promotion — it would look like evidence.
            'sufficient'    => $fired >= 30 && $positives >= 15,
        ];
    }

    /**
     * The operating point this backtest scored — the concrete parameters behind a model version.
     *
     * Recorded alongside the version string because "comeback/v2" tells a reader which code ran, and
     * only these numbers tell them what it actually did.
     *
     * @return array{window_days:int, min_priors:int, excluded_signatures:array}
     */
    public function operatingPoint(): array
    {
        return [
            'window_days'         => (int) config('features.intelligence.comeback.window_days', 14),
            'min_priors'          => (int) config('features.intelligence.comeback.min_priors', 1),
            'excluded_signatures' => (array) config('features.intelligence.comeback.excluded_signatures', []),
        ];
    }

    /**
     * A model's identity: the capability version, its operating point, and which outcome it is scored
     * against. The same v2 capability tuned differently is a different model and must not be mistaken
     * for the same one.
     */
    public function modelVersion(string $outcome, string $capabilityVersion = 'v2'): string
    {
        $op = $this->operatingPoint();

        return sprintf(
            'comeback/%s+w%dm%dx%d/%s',
            $capabilityVersion,
            $op['window_days'],
            $op['min_priors'],
            count($op['excluded_signatures']),
            $outcome,
        );
    }

    /**
     * A fingerprint of the corpus as it stands: classifier vocabulary, size, and last observation.
     *
     * A projection rebuild changes every answer here without changing a line of code, so a decision
     * that does not record this cannot be told apart from a change of mind later.
     */
    public function datasetVersion(): string
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) n, MAX(occurred_at) last_at, MIN(classifier_version) cv
             FROM maintenance_signatures'
        );

        return sprintf(
            'proj/%s:%d:%s',
            $row->cv ?? 'unknown',
            (int) ($row->n ?? 0),
            $row->last_at ? substr((string) $row->last_at, 0, 10) : 'empty',
        );
    }

    /**
     * Every (ticket, signature) prediction opportunity, with its prior count and its outcome.
     *
     * @return array<int, array{ticket:int, signature:string, priors:int, positive:bool}>
     */
    private function opportunities(string $outcome, int $window): array
    {
        $rows = DB::table('maintenance_signatures')
            ->where('is_exposure', 0)
            ->whereNotNull('vehicle_id')->whereNotNull('occurred_at')
            ->orderBy('vehicle_id')->orderBy('signature')->orderBy('occurred_at')
            ->get(['maintenance_id', 'vehicle_id', 'signature', 'occurred_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $day     = 86400;
        $maxDate = strtotime((string) DB::table('maintenance_signatures')->max('occurred_at'));

        // Verdict outcome: only tickets an inspector actually judged are opportunities at all.
        $verdicts = $outcome === self::OUTCOME_VERDICT
            ? DB::table('repair_inspections')
                // Training data must be conclusive. An `unable_to_verify` row means the inspector
                // could not tell — treating it as "did not fail" would score the platform as right
                // for every car nobody could actually check.
                ->whereIn('result', \App\Models\RepairInspection::CONCLUSIVE_RESULTS)
                ->select('maintenance_id', DB::raw("MAX(result = 'still_exists') AS failed"))
                ->groupBy('maintenance_id')
                ->pluck('failed', 'maintenance_id')
            : collect();

        $chains = [];
        foreach ($rows as $r) {
            $chains[$r->vehicle_id.'|'.$r->signature][] = [strtotime($r->occurred_at), (int) $r->maintenance_id];
        }

        $pairs = [];

        foreach ($chains as $key => $events) {
            [, $signature] = explode('|', $key, 2);
            $dates = array_column($events, 0);
            $count = count($events);

            for ($i = 0; $i < $count; $i++) {
                $t = $dates[$i];
                $ticket = $events[$i][1];

                if ($outcome === self::OUTCOME_VERDICT) {
                    // No verdict ⇒ not an opportunity. Treating unjudged tickets as "did not fail"
                    // would score the platform as right for every car nobody inspected.
                    if (! $verdicts->has($ticket)) {
                        continue;
                    }
                    $positive = (bool) $verdicts[$ticket];
                } else {
                    // Forward window must be fully observed, or a negative is merely censored.
                    if ($t > $maxDate - self::FORWARD_DAYS * $day) {
                        continue;
                    }
                    $positive = false;
                    for ($j = 0; $j < $count; $j++) {
                        if ($dates[$j] <= $t) {
                            continue;
                        }
                        if (($dates[$j] - $t) / $day <= self::FORWARD_DAYS) {
                            $positive = true;
                        }
                        break;
                    }
                }

                // Priors: strictly earlier dates only — same-day rows are one event on two tickets.
                $priors = 0;
                for ($j = 0; $j < $count; $j++) {
                    if ($dates[$j] >= $t) {
                        continue;
                    }
                    if (($t - $dates[$j]) / $day <= $window) {
                        $priors++;
                    }
                }

                $pairs[] = [
                    'ticket'    => $ticket,
                    'signature' => $signature,
                    'priors'    => $priors,
                    'positive'  => $positive,
                ];
            }
        }

        return $pairs;
    }
}
