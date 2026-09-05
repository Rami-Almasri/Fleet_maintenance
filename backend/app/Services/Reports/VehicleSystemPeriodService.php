<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "BETWEEN THESE TWO DATES, WHAT WENT WRONG WITH THIS CAR?"
 *
 * The system dashboard already establishes what the records mean — VehicleSystemEvidenceService turns
 * rows into incidents, decides which of them name a real fault, and reads the work out of the notes.
 * What it does not do is answer the question a manager actually arrives with, which is not "how many
 * incidents" but *which problems, how many times each, on what days, what did the workshop write, and
 * what was done about it.*
 *
 * This service is that regrouping and nothing more. It invents no vocabulary, re-reads no text and
 * re-decides nothing: every fault name, every normalised key, every work claim and every strength
 * grade is taken from the incidents it is handed. Give it the same incidents and it returns the same
 * summary — it is a view, not a second opinion.
 *
 * THE THREE COUNTS THAT MUST NEVER BE CONFLATED (this is the whole reason the service exists):
 *
 *   SYSTEM VISITS   — how many times the car was recorded against this system in the period.
 *   NAMED FAULTS    — how many DISTINCT problems were actually identified. Three engine visits where
 *                     only one names a fault are three visits and one named fault.
 *   REPEATED FAULTS — how many of those named faults appear in more than one incident. A second visit
 *                     to the engine is not a repeat of the first visit's fault, and saying so on a
 *                     report is how a fleet ends up replacing a part that was never at fault.
 *
 * WORKSHOP-ONLY VISITS are reported separately and by name, so the gap between "3 visits" and
 * "2 problems" is visible rather than looking like an arithmetic error.
 *
 * @see VehicleSystemEvidenceService for how an incident is formed and what `is_confirmed` means.
 */
class VehicleSystemPeriodService
{
    /**
     * Work claims, strongest first. The first one an incident carries is the action reported for that
     * occurrence — "replaced and later checked" is a replacement, not an inspection.
     */
    private const ACTION_RANK = [
        'replacement' => 0,
        'repair'      => 1,
        'inspection'  => 2,
        'recommended' => 3,
        'mention'     => 4,
    ];

    /** Actions that record work actually CARRIED OUT, as opposed to proposed or merely mentioned. */
    private const COMPLETED_ACTIONS = ['replacement', 'repair'];

    /**
     * How firmly the record supports the action, using the grades the evidence layer already assigns.
     * Nothing is upgraded here: a recommendation stays a recommendation however it is worded.
     */
    private const ACTION_EVIDENCE = [
        'replacement' => 'strong',      // a completed-work verb naming this system's component
        'repair'      => 'strong',
        'inspection'  => 'strong',
        'recommended' => 'mentioned',   // the note proposes it; nobody wrote that it was done
        'mention'     => 'none',
        'not_recorded'=> 'none',
    ];

    /**
     * Build the period view over an already-analysed set of incidents.
     *
     * @param  array<int, array>   $incidents   VehicleSystemEvidenceService incidents, oldest first.
     * @param  array<int, array>   $durability  Its durability ledger (completed work + what followed).
     * @param  Collection<int,array> $events    The collapsed source records the incidents were built from.
     */
    public function build(array $incidents, array $durability, Collection $events): array
    {
        $ordered  = collect($incidents)->sortBy('start')->values();
        $problems = $this->problems($ordered);

        return [
            'summary'      => $this->summary($ordered, $problems, $events),
            'problems'     => $problems,
            'workshop_only'=> $this->workshopOnly($ordered),
            'repairs'      => $this->completedWork($ordered, $durability),
        ];
    }

    // ── The grouped answer ──────────────────────────────────────────────────────────────────────

