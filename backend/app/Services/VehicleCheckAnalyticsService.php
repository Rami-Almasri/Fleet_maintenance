<?php

namespace App\Services;

use App\Models\VehicleCheckEvent;
use App\Models\VehicleCheckRequirement;
use App\Support\VehicleCheckCatalog;
use Illuminate\Support\Carbon;

/**
 * What the obligation trail can now answer that a table of faults never could.
 *
 * A fault log records what was WRONG. It cannot tell you how often the platform asked for a battery
 * check, how many of those an inspector confirmed, how many he overruled, or how long a confirmed
 * problem waited before anyone fixed it — because a check that came back clean left no row at all.
 * Every number below exists because "checked, nothing found" is now a recorded fact.
 *
 * ── EVIDENCE ARTIFACT CONTRACT ─────────────────────────────────────────────────────────────────
 *   Class     : D (derived). Pure aggregation over recorded facts; computed on read, nothing stored.
 *   Consumes  : E19 check obligations (vehicle_check_requirements + vehicle_check_events).
 *   Produces  : NOTHING PERSISTED.
 *   Emits no probability and no confidence score — every figure here is a count or a measured
 *   duration over rows a person or a rule actually created ([[treat-data-as-source-of-truth]]).
 *
 * ── THE ONE NUMBER TO READ CAREFULLY ───────────────────────────────────────────────────────────
 * `override_rate` is how often inspectors answered a raised check with "nothing needed". That is NOT
 * automatically a fault in the rule: a battery check answered OK is a rule working correctly on a car
 * that turned out fine. It becomes interesting when it is HIGH and STABLE for one check type — which
 * is the signal that the threshold is asking about cars that are not in trouble. Read alongside
 * `raised`, never on its own, and never as an accuracy score.
 */
class VehicleCheckAnalyticsService
{
    /**
     * The full report, optionally windowed.
     *
     * @param  int|null  $days  trailing window; null = all time
     */
    public function report(?int $days = 180): array
    {
        $since = $days !== null ? Carbon::now()->subDays($days) : null;

        $rows = VehicleCheckRequirement::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->get();

        $byType = [];
        foreach (VehicleCheckCatalog::typeKeys() as $type) {
            $slice = $rows->where('check_type', $type);
            if ($slice->isEmpty()) {
                continue;   // never raised in this window — a row of zeroes helps nobody
            }
            $byType[] = $this->summarise($type, $slice);
        }

        // Worst first: the types generating the most work, so the page opens on what matters.
        usort($byType, fn ($a, $b) => $b['raised'] <=> $a['raised']);

        return [
            'window_days'  => $days,
            'generated_at' => Carbon::now()->toIso8601String(),
            'totals'       => $this->summarise('all', $rows),
            'by_type'      => $byType,
            // Where every figure came from, in one sentence, because a page that cannot say this is a
            // black box ([[traceability-visibility-requirement]]).
            'origin' => 'Counted directly from vehicle_check_requirements — one row per obligation the '
                      . 'platform raised — and their append-only event trail. No sampling, no inference, '
                      . 'no confidence scores: every number is a count of rows a rule or a person created.',
        ];
    }

