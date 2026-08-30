<?php

namespace App\Services\Reports;

use App\Services\GarageRecommendationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE EVIDENCE LAYER FOR THE PER-VEHICLE SYSTEM REPORT.
 *
 * The report it feeds used to count database rows and call the total a failure count. It could not,
 * because the rows do not mean what that arithmetic assumed:
 *
 *   • The sheet's `service_main` column carries the CATEGORY word for a visit ("Engine"), not a fault.
 *     Four visits whose only engine content is that word are four rows and zero identified faults, but
 *     they matched each other exactly and scored as "the same fault came back".
 *   • One workshop case is written as several rows on consecutive days — the OUT row, the garage's
 *     write-up, the return. Counted raw, one trip reads as three failures.
 *   • A visit note covers the whole car. "Transmission housing requires replacement" inside an
 *     engine-categorised visit was counted as ENGINE major work, because the major-work test read the
 *     note as one string and never asked which system the replacement belonged to.
 *
 * So this service answers a different question than "how many rows are there": **how many incidents
 * happened, what does the record actually establish about each, and how good is that evidence.**
 *
 * WHAT IT WILL NOT DO (see [[treat-data-as-source-of-truth]]):
 *   — It never invents a fault, a part, a date or a distance. Everything it reports is quoted from, or
 *     counted over, recorded text.
 *   — It never upgrades weak evidence. A generic row stays weak however many times it repeats, and
 *     "no later fault was recorded" is never rendered as "the repair held".
 *   — It produces no prediction and no probability. `confidence` describes THE RECORD's quality, not
 *     the likelihood of a future failure.
 */
class VehicleSystemEvidenceService
{
    public function __construct(private GarageRecommendationService $categories)
    {
    }

    /**
     * Two records belong to one incident only if they are this close in time. Workshop cases are
     * written up over a day or two (out, diagnosis, return); a genuine return visit is not.
     * Deliberately tight — merging two real failures is a worse error than splitting one case.
     */
    private const INCIDENT_GAP_DAYS = 3;

    /** How alike two notes must be to be treated as the same case written twice. */
    private const NOTE_SIMILARITY = 0.6;

    /**
     * Verbs that say what was actually DONE, strongest claim first. A sentence is classified by the
     * first class that matches, so "replaced and checked" is a replacement, not an inspection.
     *
     * Order matters and the list is deliberately short: every token here has to be one that only
     * appears when the work was really performed. "Required"/"needs" are excluded on purpose — a note
     * saying a part *requires* replacement records a recommendation, not a repair, and counting those
     * as work done is exactly how the old major-work count reached 3 on a car with no replacement.
     */
    private const WORK_VERBS = [
        'replacement' => ['replaced', 'replacement done', 'new one installed', 'renewed', 'swapped'],
        'repair'      => ['repaired', 'fixed', 'welded', 'tightened', 'cleaned', 'topped up', 'refilled', 'secured', 'adjusted'],
        'inspection'  => ['checked', 'inspected', 'diagnosed', 'scanned', 'tested', 'examined'],
    ];

    /** Wording that records a RECOMMENDATION rather than completed work. Never counted as work done. */
    private const RECOMMENDATION_VERBS = [
        'require', 'requires', 'required', 'needs', 'need to', 'recommended', 'should be', 'must be', 'pending', 'to be',
    ];

    /**
     * Wording that describes something being WRONG, as opposed to something being done.
     *
     * This is what lets a fault be read out of a note when the sheet's finding column carried only the
     * category word. "Cylinder 4 ignition coil is not functioning" names a fault whatever the category
     * column said, and refusing to see it would under-report a real failure just as badly as counting
     * "Engine" four times over-reports one. The fault is taken VERBATIM from the line — nothing is
     * summarised, renamed or diagnosed.
     */
    private const FAULT_TOKENS = [
        'not functioning', 'not working', 'malfunction', 'leak', 'noise', 'misfire', 'warning light',
        'worn', 'broken', 'damaged', 'failure', 'failed', 'overheat', 'cracked', 'burnt', 'burned',
        'stuck', 'vibration', 'rough running', 'weak acceleration', 'not cooling', 'smoke', 'knocking',
    ];

    /** Routine servicing, which is not a failure of the system even when it touches it. */
    private const PREVENTIVE_TOKENS = [
        'oil change', 'periodic', 'routine service', 'scheduled service', 'filter change', 'service due',
    ];