    /**
     * ONE ENTRY PER NAMED FAULT, not one per visit.
     *
     * Grouped on the identities the evidence layer already computed for the incident (lowercased,
     * stripped of punctuation and of parenthetical asides). Two spellings of one fault therefore group;
     * two genuinely different faults never do, because nothing here merges beyond an exact key match.
     * If the platform's own vocabulary cannot say two descriptions are the same fault, they stay apart:
     * an over-merged report claims a recurrence that never happened, which is the more expensive error.
     *
     * ONE INCIDENT CAN APPEAR UNDER SEVERAL PROBLEMS, and must. A visit that found a rim scratch and a
     * paint fault named two faults, so it is one visit and two entries here — which is why the summary
     * counts visits separately from problems and never adds these rows up as trips.
     * @see VehicleSystemEvidenceService::faultParts for why identity is taken phrase by phrase.
     *
     * @param  Collection<int, array>  $incidents
     */
    private function problems(Collection $incidents): array
    {
        // One (fault, incident) pair per named fault. `faults` is the evidence layer's list; an
        // incident from an older payload that carries none falls back to its single identity, so the
        // grouping degrades to exactly what it was rather than to nothing.
        $expanded = $incidents
            ->where('is_confirmed', true)
            ->values()
            ->flatMap(fn (array $i) => collect($i['faults'] ?? [])
                ->whenEmpty(fn () => collect(array_filter([
                    $i['fault_key'] ? ['text' => $i['fault'], 'key' => $i['fault_key']] : null,
                ])))
                // array_merge, NOT `+`: the union operator keeps the LEFT operand's key, so the
                // incident's own composite fault would survive and every group would be labelled with
                // the whole string it was split out of.
                ->map(fn (array $f) => array_merge($i, ['fault' => $f['text'], 'fault_key' => $f['key']])));

        return $expanded
            ->groupBy('fault_key')
            // "What came next" is asked of the EXPANDED list too: the next thing that happened to this
            // car is a fault it named, not the composite string a row happened to be written as.
            ->map(function (Collection $group) use ($expanded) {
                $ordered     = $group->sortBy('start')->values();
                $occurrences = $this->occurrences($ordered);
                $dates       = $ordered->pluck('start')->filter()->values();
                $count       = $ordered->count();

                return [
                    // The fault as it was FIRST written. The report quotes the record; it does not
                    // pick a preferred spelling or tidy the garage's wording.
                    'fault'       => $ordered->first()['fault'],
                    'fault_key'   => $ordered->first()['fault_key'],
                    'occurrences' => $count,
                    'repeated'    => $count > 1,
                    'first_seen'  => $dates->first(),
                    'last_seen'   => $dates->last(),
                    'span_days'   => $dates->count() > 1
                        ? $this->days($dates->first(), $dates->last())
                        : null,
                    'garages'     => $ordered->flatMap(fn (array $i) => $i['garages'])->unique()->values()->all(),
                    'worst_severity' => $this->worstSeverity($ordered),
                    'strength'    => $this->bestStrength($ordered),
                    'repairs_recorded' => collect($occurrences)
                        ->whereIn('action', self::COMPLETED_ACTIONS)->count(),
                    'events'      => $occurrences,
                    'returned'    => $this->returned($ordered, $occurrences),
                    // §"the next event is a different fault" — stated so the reader can see that the
                    // car came back for something else and that this is NOT a recurrence of this fault.
                    'followed_by' => $this->followedByDifferentFault($ordered->last(), $expanded),
                ];
            })
            // Most-repeated first, then most recent: the thing that keeps happening leads the page.
            ->sortByDesc(fn (array $p) => [$p['occurrences'], $p['last_seen']])
            ->values()
            ->all();
    }

    /**
     * One occurrence = one incident. Everything a reader needs to check the claim is on the line:
     * the day, the garage, THE GARAGE'S OWN WORDS, what was done, and how many source rows sit behind
     * it.
     *
     * @param  Collection<int, array>  $incidents
     */
    private function occurrences(Collection $incidents): array
    {
        $previous = null;

        return $incidents->map(function (array $i) use (&$previous) {
            $gap      = $previous ? $this->days($previous, $i['start']) : null;
            $previous = $i['start'] ?? $previous;

            $work = $this->strongestWork($i);

            return [
                'date'       => $i['start'],
                'end'        => $i['end'],
                'garage'     => $i['garages'] ? implode(', ', $i['garages']) : null,
                'garages'    => $i['garages'],
                'severity'   => $i['severity'],
                // THE NOTE, PRESERVED. `note_lines` are the lines of the visit note that belong to this
                // system; `all_note_lines` is everything the visit recorded. Neither is a summary and
                // neither is the normalised fault name — both are shown so the normalisation can be
                // checked against the text it came from.
                'note_lines'     => $this->noteLines($i, false),
                'all_note_lines' => $this->noteLines($i, true),
                'action'         => $work['action'],
                'action_text'    => $work['text'],
                'action_component' => $work['component'],
                'action_evidence'=> self::ACTION_EVIDENCE[$work['action']] ?? 'none',
                'outcome'        => $i['outcome'],
                'strength'       => $i['strength'],
                'kind'           => $i['kind'],
                'row_count'      => $i['row_count'],
                'sources'        => $i['sources'],
                'refs'           => collect($i['records'])->flatMap(fn ($r) => $r['refs'] ?? [])->unique()->values()->all(),
                'odometer'       => $i['odometer'],
                // Days since the PREVIOUS occurrence OF THIS SAME FAULT. Null on the first.
                'gap_days'       => $gap,
            ];
        })->all();
    }