    /**
     * One check type's story, from raised to resolved.
     *
     * @param  \Illuminate\Support\Collection<int,VehicleCheckRequirement>  $rows
     */
    private function summarise(string $type, $rows): array
    {
        $raised    = $rows->count();
        $inspected = $rows->filter(fn ($r) => $r->wasInspected());
        $open      = $rows->filter(fn ($r) => $r->isOpen());

        // Answered "no action needed" — the inspector looked and disagreed that work was due.
        $confirmedOk = $inspected->filter(fn ($r) => in_array($r->resolution_code, [
            VehicleCheckRequirement::RESOLUTION_CONFIRMED_OK,
            VehicleCheckRequirement::RESOLUTION_MONITORING,
        ], true));

        $actioned = $rows->filter(fn ($r) => $r->action_id !== null);
        $repaired = $rows->where('resolution_code', VehicleCheckRequirement::RESOLUTION_REPAIRED);
        $deferred = $rows->where('resolution_code', VehicleCheckRequirement::RESOLUTION_DEFERRED);
        $declined = $rows->where('resolution_code', VehicleCheckRequirement::RESOLUTION_DECLINED);
        $expired  = $rows->where('status', VehicleCheckRequirement::STATUS_EXPIRED);

        return [
            'check_type' => $type,
            'label'      => $type === 'all' ? 'All checks' : VehicleCheckCatalog::label($type),

            // ── How many times did the system ask? ──
            'raised'     => $raised,

            // ── How many were actually looked at, and how many never were? ──
            // The pair this whole entity exists to make computable. Before it, both were invisible.
            'inspected'      => $inspected->count(),
            'still_open'     => $open->count(),
            'never_answered' => $expired->count(),

            // ── What did inspectors find? ──
            'confirmed_ok'  => $confirmedOk->count(),
            'action_needed' => $inspected->count() - $confirmedOk->count(),

            // ── What happened next? ──
            'action_created' => $actioned->count(),
            'repaired'       => $repaired->count(),
            'deferred'       => $deferred->count(),
            'declined'       => $declined->count(),

            // ── Rates, each stated as a fraction of a named denominator rather than a bare percent ──
            // Null when the denominator is zero: "no checks were answered" is not "0% were confirmed".
            'inspection_rate' => $this->rate($inspected->count(), $raised),
            // See the class docblock — high is a question about the threshold, not a verdict on it.
            'override_rate'   => $this->rate($confirmedOk->count(), $inspected->count()),
            'repair_rate'     => $this->rate($repaired->count(), $inspected->count()),

            // ── How long did it take? ──
            'median_days_to_inspection' => $this->medianDays($inspected, 'created_at', 'inspected_at'),
            'median_days_to_action'     => $this->medianDays(
                $repaired->filter(fn ($r) => $r->action_completed_at !== null),
                'created_at',
                'action_completed_at',
            ),
        ];
    }

    /**
     * How often each check type is answered with "nothing needed", worst first.
     *
     * The "which recommendations do inspectors keep overriding?" question, answerable only because a
     * clean answer is now a row. Types with too few answers to mean anything are excluded rather than
     * shown with a dramatic-looking rate over three cases.
     */
    public function overrides(int $minimumSample = 10, ?int $days = 180): array
    {
        $report = $this->report($days);

        $rows = array_values(array_filter(
            $report['by_type'],
            fn ($t) => $t['inspected'] >= $minimumSample && $t['override_rate'] !== null,
        ));

        usort($rows, fn ($a, $b) => $b['override_rate'] <=> $a['override_rate']);

        return [
            'minimum_sample' => $minimumSample,
            'window_days'    => $days,
            'rows'           => $rows,
            // Never truncate silently — say what was left out and why.
            'excluded'       => count($report['by_type']) - count($rows),
            'excluded_reason' => 'Check types with fewer than ' . $minimumSample . ' answered checks in the '
                               . 'window. A rate over a handful of cases reads as a finding and is not one.',
        ];
    }

    /** How many events of each kind the trail holds — the cheap health read on the chain itself. */
    public function eventCounts(?int $days = 180): array
    {
        return VehicleCheckEvent::query()
            ->when($days !== null, fn ($q) => $q->where('occurred_at', '>=', Carbon::now()->subDays($days)))
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->all();
    }

    /** A fraction rounded to three places, or null when the denominator is zero. */
    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 3) : null;
    }

    /**
     * Median rather than mean: one check that sat unanswered for eight months would drag an average
     * into telling a story about a single car instead of about the fleet.
     */
    private function medianDays($rows, string $from, string $to): ?float
    {
        $days = $rows
            ->filter(fn ($r) => $r->{$from} && $r->{$to})
            ->map(fn ($r) => $r->{$from}->diffInHours($r->{$to}) / 24)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return null;
        }

        $mid = intdiv($days->count(), 2);

        return round($days->count() % 2
            ? $days[$mid]
            : ($days[$mid - 1] + $days[$mid]) / 2, 1);
    }
}