    // ── Entry point ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array>  $events  Collapsed events from VehicleSystemDashboardService.
     * @return array  incidents, recurrence, work, durability, facts, confidence, data_quality
     */
    public function analyse(Collection $events, string $system): array
    {
        $classified = $events
            ->map(fn (array $e) => $this->classify($e, $system))
            ->sortBy('date')
            ->values();

        $incidents = $this->cluster($classified, $system);

        return [
            'incidents'    => $incidents,
            'recurrence'   => $this->recurrence($incidents),
            'work'         => $this->workLedger($incidents),
            'durability'   => $this->durability($incidents),
            'facts'        => $this->facts($classified, $incidents),
            'confidence'   => $this->confidence($classified, $incidents),
            'data_quality' => $this->dataQuality($classified, $incidents),
        ];
    }

    // ── 1. Classify one record ──────────────────────────────────────────────────────────────────

    /**
     * What does THIS record, on its own, establish about this system?
     *
     * Three separate judgements, kept separate because they answer different questions:
     *   specificity  — is there a named fault, or only the category word?
     *   strength     — how much weight the record can carry (strong / medium / weak)
     *   kind         — what sort of record it is (a fault, a mention, servicing, a replacement)
     */
    private function classify(array $e, string $system): array
    {
        $specificity = $e['finding_specificity'] ?? $this->inferSpecificity($e, $system);
        $lines       = $this->systemLines($e['detail'] ?? '', $system, $e['finding'] ?? '');
        $work        = $this->extractWork($lines, $system);

        /*
         * A fault the NOTE names, when the finding column did not.
         *
         * The sheet's category column says "Engine" and nothing else, but the note underneath says
         * "Cylinder 4 ignition coil is not functioning". Reading only the column under-reports a real,
         * specifically identified failure — the mirror image of the bug that made "Engine" repeat four
         * times. The line is used verbatim as the fault; nothing is paraphrased or diagnosed.
         */
        $derived = null;
        if ($specificity !== 'specific') {
            $derived = collect($lines)->first(fn ($l) => $this->describesFault($l));
            if ($derived !== null) {
                $specificity = 'derived';
            }
        }

        $fault    = $derived ?? ($specificity === 'specific' ? $e['finding'] : null);
        $strength = $this->strength($e, $specificity, $lines, $derived, $system);
        $kind     = $this->kind($e, $specificity, $strength, $work, $lines);

        return array_merge($e, [
            'specificity'   => $specificity,
            'strength'      => $strength,
            'kind'          => $kind,
            'system_lines'  => $lines,
            'work'          => $work,
            // The fault this record identifies, from the finding column or read out of the note.
            'fault_text'    => $fault,
            'fault_source'  => $derived !== null ? 'note' : ($specificity === 'specific' ? 'finding_column' : null),
            // A fault this report is willing to call identified: named — by the column or by the note —
            // and backed by more than the category word alone.
            'is_confirmed'  => $fault !== null && $strength !== 'weak',
            'fault_key'     => $fault !== null ? $this->faultKey($fault) : null,
        ]);
    }

    /**
     * Does this line report a fault, or only propose one?
     *
     * "If the engine noise is still present, the engine may need to be replaced" contains a fault
     * word, but it is a conditional recommendation about the future — listing it under "what was
     * found" tells a manager a fault was identified when none was. A line only counts as a fault when
     * it states a condition outright: no recommendation verb, and not opening with a conditional.
     */
    private function describesFault(string $line): bool
    {
        $low = mb_strtolower(trim($line));

        return $this->mentionsAny($low, self::FAULT_TOKENS)
            && ! $this->mentionsAny($low, self::RECOMMENDATION_VERBS)
            && ! str_starts_with($low, 'if ')
            && ! str_contains($low, ' may ')
            && ! str_contains($low, ' might ');
    }

    /** A finding that is only the system's own name carries no fault information. */
    private function inferSpecificity(array $e, string $system): string
    {
        $finding = mb_strtolower(trim((string) ($e['finding'] ?? '')));
        $label   = mb_strtolower(VehicleSystemDashboardService::SYSTEMS[$system] ?? $system);

        if ($finding === '' || str_contains($finding, 'named no specific finding') || str_contains($finding, 'without a finding')) {
            return 'none';
        }

        return ($finding === $label || $finding === $system) ? 'generic' : 'specific';
    }

