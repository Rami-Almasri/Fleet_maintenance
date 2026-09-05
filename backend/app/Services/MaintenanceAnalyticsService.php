<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceReason;
use App\Models\MaintenanceTask;
use App\Support\FaultVocabulary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cost intelligence over maintenance line items: per-service price trends,
 * fleet/vehicle averages, and vendor price comparison — so the owner can spot
 * overcharging garages or rising part costs.
 *
 * All figures come from `maintenance_items` joined to their (type 'U') contract,
 * counting only items with a real cost ( > 0 ).
 */
class MaintenanceAnalyticsService
{
    /** Default allowed days in the garage when no Expected Return date is set. */
    public const DEFAULT_SLA_DAYS = 7;

    /**
     * Traffic-light status for a car in the garage (scenario doc, step 8):
     *   on_track (green)  — comfortably within time
     *   at_risk  (yellow) — due within 2 days
     *   breached (red)    — past its due date
     *   returned (sky)    — the linked visit already came back (IN); never overdue
     *   no_log   (gray)   — no maintenance record is strictly linked to this contract
     *
     * Due date = Expected Return if set, else out_date + DEFAULT_SLA_DAYS.
     *
     * $opts gates the active SLA so we never fabricate overdue status:
     *   - linked   (default true): is ANY maintenance record actually tied to this
     *                              contract? If false → no_log (empty, no SLA).
     *   - returned (default false): has the linked visit already come back (latest
     *                              event = IN)? If true → returned, overdue suppressed.
     *
     * @return array{status:string, days_out:?int, due:?string, overdue_days:int}
     */
    public function slaStatus($outDate, $expectedReturn, array $opts = []): array
    {
        $linked   = array_key_exists('linked', $opts) ? (bool) $opts['linked'] : true;
        $returned = (bool) ($opts['returned'] ?? false);

        $today = Carbon::today();
        $out = $outDate ? Carbon::parse($outDate) : null;
        $due = $expectedReturn
            ? Carbon::parse($expectedReturn)
            : ($out ? $out->copy()->addDays(self::DEFAULT_SLA_DAYS) : null);

        $daysOut = $out ? $out->diffInDays($today, false) : null;

        // No maintenance record strictly satisfies the link rules for this contract:
        // show "No Log" rather than borrowing a stale visit. No SLA at all.
        if (! $linked) {
            return ['status' => 'no_log', 'days_out' => $daysOut, 'due' => null, 'overdue_days' => 0];
        }

        // The linked visit has already returned (IN) — it is closed, so it must never
        // raise an Overdue / active SLA on the open contract.
        if ($returned) {
            return ['status' => 'returned', 'days_out' => $daysOut, 'due' => optional($due)->toDateString(), 'overdue_days' => 0];
        }

        if (! $due) {
            return ['status' => 'unknown', 'days_out' => $daysOut, 'due' => null, 'overdue_days' => 0];
        }

        $remaining = $today->diffInDays($due, false); // >0 due in future, <0 past due

        if ($remaining < 0) {
            $status = 'breached';
        } elseif ($remaining <= 2) {
            $status = 'at_risk';
        } else {
            $status = 'on_track';
        }

        return [
            'status'       => $status,
            'days_out'     => $daysOut,
            'due'          => $due->toDateString(),
            'overdue_days' => $remaining < 0 ? abs($remaining) : 0,
        ];
    }

    /** Priority levels ranked by how urgently they should surface (most severe first). */
    private const LEVEL_RANK = ['critical' => 0, 'special' => 1, 'minor' => 2, 'routine' => 3];

    /** English words too generic to match a reason on (compared after stemming). */
    private const STOPWORDS = [
        'the', 'of', 'and', 'in', 'issue', 'issues', 'problem', 'problems', 'system', 'systems',
        'trouble', 'troubles', 'change', 'malfunction', 'malfunctions', 'failure', 'failures',
        'light', 'lights', 'case', 'special', 'mixing', 'car', 'cars', 'new',
    ];

    /** Arabic connective/category words too generic to match a reason on. */
    private const AR_STOPWORDS = [
        'مشاكل', 'مشكلة', 'نظام', 'في', 'على', 'الى', 'من', 'أو', 'السيارة', 'السيارات', 'عند', 'عندما',
    ];

    /** Cached reason vocabulary + the ambiguous-token set, most-severe-first. */
    private static ?array $reasonCache = null;