    /**
     * The lines of the note, in the order they were written, with nothing rewritten.
     *
     * `$all = false` keeps the lines the evidence layer attributed to this system, so a rim scratch
     * logged in the same visit does not sit under an engine fault. When that filtering leaves nothing
     * — the fault came from the finding column and the note talks about something else — the whole
     * note is returned instead: an unattributable note is still the record, and hiding it would be a
     * worse failure than showing it unsorted.
     */
    private function noteLines(array $incident, bool $all): array
    {
        $lines = collect($incident['records'])
            ->flatMap(fn (array $r) => $all ? ($r['all_lines'] ?? []) : ($r['system_lines'] ?? []))
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->unique(fn ($l) => mb_strtolower($l))
            ->values()
            ->all();

        if (! $all && $lines === []) {
            return $this->noteLines($incident, true);
        }

        return $lines;
    }

    /** The strongest work claim this incident carries, or "nothing was recorded". */
    private function strongestWork(array $incident): array
    {
        $best = collect($incident['work'] ?? [])
            ->sortBy(fn (array $w) => self::ACTION_RANK[$w['action']] ?? 9)
            ->first();

        // A bare "mention" names a component without saying anything was done to it. Reporting that as
        // an action would tell a reader work happened when the note only referred to a part.
        if ($best === null || $best['action'] === 'mention') {
            return ['action' => 'not_recorded', 'text' => null, 'component' => null];
        }

        return ['action' => $best['action'], 'text' => $best['text'], 'component' => $best['component']];
    }

    /**
     * DID IT COME BACK? — answered from this fault's own occurrences and nothing else.
     *
     * A second visit to the same SYSTEM is not this fault returning, so system activity is deliberately
     * not consulted here. `after_repair` is only true when completed work was recorded at an earlier
     * occurrence and the same fault was logged again after it — the record shows the sequence, and the
     * sequence is all that is claimed.
     */
    private function returned(Collection $incidents, array $occurrences): array
    {
        if (count($occurrences) < 2) {
            return ['status' => 'no', 'days' => null, 'from' => null, 'to' => null, 'after_repair' => false];
        }

        $last     = $occurrences[count($occurrences) - 1];
        $previous = $occurrences[count($occurrences) - 2];

        $afterRepair = false;
        foreach ($occurrences as $index => $occurrence) {
            if ($index < count($occurrences) - 1 && in_array($occurrence['action'], self::COMPLETED_ACTIONS, true)) {
                $afterRepair = true;
                break;
            }
        }

        return [
            'status'       => 'yes',
            'days'         => $last['gap_days'],
            'from'         => $previous['date'],
            'to'           => $last['date'],
            'times'        => count($occurrences),
            'after_repair' => $afterRepair,
        ];
    }

    /**
     * The next NAMED fault recorded after this one's final occurrence, when it is a different fault.
     *
     * Stated explicitly because the alternative reading is the dangerous one: a car that goes back in
     * for something else looks, on a chronological list, exactly like a fault that returned.
     *
     * @param  Collection<int, array>  $incidents  every incident in the period
     */
    private function followedByDifferentFault(?array $lastOccurrence, Collection $incidents): ?array
    {
        if (! $lastOccurrence) {
            return null;
        }

        $next = $incidents
            ->where('is_confirmed', true)
            ->filter(fn (array $i) => $i['start'] > $lastOccurrence['start']
                && $i['fault_key'] !== $lastOccurrence['fault_key'])
            ->sortBy('start')
            ->first();

        return $next ? ['date' => $next['start'], 'fault' => $next['fault']] : null;
    }

    // ── Visits that named nothing ───────────────────────────────────────────────────────────────