    /**
     * Evidence hierarchy. A structured fault ticket is the strongest thing this fleet records: a person
     * chose the system, wrote the symptom and set a severity. Free text is weaker, and free text that
     * only echoes the category word is the weakest thing that still counts as a record at all.
     */
    private function strength(array $e, string $specificity, array $lines, ?string $derived, string $system): string
    {
        $isTicket = ($e['source'] ?? '') === 'ticket';

        if ($isTicket && $specificity === 'specific') {
            return ! empty($e['severity']) ? 'strong' : 'medium';
        }

        if ($specificity === 'specific') {
            return $lines !== [] ? 'strong' : 'medium';
        }

        /*
         * A fault read out of the note is strong only when the line names a COMPONENT as well as a
         * failure — "cylinder 4 ignition coil is not functioning" identifies what failed, where
         * "noise" alone does not. That distinction is the user-facing difference between an explicit
         * component failure and a vague complaint, and it must not be flattened.
         */
        if ($specificity === 'derived') {
            return $this->componentIn(' ' . mb_strtolower((string) $derived) . ' ', $system) !== null ? 'strong' : 'medium';
        }

        // Generic category word, but the note itself describes something in this system.
        if ($specificity === 'generic' && $lines !== []) {
            return 'medium';
        }

        return 'weak';
    }

    private function kind(array $e, string $specificity, string $strength, array $work, array $lines): string
    {
        if ($this->mentionsAny(implode(' ', $lines) . ' ' . ($e['finding'] ?? ''), self::PREVENTIVE_TOKENS)) {
            return 'preventive_service';
        }

        if (collect($work)->contains(fn (array $w) => $w['action'] === 'replacement')) {
            return 'major_replacement';
        }

        if (in_array($specificity, ['specific', 'derived'], true) && $strength !== 'weak') {
            return 'confirmed_fault';
        }

        if ($specificity === 'none') {
            return 'unknown';
        }

        return 'workshop_mention';
    }