    /**
     * Classify a maintenance job by matching its issue tags / notes against the
     * controlled reason->status vocabulary (maintenance_reasons, from the "Main reason"
     * sheet tab). Two passes, most-severe-first:
     *   1. whole-reason-name match (so a tag picked from the list classifies exactly)
     *   2. distinctive single keyword (a keyword shared by reasons of different levels —
     *      e.g. "oil" or "engine" — is ambiguous and skipped rather than guessed)
     * Falls back to 'routine' when nothing matches (incl. jobs with no tags).
     *
     * FAULT LABELS ONLY. This scores how URGENT a visit is, and urgency comes from what went wrong on
     * it. A tag list straight off the sheet mixes faults with planned services ("Oil & Fillter Change")
     * and bookkeeping words ("Customer", "Ready"), and feeding those in let an oil change contribute to
     * a car's priority. The filter lives HERE rather than at each of the eight call sites, so no caller
     * has to remember it and there is one place to change (audit M8).
     *
     * A tag list that is ENTIRELY non-fault falls through to the raw list rather than to silence — a
     * service visit still has a priority, it is just not being scored as a breakdown.
     *
     * @param  array<int,string>|string|null  $tags
     * @return array{level:string, matched:?string}
     */
    public function classifyPriority($tags, ?string $notes = null): array
    {
        if (is_array($tags) && $tags !== []) {
            $faults = app(EventClassificationService::class)->splitLabels($tags)[MaintenanceTask::KIND_FAULT];
            $tags   = $faults ?: $tags;
        }

        $raw = (is_array($tags) ? implode(' ', $tags) : (string) $tags) . ' ' . (string) $notes;
        if (trim($raw) === '') {
            return ['level' => 'routine', 'matched' => null];
        }

        $hayLower = mb_strtolower($raw);

        $enWords = [];
        foreach (preg_split('/[^a-z0-9]+/', strtolower($raw)) as $w) {
            if (strlen($w) >= 3) {
                $enWords[$this->stem($w)] = true;
            }
        }
        $arWords = [];
        foreach (preg_split('/\s+/u', $raw) as $w) {
            $w = mb_strtolower(trim($w));
            if (mb_strlen($w) >= 3 && preg_match('/[^\x00-\x7F]/', $w)) {
                $arWords[] = $w;
            }
        }

        ['reasons' => $reasons, 'ambiguous' => $ambiguous] = $this->reasonVocabulary();

        // Pass 1 — whole reason name (English phrase or Arabic phrase) appears in the text.
        foreach ($reasons as $r) {
            foreach ($r['phrases'] as $p) {
                if (mb_strpos($hayLower, $p) !== false) {
                    return ['level' => $r['level'], 'matched' => $r['reason']];
                }
            }
        }

        // Pass 2 — a distinctive single keyword matches.
        foreach ($reasons as $r) {
            foreach ($r['tokens'] as $tok) {
                if (isset($ambiguous[$tok['t']])) {
                    continue;
                }
                if ($tok['ar']) {
                    foreach ($arWords as $w) {
                        if (mb_strpos($tok['t'], $w) !== false || mb_strpos($w, $tok['t']) !== false) {
                            return ['level' => $r['level'], 'matched' => $r['reason']];
                        }
                    }
                } else {
                    foreach (array_keys($enWords) as $w) {
                        if ($w === $tok['t'] || (strlen($tok['t']) >= 4 && (str_contains($w, $tok['t']) || str_contains($tok['t'], $w)))) {
                            return ['level' => $r['level'], 'matched' => $r['reason']];
                        }
                    }
                }
            }
        }

        return ['level' => 'routine', 'matched' => null];
    }

    /**
     * The MaintenanceReason that best matches the given situation text (or null).
     * Used to LINK a maintenance row to its reason once, instead of re-classifying.
     */
    public function reasonFor($tags, ?string $notes = null): ?MaintenanceReason
    {
        $hit = $this->classifyPriority($tags, $notes);
        if (! $hit['matched']) {
            return null;
        }
        return MaintenanceReason::where('reason_en', $hit['matched'])->first();
    }

    /**
     * Resolve a maintenance row's priority. Prefers its LINKED reason (authoritative,
     * set when the situation was recorded); falls back to classifying its tags/notes.
     *
     * @return array{level:string, matched:?string}
     */
    public function classifyMaintenance(?Maintenance $m): array
    {
        if ($m && $m->maintenance_reason_id && $m->reason) {
            return ['level' => $m->reason->level, 'matched' => $m->reason->reason_en];
        }
        return $this->classifyPriority($m?->maintenance_tags ?? [], $m?->maintenance_notes);
    }