    /**
     * The visits this system was recorded against where no fault was ever named.
     *
     * These are the difference between "3 visits" and "2 problems", and they are shown rather than
     * dropped: a workshop visit is a real event and a fleet paid for it. What they are NOT is evidence
     * of a fault, so they never enter the problem list and never count towards recurrence.
     *
     * @param  Collection<int, array>  $incidents
     */
    private function workshopOnly(Collection $incidents): array
    {
        return $incidents
            ->where('is_confirmed', false)
            ->map(function (array $i) {
                $work = $this->strongestWork($i);

                return [
                    'date'        => $i['start'],
                    'end'         => $i['end'],
                    'garage'      => $i['garages'] ? implode(', ', $i['garages']) : null,
                    'garages'     => $i['garages'],
                    'kind'        => $i['kind'],
                    'strength'    => $i['strength'],
                    'note_lines'  => $this->noteLines($i, false),
                    'action'      => $work['action'],
                    'action_text' => $work['text'],
                    'action_evidence' => self::ACTION_EVIDENCE[$work['action']] ?? 'none',
                    'row_count'   => $i['row_count'],
                    'refs'        => collect($i['records'])->flatMap(fn ($r) => $r['refs'] ?? [])->unique()->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Completed work recorded in the period, each carrying what the durability ledger says followed it.
     * The ledger is passed in rather than recomputed — there is one answer to "did it hold" and it
     * lives in the evidence layer.
     *
     * @param  Collection<int, array>  $incidents
     */
    private function completedWork(Collection $incidents, array $durability): array
    {
        $verdicts = collect($durability)->keyBy(fn (array $d) => $d['date'] . '|' . mb_strtolower($d['work']['text']));

        $out = [];

        foreach ($incidents as $i) {
            foreach ($i['work'] as $w) {
                if (! in_array($w['action'], self::COMPLETED_ACTIONS, true)) {
                    continue;
                }

                $key = $i['start'] . '|' . mb_strtolower($w['text']);

                $out[] = [
                    'date'      => $i['start'],
                    'garage'    => $i['garages'] ? implode(', ', $i['garages']) : null,
                    'action'    => $w['action'],
                    'component' => $w['component'],
                    'text'      => $w['text'],
                    'fault'     => $i['fault'],
                    'verdict'   => $verdicts->get($key)['verdict'] ?? null,
                ];
            }
        }

        return $out;
    }

    // ── The counters at the top of the report ───────────────────────────────────────────────────

    /**
     * Every number here is a tally over the SELECTED period's incidents. None of them is derived from
     * anything outside the range, and none of them is an estimate.
     *
     * @param  Collection<int, array>  $incidents
     * @param  array<int, array>       $problems
     * @param  Collection<int, array>  $events
     */
    private function summary(Collection $incidents, array $problems, Collection $events): array
    {
        $problemList = collect($problems);

        return [
            // How many times the car was recorded against this system — visits, not faults.
            'system_visits'        => $incidents->count(),
            // How many DISTINCT problems were identified. Never the visit count.
            'named_faults'         => $problemList->count(),
            // How many incidents named one of those problems (a repeated fault contributes several).
            'named_fault_visits'   => $incidents->where('is_confirmed', true)->count(),
            'repeated_faults'      => $problemList->where('repeated', true)->count(),
            // Incidents in which completed work was recorded. Recommendations are excluded on purpose.
            'repairs'              => $incidents->filter(
                fn (array $i) => collect($i['work'])->contains(
                    fn (array $w) => in_array($w['action'], self::COMPLETED_ACTIONS, true)
                )
            )->count(),
            'workshop_only_visits' => $incidents->where('is_confirmed', false)->count(),
            // The rows underneath, so the reader can see how much collapsing happened.
            'source_records'       => $events->count(),
            'first_record'         => $incidents->pluck('start')->filter()->min(),
            'last_record'          => $incidents->pluck('start')->filter()->max(),
        ];
    }

    // ── Small shared readings ───────────────────────────────────────────────────────────────────

    /** @param  Collection<int, array>  $incidents */
    private function worstSeverity(Collection $incidents): ?string
    {
        $rank = array_flip(['routine', 'moderate', 'high', 'critical']);

        return $incidents
            ->pluck('severity')
            ->filter()
            ->sortByDesc(fn (string $s) => $rank[$s] ?? -1)
            ->first();
    }

    /** @param  Collection<int, array>  $incidents */
    private function bestStrength(Collection $incidents): string
    {
        foreach (['strong', 'medium', 'weak'] as $level) {
            if ($incidents->contains('strength', $level)) {
                return $level;
            }
        }

        return 'weak';
    }

    private function days(?string $from, ?string $to): ?int
    {
        return ($from && $to) ? Carbon::parse($from)->diffInDays(Carbon::parse($to)) : null;
    }
}