    /**
     * A fault's identity for recurrence matching: lowercased, stripped of punctuation and of the
     * parenthetical asides garages add ("(suspected ignition/electrical issue)"), which vary between
     * write-ups of the same fault and would otherwise split one recurring fault into two.
     */
    private function faultKey(?string $finding): string
    {
        $t = mb_strtolower((string) $finding);
        $t = preg_replace('/\([^)]*\)/u', ' ', $t);
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    // ── 2. Attribute note text to this system ───────────────────────────────────────────────────

    /**
     * The lines of a visit note that belong to THIS system.
     *
     * Each line is classified with GarageRecommendationService — the platform's single sanctioned
     * reader of this text, and the same one that decided the visit belonged to this system. A line is
     * kept when its categories include this system or a category the finding itself names, so
     * "Engine Oil leak" (engine + fluids) keeps its oil line on the engine report.
     *
     * This is what stops a transmission replacement written inside an engine visit from being counted
     * as engine work.
     *
     * @return array<int, string>
     */
    public function systemLines(?string $detail, string $system, string $finding = ''): array
    {
        $lines = collect(preg_split('/\r?\n|\s*\/\/\s*|\s+·\s+|(?<=\.)\s+/u', (string) $detail))
            ->map(fn ($l) => trim((string) $l, " \t\n\r\0\x0B\"'"))
            ->filter(fn ($l) => $l !== '' && $l !== 'No notes recorded' && mb_strlen($l) > 2)
            ->unique(fn ($l) => mb_strtolower($l))
            ->values();

        if ($lines->isEmpty()) {
            return [];
        }

        $wanted = array_unique(array_merge([$system], $this->categories->extractCategories($finding, null, null)));

        return $lines
            ->filter(fn ($l) => array_intersect($wanted, $this->categories->extractCategories($l, null, null)) !== [])
            ->values()
            ->all();
    }

    /** Every line of the note, whichever system it belongs to — for the raw-evidence drawer. */
    public function allLines(?string $detail): array
    {
        return collect(preg_split('/\r?\n|\s*\/\/\s*|\s+·\s+|(?<=\.)\s+/u', (string) $detail))
            ->map(fn ($l) => trim((string) $l, " \t\n\r\0\x0B\"'"))
            ->filter(fn ($l) => $l !== '' && $l !== 'No notes recorded' && mb_strlen($l) > 2)
            ->unique(fn ($l) => mb_strtolower($l))
            ->values()
            ->all();
    }

    // ── 3. What work the text actually records ──────────────────────────────────────────────────

    /**
     * Work claimed by lines belonging to THIS system, with the strength of the claim.
     *
     * A sentence must contain a completed-work verb to count as work. "Radiator requires replacement"
     * is a recommendation and is recorded as such — never as a replacement — because a fleet that
     * counts recommendations as repairs will believe parts were fitted that never were.
     *
     * @return array<int, array{action: string, component: ?string, text: string}>
     */
    private function extractWork(array $lines, string $system): array
    {
        $out = [];

        foreach ($lines as $line) {
            $low = ' ' . mb_strtolower($line) . ' ';

            $action = null;
            foreach (self::WORK_VERBS as $class => $verbs) {
                if ($this->mentionsAny($low, $verbs)) {
                    $action = $class;
                    break;
                }
            }

            // A recommendation only ever downgrades: it can turn a replacement claim into "recommended",
            // never the other way round.
            if ($action === null || ($action === 'replacement' && $this->mentionsAny($low, self::RECOMMENDATION_VERBS))) {
                $action = $this->mentionsAny($low, self::RECOMMENDATION_VERBS) ? 'recommended' : 'mention';
            }

            $out[] = [
                'action'    => $action,
                'component' => $this->componentIn($low, $system),
                'text'      => $line,
            ];
        }

        return $out;
    }

    /**
     * Which component of this system the line names, taken from the SAME keyword map the extractor
     * uses to assign categories (config/fault_extraction.php). No second vocabulary is invented here:
     * if the platform does not already recognise the word, this does not either.
     */
    private function componentIn(string $lowLine, string $system): ?string
    {
        $best = null;

        foreach ((array) config("fault_extraction.map.{$system}", []) as $token) {
            if (str_contains($lowLine, mb_strtolower($token))) {
                // Longest match wins: "cooling system" is a better name than "coolant".
                if ($best === null || mb_strlen($token) > mb_strlen($best)) {
                    $best = $token;
                }
            }
        }

        return $best;
    }

    private function mentionsAny(string $haystack, array $needles): bool
    {
        $h = ' ' . mb_strtolower($haystack) . ' ';

        foreach ($needles as $n) {
            if (str_contains($h, mb_strtolower($n))) {
                return true;
            }
        }

        return false;
    }

    // ── 4. Group records into incidents ─────────────────────────────────────────────────────────

    /**
     * COUNT INCIDENTS, NOT ROWS.
     *
     * A record joins the open incident when it is within a few days of it AND there is a positive
     * reason to believe it is the same case — the same garage, an unnamed garage, or a note that is
     * substantially the same text. Absent such a reason it starts a new incident: two genuine failures
     * merged into one is a worse error than one case reported as two, because the merge hides a
     * recurrence and the split only overstates activity that the incident list shows plainly.
     *
     * Every incident keeps the ids of the records that formed it, and the reason each was joined, so
     * the grouping can be audited row by row rather than trusted.
     */
    private function cluster(Collection $classified, string $system): array
    {
        $incidents = [];

        foreach ($classified as $e) {
            $open = end($incidents);
            $why  = $open ? $this->joinReason($open, $e) : null;

            if ($why === null) {
                $incidents[] = [
                    'records' => [$e + ['join_reason' => 'first record of this incident']],
                ];
                continue;
            }

            $incidents[count($incidents) - 1]['records'][] = $e + ['join_reason' => $why];
        }

        return array_map(fn (array $i) => $this->summariseIncident($i['records'], $system), $incidents);
    }

    /** Why this record belongs to the open incident, or null if it does not. */
    private function joinReason(array $incident, array $e): ?string
    {
        $last = end($incident['records']);

        if (empty($last['date']) || empty($e['date'])) {
            return null;
        }

        $gap = Carbon::parse($last['date'])->diffInDays(Carbon::parse($e['date']));

        if ($gap > self::INCIDENT_GAP_DAYS) {
            return null;
        }

        if (! empty($last['garage']) && ! empty($e['garage'])
            && $last['garage'] !== 'Not recorded' && $e['garage'] !== 'Not recorded'
            && $last['garage'] === $e['garage']) {
            return $gap === 0
                ? 'same date and same garage'
                : "{$gap} day(s) later at the same garage";
        }

        if (($last['garage'] ?? '') === 'Not recorded' || ($e['garage'] ?? '') === 'Not recorded') {
            return "within {$gap} day(s), and one of the two rows names no garage";
        }

        $sim = $this->similarity($last['detail'] ?? '', $e['detail'] ?? '');
        if ($sim >= self::NOTE_SIMILARITY) {
            return 'the note is ' . round($sim * 100) . '% the same text as the previous row';
        }

        return null;
    }

    /** Token containment: how much of the smaller note appears in the larger one. */
    private function similarity(string $a, string $b): float
    {
        $tok = function (string $s): array {
            $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s));

            return array_unique(array_filter(preg_split('/\s+/u', $s), fn ($w) => mb_strlen($w) > 3));
        };