    /**
     * The latest sheet maintenance event (origin = 'sheet') for each given vehicle,
     * keyed by vehicle_id. OfficeManager's /contracts payload carries no garage/issue
     * detail for a maintenance contract, so the board describes a car's CURRENT garage
     * visit from the "N-Maintenance & Repair" sheet log instead. "Latest" = the most
     * recently imported event (id follows the sheet's append order).
     *
     * @param  iterable<int>  $vehicleIds
     * @return array<int, Maintenance>
     */
    public function latestSheetEvents($vehicleIds): array
    {
        $vehicleIds = collect($vehicleIds)->filter()->unique()->values();
        if ($vehicleIds->isEmpty()) {
            return [];
        }

        $latestIds = Maintenance::whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereIn('vehicle_id', $vehicleIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('vehicle_id')
            ->pluck('id');

        return Maintenance::with('vendor')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('vehicle_id')
            ->all();
    }

    /**
     * Grace window (days) before a contract's out_date in which a sheet event is still
     * considered part of THIS visit — covers the car reaching the garage a day or two
     * before the OfficeManager contract is opened (data-entry lag).
     */
    public const LINK_BUFFER_DAYS = 2;

    /**
     * Strictly link each contract to its OWN maintenance visit from the sheet log — a
     * "Hard Lock" bounded by the contract's lifespan, so a contract can never consume an
     * event that belongs to a different visit.
     *
     * A sheet event is attached to a contract only when ALL hold:
     *   1. Vehicle match:    same vehicle_id, AND
     *   2. Start boundary:   event out_date >= (contract out_date − LINK_BUFFER_DAYS), AND
     *   3. End boundary:     if the contract is CLOSED, event out_date <= the contract's
     *                        close date. We use `in_date` as the close cutoff (the date the
     *                        car came back / the visit ended — there is no `closed_at`
     *                        column; in_date is the only per-visit close marker). An open
     *                        contract (in_date = null) has no upper cutoff.
     *
     * Returns the FULL chronological sequence (oldest→newest) of every event that falls
     * inside each contract's lifespan window — so the caller can see the whole garage
     * history / "ping-pong" (car out → back → out again) while the problem is still open.
     * Take ->last() for the current workshop stage. If nothing qualifies the contract gets
     * NO sequence — the caller shows "No Log". This guarantees a closed/"zombie" contract
     * stops pulling sheet records the moment OfficeManager signs the repair off, and a
     * brand-new contract never inherits (bridges) a previous closed visit.
     *
     * (Future-proofing: once maintenances.contract_id is populated we switch to a 1:1 hard
     *  link; until then this lifespan window + vehicle match is the source of truth.)
     *
     * @param  iterable<\App\Models\Contract>  $contracts  contracts (need id, vehicle_id, out_date, in_date)
     * @return array<int, \Illuminate\Support\Collection<int, Maintenance>>  keyed by CONTRACT id
     */
    public function linkedSheetEvents($contracts): array
    {
        $contracts  = collect($contracts);
        $vehicleIds = $contracts->pluck('vehicle_id')->filter()->unique()->values();
        if ($vehicleIds->isEmpty()) {
            return [];
        }

        // All sheet events for these vehicles, oldest-first so each contract's sequence
        // comes out chronologically and ->last() is the current stage.
        $eventsByVehicle = Maintenance::with(['vendor', 'reason'])
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('out_date')
            ->orderBy('out_date')
            ->orderBy('id')
            ->get()
            ->groupBy('vehicle_id');

        $linked = [];
        foreach ($contracts as $c) {
            if (! $c->out_date) {
                continue;                       // no contract out_date → cannot window-match
            }
            $start = $c->out_date->copy()->subDays(self::LINK_BUFFER_DAYS);   // start boundary (with buffer)
            $end   = $c->in_date;               // Hard Cutoff: close date (null while still open)

            $seq = ($eventsByVehicle[$c->vehicle_id] ?? collect())
                ->filter(function ($e) use ($start, $end) {
                    if (! $e->out_date || $e->out_date->lt($start)) {
                        return false;           // before this visit started
                    }
                    if ($end && $e->out_date->gt($end)) {
                        return false;           // Hard Cutoff: after the contract closed → not ours
                    }
                    return true;
                })
                ->values();

            if ($seq->isNotEmpty()) {
                $linked[$c->id] = $seq;
            }
        }

        return $linked;
    }

    /**
     * The issue tags for a sheet maintenance event: its service categories (service_main)
     * plus the specific sub-faults (service_sup), each split into individual tags, e.g.
     * main="Body & Exterior, Brakes" + sup="Rim Scratch, Brake Pad Wear" becomes
     * ['Body & Exterior', 'Brakes', 'Rim Scratch', 'Brake Pad Wear']. These feed both the
     * board's Issues column and the priority classifier.
     *
     * @return array<int,string>
     */
    public function sheetIssueTags(?Maintenance $sheet): array
    {
        $grain = $this->sheetIssueTagsByGrain($sheet);

        return array_values(array_unique(array_merge($grain['main'], $grain['sup'])));
    }

    /**
     * The same labels, kept at their ORIGINAL GRAIN instead of flattened.
     *
     * `service_main` is a system CATEGORY ("Engine", "Body & Exterior"); `service_sup` is the specific
     * finding inside it ("Rim Scratch"). Flattening them into one list makes a category the peer of its
     * own child, so a single visit is counted twice and the top of every fault chart is category labels
     * rather than faults (audit M6). Consumers that COUNT must pick a grain; only consumers that DISPLAY
     * a "what was this visit about" blob should use the flattened sheetIssueTags().
     *
     * @return array{main: array<int,string>, sup: array<int,string>}
     */
    public function sheetIssueTagsByGrain(?Maintenance $sheet): array
    {
        $split = function (?string $field): array {
            $out = [];
            foreach (preg_split('/\s*,\s*/', (string) $field, -1, PREG_SPLIT_NO_EMPTY) as $t) {
                $t = trim($t);
                if ($t !== '' && ! in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }

            return $out;
        };

        if (! $sheet) {
            return ['main' => [], 'sup' => []];
        }

        $main = $split($sheet->service_main);
        $sup  = $split($sheet->service_sup);

        // A label repeated across both columns belongs to the finer grain only, so the pair never
        // double-counts the same word.
        $main = array_values(array_diff($main, $sup));

        return ['main' => $main, 'sup' => $sup];
    }

    /**
     * The visit's labels TYPED — fault / service / context — through the one classifier that owns the
     * legacy sheet vocabulary (EventClassificationService::splitLabels).
     *
     * The imported sheet has no type axis: faults ("Rim Scratch"), planned services ("Oil & Fillter
     * Change") and bookkeeping words ("Customer", "Ready") share one free-text column. Every consumer
     * that counts, charts or scores FAULTS must read `fault` here rather than the raw tag list.
     *
     * @return array{fault: array<int,string>, service: array<int,string>, context: array<int,string>}
     */
    public function sheetIssueTagsByKind(?Maintenance $sheet): array
    {
        return app(EventClassificationService::class)->splitLabels($this->sheetIssueTags($sheet));
    }

    /**
     * A VISIT'S FAULTS, at the right grain, each carrying the system it belongs to.
     *
     * `sheetIssueTags()` flattens MAIN and SUP into one list, which is correct for a "what was this
     * visit about" blob and wrong for anything that COUNTS. The bare system word survives on its own,
     * so a visit written as MAIN="Engine" / SUP="Oil & Fillter Change" contributed an `engine` fault —
     * and on the dossier donut that word then sat as a peer of its own children, "Engine" ranked beside
     * "Engine Oil leak". FaultVocabulary::sheetFaultLabels() applies the column-grain rule; this merges
     * its output ACROSS the several rows that make up one visit.
     *
     * Merged per visit, not per row, for the same reason the rule exists at all: if any row of the visit
     * named a fault specifically, the sibling row that only wrote the system word adds nothing and must
     * not survive beside it.
     *
     * @param  iterable<int,Maintenance>  $events  the workshop rows behind ONE visit
     * @return array<int,array{label:string,key:string,category_key:string,category_label:string,grain:string}>
     */
    public function faultFindings(iterable $events): array
    {
        $found = [];
        foreach ($events as $e) {
            foreach (FaultVocabulary::sheetFaultLabels($e->service_main ?? null, $e->service_sup ?? null) as $f) {
                // Keyed by identity, so the same fault written on the OUT row and the IN row is one fault.
                $found[$f['key']] ??= $f;
            }
        }

        $namedSystems = [];
        foreach ($found as $f) {
            if ($f['grain'] === FaultVocabulary::GRAIN_SPECIFIC) {
                $namedSystems[$f['category_key']] = true;
            }
        }

        return array_values(array_filter(
            $found,
            fn ($f) => $f['grain'] === FaultVocabulary::GRAIN_SPECIFIC || ! isset($namedSystems[$f['category_key']]),
        ));
    }

    /**
     * The same findings rolled up BY SYSTEM — what the dossier donut needs to show "Engine · 3" and,
     * underneath it, the three engine faults that actually happened.
     *
     * Also the shape for hand-entered `maintenance_tags`, which have no MAIN/SUP columns to reason
     * about: every one of those is treated as specific, since a human typed the fault itself.
     *
     * @param  array<int,array<string,mixed>>  $findings  from faultFindings(), or [] with $labels set
     * @param  array<int,string>  $labels  raw fault labels to group instead (hand-entered tags)
     * @return array<int,array{key:string,label:string,faults:array<int,string>}>
     */
    public function faultSystems(array $findings, array $labels = []): array
    {
        if ($labels) {
            $findings = [];
            foreach ($labels as $label) {
                $category = FaultVocabulary::resolveCategory($label);
                $findings[] = [
                    'label'          => $label,
                    'category_key'   => $category['key'] ?? FaultVocabulary::normalise($label),
                    'category_label' => $category['label'] ?? $label,
                ];
            }
        }

        $systems = [];
        foreach ($findings as $f) {
            $systems[$f['category_key']] ??= [
                'key'    => $f['category_key'],
                'label'  => $f['category_label'],
                'faults' => [],
            ];
            // A system whose only evidence is its own name has no children to list — the donut renders
            // it as a leaf rather than inventing "Engine › Engine".
            if ($f['label'] !== $f['category_label']) {
                // Keyed by IDENTITY, not by wording. The sheet writes the same fault more than one way
                // ("Engine Oil leak" and "Engine Oil Leak" both occur), and de-duplicating on the raw
                // string listed them as two separate faults under the same system — the reader sees a
                // duplicate and stops trusting the panel. First wording seen wins as the display label.
                $key = FaultVocabulary::catalogSlugOf($f['label']) ?: FaultVocabulary::normalise($f['label']);
                $systems[$f['category_key']]['faults'][$key] ??= $f['label'];
            }
        }

        // array_merge, NOT the `+` union: `+` keeps the LEFT operand's key, so `$s + ['faults' => …]`
        // silently preserved the dedupe map and every child rendered as `1`.
        return array_values(array_map(
            fn ($s) => array_merge($s, ['faults' => array_values($s['faults'])]),
            $systems,
        ));
    }

    /**
     * Build (and cache) the reason vocabulary. Each reason gets `phrases` (full English
     * + Arabic names, lowercased) and `tokens` (significant single words, English stemmed).
     * Also returns `ambiguous`: tokens that appear across reasons of MORE THAN ONE level
     * (so the classifier won't guess a level from them). Reasons are sorted severe-first.
     *
     * @return array{reasons: array<int, array<string,mixed>>, ambiguous: array<string,bool>}
     */
    private function reasonVocabulary(): array
    {
        if (self::$reasonCache !== null) {
            return self::$reasonCache;
        }

        $reasons = [];
        $tokenLevels = []; // token => [level => true]

        foreach (MaintenanceReason::all() as $r) {
            $phrases = [];
            $tokens = [];

            $en = strtolower(trim((string) $r->reason_en));
            if ($en !== '') {
                $phrases[] = $en;
                foreach (preg_split('/[^a-z0-9]+/', $en) as $w) {
                    $stem = $this->stem($w);
                    if (strlen($stem) >= 3 && ! in_array($stem, $this->stemmedStopwords(), true)) {
                        $tokens[] = ['t' => $stem, 'ar' => false];
                        $tokenLevels[$stem][$r->level] = true;
                    }
                }
            }

            $ar = trim((string) $r->reason_ar);
            if ($ar !== '') {
                $phrases[] = mb_strtolower($ar);
                foreach (preg_split('/\s+/u', $ar) as $w) {
                    $w = mb_strtolower(trim($w));
                    if (mb_strlen($w) >= 4 && ! in_array($w, self::AR_STOPWORDS, true)) {
                        $tokens[] = ['t' => $w, 'ar' => true];
                        $tokenLevels[$w][$r->level] = true;
                    }
                }
            }

            $reasons[] = [
                'level'   => $r->level,
                'rank'    => self::LEVEL_RANK[$r->level] ?? 9,
                'reason'  => $r->reason_en,
                'phrases' => array_values(array_unique($phrases)),
                'tokens'  => $tokens,
            ];
        }

        $ambiguous = [];
        foreach ($tokenLevels as $tok => $levels) {
            if (count($levels) > 1) {
                $ambiguous[$tok] = true;
            }
        }

        usort($reasons, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return self::$reasonCache = ['reasons' => $reasons, 'ambiguous' => $ambiguous];
    }

    /** STOPWORDS reduced to stems (so e.g. "issues"->"issu" is filtered too). */
    private function stemmedStopwords(): array
    {
        static $stems = null;
        if ($stems === null) {
            $stems = [];
            foreach (self::STOPWORDS as $w) {
                $stems[] = $w;
                $stems[] = $this->stem($w);
            }
            $stems = array_values(array_unique($stems));
        }
        return $stems;
    }

    /** Lowercase + strip a common english suffix so brake/brakes/braking match. */
    private function stem(string $w): string
    {
        $w = strtolower(trim($w));
        foreach (['ing', 'es', 's'] as $suf) {
            if (strlen($w) > strlen($suf) + 2 && str_ends_with($w, $suf)) {
                return substr($w, 0, -strlen($suf));
            }
        }
        return $w;
    }

    /**
     * Base query: maintenance items with a real cost + their contract context.
     * The garage/vendor now lives on the `maintenances` header, so we join through it.
     */
    protected function base()
    {
        return DB::table('maintenance_items as mi')
            ->join('contracts as c', 'c.id', '=', 'mi.contract_id')
            ->leftJoin('maintenances as mt', 'mt.contract_id', '=', 'c.id')
            ->leftJoin('vendors as v', 'v.id', '=', 'mt.vendor_id')
            ->whereNull('c.deleted_at')
            ->where('mi.cost', '>', 0);
    }

    /**
     * Average / min / max cost per service across the whole fleet (or one vehicle).
     *
     * @return array<int, array<string,mixed>>
     */
    public function serviceAverages(?int $vehicleId = null): array
    {
        $q = $this->base()
            ->selectRaw('mi.service_name as service,
                         COUNT(*) as visits,
                         ROUND(AVG(mi.cost), 2) as avg_cost,
                         ROUND(MIN(mi.cost), 2) as min_cost,
                         ROUND(MAX(mi.cost), 2) as max_cost')
            ->groupBy('mi.service_name')
            ->orderByDesc('visits');

        if ($vehicleId) {
            $q->where('c.vehicle_id', $vehicleId);
        }

        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * For every service, the per-vendor price comparison sorted cheapest-first,
     * flagging the cheapest and most expensive garage for that service.
     *
     * @return array<int, array<string,mixed>>
     */
    public function vendorComparison(): array
    {
        $rows = $this->base()
            ->whereNotNull('mt.vendor_id')
            ->selectRaw('mi.service_name as service,
                         mt.vendor_id,
                         v.name as vendor,
                         COUNT(*) as visits,
                         ROUND(AVG(mi.cost), 2) as avg_cost,
                         ROUND(MIN(mi.cost), 2) as min_cost,
                         ROUND(MAX(mi.cost), 2) as max_cost')
            ->groupBy('mi.service_name', 'mt.vendor_id', 'v.name')
            ->orderBy('mi.service_name')
            ->orderBy('avg_cost')
            ->get();

        $byService = [];
        foreach ($rows as $r) {
            $byService[$r->service][] = (array) $r;
        }

        $out = [];
        foreach ($byService as $service => $vendors) {
            // only meaningful to "compare" when more than one garage did this service
            $costs = array_column($vendors, 'avg_cost');
            $out[] = [
                'service'      => $service,
                'vendor_count' => count($vendors),
                'cheapest'     => $vendors[0]['vendor'] ?? null,
                'cheapest_avg' => $vendors[0]['avg_cost'] ?? null,
                'dearest'      => end($vendors)['vendor'] ?? null,
                'dearest_avg'  => end($vendors)['avg_cost'] ?? null,
                'spread'       => round((max($costs) - min($costs)), 2),
                'vendors'      => $vendors,
            ];
        }

        // services with the biggest price spread (most worth scrutinising) first
        usort($out, fn ($a, $b) => $b['spread'] <=> $a['spread']);

        return $out;
    }

    /**
     * Recurring faults (scenario step 8): cars that came in for the SAME issue 3+ times.
     * Surfaces problem vehicles / chronic faults. Routine servicing (oil, etc.) is excluded
     * — only real faults (critical / minor) count.
     *
     * @return array<int, array<string,mixed>>
     */
    public function recurringFaults(int $minVisits = 3): array
    {
        return DB::table('maintenances as mt')
            ->join('contracts as c', 'c.id', '=', 'mt.contract_id')
            ->join('maintenance_reasons as r', 'r.id', '=', 'mt.maintenance_reason_id')
            ->join('vehicles as v', 'v.id', '=', 'c.vehicle_id')
            ->whereNull('c.deleted_at')
            // FAULTS ONLY. This used to read `r.level IN (critical, minor)` — a priority column standing in
            // for the type — which both let planned work through and dropped genuine faults the sheet rates
            // as low-priority ("Engine Oil leak", "Fluid Leaks"). Typed by name through the one vocabulary
            // instead. See EventClassificationService::faultReasonIds() and audit M7.
            ->whereIn('mt.maintenance_reason_id', app(EventClassificationService::class)->faultReasonIds())
            ->selectRaw('c.vehicle_id, v.plate_no, v.make, v.model, r.reason_en as reason, r.level,
                         COUNT(*) as visits, MAX(c.out_date) as last_date')
            ->groupBy('c.vehicle_id', 'v.plate_no', 'v.make', 'v.model', 'r.reason_en', 'r.level')
            ->havingRaw('COUNT(*) >= ?', [$minVisits])
            ->orderByDesc('visits')
            ->get()
            ->map(fn ($r) => [
                'vehicle_id' => (int) $r->vehicle_id,
                'plate'      => $r->plate_no,
                'car'        => trim($r->make . ' ' . $r->model),
                'reason'     => $r->reason,
                'level'      => $r->level,
                'visits'     => (int) $r->visits,
                'last_date'  => $r->last_date ? substr((string) $r->last_date, 0, 10) : null,
            ])->all();
    }

    /**
     * Per-garage performance: how many jobs, how many cars are in right now, how many
     * are overdue, on-time vs late returns, average delay and total spend — plus the
     * list of cars currently at each garage with their SLA status (to act on delays).
     *
     * Source is the "N-Maintenance & Repair" sheet log (`maintenances`, origin 'sheet'):
     * OfficeManager carries no garage detail, so these rows — keyed to a vehicle
     * (vehicle_id) and a garage (vendor_id), NOT to a contract — are the only record of
     * which garage handled which car. The log is event-grained: one car visit (same
     * out_date) is many rows and may move across several garages, ending in an `IN`
     * event whose `actual_in_date` is the return. Crucially that closing `IN` is usually
     * logged at "OFFICE PARKING" (car back at the office), so we attribute a visit to its
     * WORK garages (any non-`IN` event) and read the return from the visit as a whole.
     *
     * @return array<int, array<string,mixed>>
     */
    /**
     * "Maintenance Pulse" — a few fleet-wide vital signs for the top of the board:
     *   - spend_week       : money spent on repairs whose visit STARTED this calendar week
     *                        (out_date >= start of week), across every maintenance origin.
     *   - avg_repair_days  : average garage turnaround (actual_in_date − out_date) over
     *                        visits completed in the last 90 days — the yardstick the board
     *                        uses to flag a still-open car as "over average".
     *   - completed_week   : count of visits that came back this week (a throughput signal).
     *
     * All figures are single aggregate queries — no per-card work — so the board stays cheap.
     *
     * @return array{spend_week:float, avg_repair_days:?float, completed_week:int}
     */
    public function maintenancePulse(): array
    {
        $weekStart = Carbon::now()->startOfWeek();

        // Spend this week: cost on any maintenance event whose visit started this week.
        $spendWeek = (float) Maintenance::whereNotNull('cost')
            ->whereDate('out_date', '>=', $weekStart->toDateString())
            ->sum('cost');

        // Average turnaround over recently completed visits (both dates present), last 90 days.
        $recent = Maintenance::whereNotNull('out_date')
            ->whereNotNull('actual_in_date')
            ->whereDate('actual_in_date', '>=', Carbon::now()->subDays(90)->toDateString())
            ->get(['out_date', 'actual_in_date']);

        $durations = $recent
            ->map(fn ($m) => $m->out_date && $m->actual_in_date
                ? Carbon::parse($m->out_date)->diffInDays(Carbon::parse($m->actual_in_date))
                : null)
            ->filter(fn ($d) => $d !== null)
            ->values();

        $avgRepairDays = $durations->isNotEmpty()
            ? round($durations->avg(), 1)
            : null;

        $completedWeek = (int) Maintenance::whereNotNull('actual_in_date')
            ->whereDate('actual_in_date', '>=', $weekStart->toDateString())
            ->distinct()
            ->count(DB::raw("CONCAT(vehicle_id, '|', out_date)"));

        return [
            'spend_week'      => round($spendWeek, 2),
            'avg_repair_days' => $avgRepairDays,
            'completed_week'  => $completedWeek,
        ];
    }

    public function garagePerformance(): array
    {
        // All garage events in one pass, with the car + garage name attached. left-joins
        // keep events whose vehicle/vendor row is missing (still real workload).
        $rows = DB::table('maintenances as mt')
            ->leftJoin('vendors as v', 'v.id', '=', 'mt.vendor_id')
            ->leftJoin('vehicles as ve', 've.id', '=', 'mt.vehicle_id')
            ->whereNotNull('mt.vendor_id')
            ->orderBy('mt.id')
            ->get([
                'mt.id', 'mt.vehicle_id', 'mt.vendor_id', 'mt.out_date', 'mt.expected_return_date',
                'mt.actual_in_date', 'mt.event_status', 'mt.cost', 'mt.service_main', 'mt.service_sup',
                'v.name as garage', 've.plate_no', 've.make', 've.model',
            ]);

        $d10 = fn ($v) => $v ? substr((string) $v, 0, 10) : null;   // normalise dates to YYYY-MM-DD

        // ---- pass 1: group events into visits (vehicle_id + out_date) ----------------
        // Per visit we keep the return date (max actual_in_date), the latest expected
        // return (delays push it out, so max) and the set of WORK garages.
        $visits = [];
        foreach ($rows as $r) {
            $vkey = $r->vehicle_id . '|' . ($d10($r->out_date) ?? ('row' . $r->id));
            if (! isset($visits[$vkey])) {
                $visits[$vkey] = ['vehicle' => $r->vehicle_id, 'out' => $d10($r->out_date), 'ret' => null, 'exp' => null, 'work' => []];
            }
            $v = &$visits[$vkey];

            $in = $d10($r->actual_in_date);
            if ($in && (! $v['ret'] || $in > $v['ret'])) {
                $v['ret'] = $in;
            }
            $exp = $d10($r->expected_return_date);
            if ($exp && (! $v['exp'] || $exp > $v['exp'])) {
                $v['exp'] = $exp;
            }
            // a garage "works" a visit via any event that isn't the closing return marker
            if ($r->event_status !== 'IN') {
                $v['work'][$r->vendor_id] = true;
            }
            unset($v);
        }

        // ---- per-garage accumulators -------------------------------------------------
        $g = [];   // vendor_id => stats bag
        $bag = function (&$g, $vid, $name) {
            if (! isset($g[$vid])) {
                $g[$vid] = [
                    'garage' => $name, 'jobs' => 0, 'late' => 0, 'ontime' => 0,
                    'delay_sum' => 0, 'delay_n' => 0, 'spent' => 0.0,
                ];
            }
        };

        // ---- pass 2: credit each visit to its work garages ---------------------------
        foreach ($visits as $v) {
            $closed = $v['ret'] !== null;
            $late = $closed && $v['exp'] && $v['ret'] > $v['exp'];
            $delay = $late ? Carbon::parse($v['exp'])->diffInDays(Carbon::parse($v['ret']), false) : null;
            foreach (array_keys($v['work']) as $vid) {
                $bag($g, $vid, null);
                $g[$vid]['jobs']++;
                if ($closed) {
                    $late ? $g[$vid]['late']++ : $g[$vid]['ontime']++;
                    if ($late) {
                        $g[$vid]['delay_sum'] += $delay;
                        $g[$vid]['delay_n']++;
                    }
                }
            }
        }

        // total spend per garage = the row-level cost on its events
        $names = [];
        foreach ($rows as $r) {
            $names[$r->vendor_id] = $r->garage;
            if ($r->cost > 0) {
                $bag($g, $r->vendor_id, $r->garage);
                $g[$r->vendor_id]['spent'] += (float) $r->cost;
            }
        }

        // ---- cars in each garage right now -------------------------------------------
        // "In the garage now" is the operational truth shared with the maintenance board
        // and dashboard: a car with an OPEN type-U contract. The sheet log only attributes
        // WHICH garage (its latest event's vendor). This keeps the three views consistent
        // and avoids ghost "in garage 300 days" cars from un-closed sheet rows. The id is a
        // contract id so the card links to /contracts/:id like the rest of the app.
        $openCars = \App\Models\Contract::where('contract_type', 'U')
            ->currentlyOpen()
            ->with('vehicle')
            ->get();
        $sheetEvents = $this->latestSheetEvents($openCars->pluck('vehicle_id'));

        $carsByVendor = [];
        foreach ($openCars as $c) {
            $sheet = $sheetEvents[$c->vehicle_id] ?? null;
            if (! $sheet || ! $sheet->vendor_id) {
                continue;                                  // no sheet event → can't place it in a garage
            }
            $sla = $this->slaStatus($c->out_date, $sheet->expected_return_date);
            $carsByVendor[$sheet->vendor_id][] = [
                'id'           => $c->id,
                'vehicle_id'   => $c->vehicle_id,
                'plate'        => $c->vehicle?->plate_no,
                'car'          => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                'days_out'     => $sla['days_out'],
                'due'          => $sla['due'],
                'status'       => $sla['status'],
                'overdue_days' => $sla['overdue_days'],
            ];
        }

        // ---- recent cars each garage handled (newest first, capped) ------------------
        // Walk events newest-first; the first time we see a (garage, car) pair, that is
        // its most recent visit there. Priority is classified from the event's services.
        $recentByVendor = [];
        $seen = [];
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $r = $rows[$i];
            if ($r->event_status === 'IN') {
                continue;                                  // return markers aren't "handled here"
            }
            $pair = $r->vendor_id . ':' . $r->vehicle_id;
            if (isset($seen[$pair]) || count($recentByVendor[$r->vendor_id] ?? []) >= 6) {
                continue;
            }
            $seen[$pair] = true;
            $tags = $this->splitServices($r->service_main, $r->service_sup);
            $recentByVendor[$r->vendor_id][] = [
                'id'       => (int) $r->vehicle_id,
                'plate'    => $r->plate_no,
                'car'      => trim((string) $r->make . ' ' . (string) $r->model) ?: null,
                'date'     => $d10($r->out_date),
                'priority' => $this->classifyPriority($tags)['level'],
            ];
        }

        // ---- assemble ---------------------------------------------------------------
        $out = [];
        foreach ($g as $vid => $s) {
            $cars = $carsByVendor[$vid] ?? [];
            $overdueNow = count(array_filter($cars, fn ($x) => $x['status'] === 'breached'));
            $measured = $s['late'] + $s['ontime'];

            $out[] = [
                'vendor_id'      => (int) $vid,
                'garage'         => $s['garage'] ?? $names[$vid] ?? ('#' . $vid),
                'jobs'           => $s['jobs'],
                'in_garage_now'  => count($cars),
                'overdue_now'    => $overdueNow,
                'late_returns'   => $s['late'],
                'ontime_returns' => $s['ontime'],
                'on_time_rate'   => $measured ? (int) round($s['ontime'] / $measured * 100) : null,
                'avg_delay_days' => $s['delay_n'] ? round($s['delay_sum'] / $s['delay_n'], 1) : null,
                'total_spent'    => round($s['spent'], 2),
                'current_cars'   => $cars,
                'recent_cars'    => $recentByVendor[$vid] ?? [],
            ];
        }

        // most actionable first: overdue, then cars-in-now, then total jobs
        usort($out, fn ($a, $b) => [$b['overdue_now'], $b['in_garage_now'], $b['jobs']] <=> [$a['overdue_now'], $a['in_garage_now'], $a['jobs']]);

        return $out;
    }

    /**
     * Split a sheet event's service_main + service_sup ("A, B" / "C, D") into individual
     * issue tags — the lightweight equivalent of sheetIssueTags() for a raw DB row.
     *
     * @return array<int,string>
     */
    private function splitServices(?string $main, ?string $sup): array
    {
        $tags = [];
        foreach ([$main, $sup] as $field) {
            foreach (preg_split('/\s*,\s*/', (string) $field, -1, PREG_SPLIT_NO_EMPTY) as $t) {
                $t = trim($t);
                if ($t !== '' && ! in_array($t, $tags, true)) {
                    $tags[] = $t;
                }
            }
        }
        return $tags;
    }

    /**
     * Per-service price history for one vehicle, with a trend (last vs previous)
     * and this-car-vs-fleet average comparison.
     *
     * @return array<int, array<string,mixed>>
     */
    public function vehicleServiceTrends(int $vehicleId): array
    {
        $rows = $this->base()
            ->where('c.vehicle_id', $vehicleId)
            ->selectRaw('mi.service_name as service, mi.cost, c.out_date, c.id as contract_id, v.name as vendor')
            ->orderBy('mi.service_name')
            ->orderByRaw('c.out_date is null, c.out_date')
            ->orderBy('c.id')
            ->get();

        // fleet averages to compare against
        $fleet = [];
        foreach ($this->serviceAverages() as $s) {
            $fleet[$s['service']] = $s['avg_cost'];
        }

        $byService = [];
        foreach ($rows as $r) {
            $byService[$r->service][] = [
                'date'        => $r->out_date,
                'cost'        => round((float) $r->cost, 2),
                'vendor'      => $r->vendor,
                'contract_id' => $r->contract_id,
            ];
        }

        $out = [];
        foreach ($byService as $service => $history) {
            $costs = array_column($history, 'cost');
            $latest = end($history);
            $previous = count($history) >= 2 ? $history[count($history) - 2] : null;

            $trend = 'flat';
            $delta = null;
            if ($previous) {
                $delta = round($latest['cost'] - $previous['cost'], 2);
                $trend = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
            }

            $out[] = [
                'service'      => $service,
                'visits'       => count($history),
                'latest_cost'  => $latest['cost'],
                'latest_date'  => $latest['date'],
                'latest_vendor' => $latest['vendor'],
                'previous_cost' => $previous['cost'] ?? null,
                'trend'        => $trend,            // up | down | flat
                'delta'        => $delta,            // latest - previous
                'avg_cost'     => round(array_sum($costs) / max(count($costs), 1), 2),
                'fleet_avg'    => $fleet[$service] ?? null,
                'history'      => $history,
            ];
        }

        usort($out, fn ($a, $b) => $b['visits'] <=> $a['visits']);

        return $out;
    }
}