        $x = $tok($a);
        $y = $tok($b);

        if ($x === [] || $y === []) {
            return 0.0;
        }

        return count(array_intersect($x, $y)) / min(count($x), count($y));
    }

    private function summariseIncident(array $records, string $system): array
    {
        $rows      = collect($records);
        $dates     = $rows->pluck('date')->filter()->sort()->values();
        $confirmed = $rows->where('is_confirmed', true);
        $work      = $rows->flatMap(fn ($r) => $r['work'])->values();

        $kinds = ['confirmed_fault', 'major_replacement', 'preventive_service', 'workshop_mention', 'unknown'];
        $kind  = collect($kinds)->first(fn ($k) => $rows->contains('kind', $k)) ?? 'unknown';

        $strengths = ['strong', 'medium', 'weak'];
        $strength  = collect($strengths)->first(fn ($s) => $rows->contains('strength', $s)) ?? 'weak';

        return [
            'start'        => $dates->first(),
            'end'          => $dates->last(),
            'days'         => $dates->count() > 1
                ? Carbon::parse($dates->first())->diffInDays(Carbon::parse($dates->last()))
                : 0,
            'row_count'    => $rows->count(),
            'garages'      => $rows->pluck('garage')->reject(fn ($g) => $g === 'Not recorded')->unique()->values()->all(),
            'kind'         => $kind,
            'strength'     => $strength,
            'is_confirmed' => $confirmed->isNotEmpty(),
            // The named fault this incident establishes, if any. Generic rows contribute none.
            'fault'        => $confirmed->first()['fault_text'] ?? null,
            'fault_key'    => $confirmed->first()['fault_key'] ?? null,
            'severity'     => $rows->pluck('severity')->filter()->first(),
            'sources'      => $rows->pluck('source')->unique()->values()->all(),
            'odometer'     => $rows->pluck('odometer')->filter()->max(),
            'work'         => $this->dedupeWork($work),
            'outcome'      => $rows->last()['outcome'] ?? null,
            'records'      => $rows->map(fn ($r) => [
                'ref'          => $r['ref'] ?? null,
                'refs'         => $r['refs'] ?? [],
                'date'         => $r['date'],
                'garage'       => $r['garage'],
                'source'       => $r['source'],
                'finding'      => $r['finding'],
                'specificity'  => $r['specificity'],
                'strength'     => $r['strength'],
                'kind'         => $r['kind'],
                'severity'     => $r['severity'] ?? null,
                'outcome'      => $r['outcome'] ?? null,
                'odometer'     => $r['odometer'] ?? null,
                'join_reason'  => $r['join_reason'],
                'system_lines' => $r['system_lines'],
                'all_lines'    => $this->allLines($r['detail'] ?? ''),
            ])->all(),
        ];
    }

    /** @param  Collection<int, array>  $work */
    private function dedupeWork(Collection $work): array
    {
        return $work
            ->filter(fn (array $w) => $w['action'] !== 'mention' || $w['component'] !== null)
            ->unique(fn (array $w) => $w['action'] . '|' . mb_strtolower($w['text']))
            ->values()
            ->all();
    }

    // ── 5. Recurrence, honestly ─────────────────────────────────────────────────────────────────

    /**
     * FOUR DISTINCT ANSWERS, because "1 repeated fault" was hiding all of them.
     *
     *   confirmed_recurring_fault — the same NAMED fault appears in two or more separate incidents.
     *   recurring_system_visits   — the car came back for this system, but the faults are not the same
     *                               one, or were never named. Repeated activity; not a proven repeat.
     *   no_evidence               — at most one incident.
     *   insufficient_data         — several incidents, but none of them names a fault at all, so
     *                               recurrence is not decidable either way.
     */
    private function recurrence(array $incidents): array
    {
        $all       = collect($incidents);
        $confirmed = $all->where('is_confirmed', true);

        $repeated = $confirmed
            ->groupBy('fault_key')
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($repeated->isNotEmpty()) {
            $status = 'confirmed_recurring_fault';
        } elseif ($all->count() <= 1) {
            $status = 'no_evidence';
        } elseif ($confirmed->isEmpty()) {
            $status = 'insufficient_data';
        } else {
            $status = 'recurring_system_visits';
        }

        return [
            'status'             => $status,
            'incident_count'     => $all->count(),
            'confirmed_count'    => $confirmed->count(),
            'named_faults'       => $confirmed->pluck('fault')->unique()->values()->all(),
            'repeated_faults'    => $repeated->map(fn (Collection $g, $key) => [
                'fault'     => $g->first()['fault'],
                'incidents' => $g->pluck('start')->values()->all(),
                'count'     => $g->count(),
            ])->values()->all(),
        ];
    }

    // ── 6. What was actually done, and did anything follow it ───────────────────────────────────

    /** Every work claim across the incidents, strongest first — the replacement ledger. */
    private function workLedger(array $incidents): array
    {
        $out = [];

        foreach ($incidents as $i) {
            foreach ($i['work'] as $w) {
                $out[] = $w + [
                    'date'     => $i['start'],
                    'garages'  => $i['garages'],
                    'odometer' => $i['odometer'],
                ];
            }
        }

        $rank = ['replacement' => 0, 'repair' => 1, 'inspection' => 2, 'recommended' => 3, 'mention' => 4];
        usort($out, fn ($a, $b) => [$rank[$a['action']] ?? 9, $b['date']] <=> [$rank[$b['action']] ?? 9, $a['date']]);

        return $out;
    }

    /**
     * DID IT HOLD? — for each completed replacement or repair, what the record shows afterwards.
     *
     * The answer is one of three, and the third is the one that matters most: there is a real
     * difference between "no later fault was recorded" and "the repair held". This never claims the
     * second. A car that left the fleet, or whose later visits nobody wrote up, produces exactly the
     * same silence as a car that was genuinely fixed.
     */
    private function durability(array $incidents): array
    {
        $out = [];

        foreach ($incidents as $idx => $i) {
            foreach ($i['work'] as $w) {
                if (! in_array($w['action'], ['replacement', 'repair'], true)) {
                    continue;
                }

                $later = collect(array_slice($incidents, $idx + 1));
                $next  = $later->first();
                $nextConfirmed = $later->firstWhere('is_confirmed', true);

                $out[] = [
                    'work'          => $w,
                    'date'          => $i['start'],
                    'odometer'      => $i['odometer'],
                    'fault_before'  => $i['fault'],
                    'next_incident' => $next ? [
                        'date'         => $next['start'],
                        'days'         => $this->days($i['start'], $next['start']),
                        'km'           => $this->km($i['odometer'], $next['odometer']),
                        'kind'         => $next['kind'],
                        'fault'        => $next['fault'],
                        'is_confirmed' => $next['is_confirmed'],
                        'same_fault'   => $next['fault_key'] !== null && $next['fault_key'] === $i['fault_key'],
                    ] : null,
                    'next_confirmed' => $nextConfirmed ? [
                        'date'       => $nextConfirmed['start'],
                        'days'       => $this->days($i['start'], $nextConfirmed['start']),
                        'km'         => $this->km($i['odometer'], $nextConfirmed['odometer']),
                        'fault'      => $nextConfirmed['fault'],
                        'same_fault' => $nextConfirmed['fault_key'] !== null && $nextConfirmed['fault_key'] === $i['fault_key'],
                    ] : null,
                    /*
                     * held           — a later CONFIRMED fault of the same name followed: it did not hold.
                     * activity       — the car came back for this system, but not for the same named fault.
                     * no_evidence    — nothing further is recorded. NOT a claim that the repair worked.
                     */
                    'verdict' => match (true) {
                        $nextConfirmed !== null && $nextConfirmed['fault_key'] === $i['fault_key'] => 'same_fault_returned',
                        $nextConfirmed !== null                                                    => 'different_fault_followed',
                        $next !== null                                                             => 'system_activity_followed',
                        default                                                                    => 'nothing_recorded_after',
                    },
                ];
            }
        }

        return $out;
    }

    private function days(?string $from, ?string $to): ?int
    {
        return ($from && $to) ? Carbon::parse($from)->diffInDays(Carbon::parse($to)) : null;
    }

    /** Distance between two readings, or null. Never estimated — absent odometers stay absent. */
    private function km(?int $from, ?int $to): ?int
    {
        return ($from && $to && $to >= $from) ? $to - $from : null;
    }

    // ── 7. Facts, confidence, data quality ──────────────────────────────────────────────────────

    /** Counts only. Nothing here is an inference; each line is a tally of records. */
    private function facts(Collection $classified, array $incidents): array
    {
        $inc = collect($incidents);

        return [
            'records'            => $classified->count(),
            'incidents'          => $inc->count(),
            'confirmed_faults'   => $inc->where('is_confirmed', true)->count(),
            'workshop_mentions'  => $inc->where('kind', 'workshop_mention')->count(),
            'replacements'       => $inc->where('kind', 'major_replacement')->count(),
            'preventive'         => $inc->where('kind', 'preventive_service')->count(),
            'from_tickets'       => $classified->where('source', 'ticket')->count(),
            'from_workshop_log'  => $classified->where('source', 'workshop log')->count(),
            'with_severity'      => $classified->filter(fn ($e) => ! empty($e['severity']))->count(),
            'with_system_notes'  => $classified->filter(fn ($e) => $e['system_lines'] !== [])->count(),
            'with_odometer'      => $classified->filter(fn ($e) => ! empty($e['odometer']))->count(),
            'multi_row_incidents'=> $inc->filter(fn ($i) => $i['row_count'] > 1)->count(),
            'latest_confirmed'   => $inc->where('is_confirmed', true)->last(),
            'latest_incident'    => $inc->last(),
        ];
    }

    /**
     * HOW GOOD IS THE EVIDENCE — not how likely a failure is.
     *
     * Driven by the share of incidents that name a fault and the share of records that are structured
     * tickets. A report built entirely from generic sheet rows says LOW however many rows there are,
     * which is the point: volume of weak evidence is still weak evidence.
     */
    private function confidence(Collection $classified, array $incidents): array
    {
        $inc = collect($incidents);
        $n   = max(1, $inc->count());

        $namedShare  = $inc->where('is_confirmed', true)->count() / $n;
        $ticketShare = $classified->isEmpty() ? 0 : $classified->where('source', 'ticket')->count() / $classified->count();
        $noteShare   = $classified->isEmpty() ? 0 : $classified->filter(fn ($e) => $e['system_lines'] !== [])->count() / $classified->count();

        $level = match (true) {
            $namedShare >= 0.6 && ($ticketShare > 0 || $noteShare >= 0.6) => 'high',
            $namedShare >= 0.3 || $noteShare >= 0.5                       => 'medium',
            default                                                        => 'low',
        };

        return [
            'level'         => $level,
            'named_share'   => round($namedShare, 2),
            'ticket_share'  => round($ticketShare, 2),
            'note_share'    => round($noteShare, 2),
        ];
    }

    /** What is missing or weak in the underlying records — stated, not glossed over. */
    private function dataQuality(Collection $classified, array $incidents): array
    {
        $inc      = collect($incidents);
        $warnings = [];
        $total    = max(1, $classified->count());

        $unstructured = $classified->where('source', 'workshop log')->count();
        if ($unstructured > 0) {
            $warnings[] = ['code' => 'unstructured', 'n' => $unstructured, 'total' => $classified->count()];
        }

        $generic = $classified->whereIn('specificity', ['generic', 'none'])->count();
        if ($generic > 0) {
            $warnings[] = ['code' => 'generic_finding', 'n' => $generic, 'total' => $classified->count()];
        }

        $noNotes = $classified->filter(fn ($e) => $e['system_lines'] === [])->count();
        if ($noNotes > 0) {
            $warnings[] = ['code' => 'no_system_notes', 'n' => $noNotes, 'total' => $classified->count()];
        }

        $multi = $inc->filter(fn ($i) => $i['row_count'] > 1)->count();
        if ($multi > 0) {
            $warnings[] = ['code' => 'multi_row_incidents', 'n' => $multi, 'total' => $inc->count()];
        }

        $noOdo = $classified->filter(fn ($e) => empty($e['odometer']))->count();
        if ($noOdo > 0) {
            $warnings[] = ['code' => 'no_odometer', 'n' => $noOdo, 'total' => $classified->count()];
        }

        $warnings[] = ['code' => 'no_structured_parts', 'n' => 0, 'total' => 0];

        return $warnings;
    }
}
