<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Support\EventKind;
use App\Support\FaultVocabulary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE CAR's repeat-fault story — "this car keeps breaking down with the SAME thing".
 *
 * The Vehicle Profile answers a question no fleet-wide list can: for THIS car, which faults came back
 * after they were supposedly fixed, how many separate times, how long the car held between each return,
 * where it was each time and what it cost. The output is a CHAIN per fault:
 *
 *     3×  Electrical   broke again 3 separate times
 *     #4369  →25d→  #4473  →50d→  #4728 ·2×
 *
 * ── BOTH fault models, one chain ───────────────────────────────────────────────────────────────────
 * The fleet records faults in two places, and a car's history runs through both:
 *   • the legacy WORKSHOP LOG (`maintenances`, origin sheet/manual) — free-text labels in
 *     service_main / service_sup, the bulk of the history; and
 *   • the TICKET WORKFLOW (`maintenance_tasks`) — one row per fault with a symptom + category_key,
 *     which is where every NEW fault is raised.
 * Reading only one of them would make the panel quietly stop counting: the sheet holds the past, the
 * tickets hold the future. So both are read and merged into a SINGLE timeline per fault, keyed on the
 * canonical category (FaultVocabulary::categoryOf) so the sheet's "Tires" and a ticket's
 * "Puncture / slow leak" are recognised as the same recurring fault rather than two unrelated ones.
 * A `maintenances` row that owns tasks is read ONLY through the ticket side, so nothing counts twice.
 * Every step keeps its `source`, so the UI can always show which system recorded it.
 *
 * ── What counts as "it came back" ──────────────────────────────────────────────────────────────────
 * Repeat ≠ "logged twice". A car shuffling in and out of the shop on ONE repair is still one repair.
 * The same episode rules as the fleet engine apply (MaintenanceForesightService), so this panel can
 * never contradict the fleet view:
 *   • Rule A — every event under the SAME Type-U maintenance contract is ONE episode, however many
 *     times the car moved between garages. The counter does not tick inside a contract.
 *   • Rule B — events with NO maintenance contract open join the current episode only when they land
 *     within WAITING_BUFFER_DAYS; a longer gap is a genuine return. (No maintenance contract does NOT
 *     establish that the car was on rent — see the 'kind' field in describeFault().)
 *   • A new episode therefore begins when the contract changes / opens / closes, or after a >7-day gap
 *     with no contract. Each break records WHY, which is what the chain renders.
 *   • Routine planned upkeep and cosmetic / rental-return work are excluded — a car having its oil
 *     changed on schedule is not a car that keeps breaking down.
 *   • Faults the workshop ruled cancelled or not-found never happened, so they never count.
 *
 * ── The counter-signal ─────────────────────────────────────────────────────────────────────────────
 * The SAME garage taking the car 3× for the same fault inside 10 days is a slow workshop, not a car
 * that keeps failing. That is surfaced on the fault as `stalling` so the panel can say so out loud
 * instead of blaming the vehicle.
 *
 * RETIRED TICKETS ARE INCLUDED, DELIBERATELY. `maintenances` is soft-deleted; the raw queries below do
 * not inherit the model's scope and are not meant to. This class measures WHAT HAPPENED, and a retired
 * ticket is still a repair that occurred — excluding it would let history change whenever somebody
 * tidied the board, and would move a denominator without its numerator. Live operational surfaces take
 * the opposite rule and filter `deleted_at` explicitly. See docs/Maintenance-Deletion-Model.md.
 */
class VehicleFaultRecurrenceService
{
    /** Distinct episodes at/over this → the fault is a repeat offender worth showing. */
    private const MIN_EPISODES = 2;
    /** A return inside this many days (with no maintenance contract) is the same repair, not a recurrence. */
    private const WAITING_BUFFER_DAYS = 7;
    /** Grace window before a Type-U contract's out_date in which an event still belongs to it. */
    private const CONTRACT_BUFFER_DAYS = 2;
    /** Same workshop, same fault, this many visits inside STALL_WINDOW_DAYS → the garage is stalling. */
    private const STALL_MIN_VISITS = 3;
    private const STALL_WINDOW_DAYS = 10;

    /** Where a step in the chain was recorded. */
    public const SOURCE_LOG    = 'log';      // legacy workshop log (N-Maintenance sheet / manual event)
    public const SOURCE_TICKET = 'ticket';   // the maintenance ticket workflow

    public function __construct(private MaintenanceAnalyticsService $analytics)
    {
    }

    /**
     * The full repeat-fault report for one car, newest-pain-first.
     *
     * @return array{
     *   vehicle_id:int, faults:array<int,array<string,mixed>>, summary:array<string,mixed>,
     *   rules:array<string,mixed>, sources:array<string,mixed>, origin:string
     * }
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $contracts = $this->maintenanceContracts($vehicle->id);

        // Rows the ticket workflow owns are read through the ticket side only — never twice.
        $ticketOwned = MaintenanceTask::where('vehicle_id', $vehicle->id)
            ->whereNotNull('maintenance_id')
            ->distinct()
            ->pluck('maintenance_id')
            ->all();

        $events = array_merge(
            $this->workshopLogEvents($vehicle->id, $ticketOwned),
            $this->ticketEvents($vehicle->id),
        );

        // Bucket every event by the canonical fault category, so both vocabularies land in one chain.
        $byFault = [];
        foreach ($events as $event) {
            $bucket = $this->bucketFor($event['label']);
            if (! $bucket) {
                continue;   // cosmetic, routine upkeep, or wording we cannot place — not a breakdown story
            }
            $event['contract'] = $this->resolveContract($contracts['rows'], $event['date']);
            $byFault[$bucket['key']] ??= ['label' => $bucket['label'], 'events' => []];
            $byFault[$bucket['key']]['events'][] = $event;
        }

        $faults = [];
        foreach ($byFault as $key => $bucket) {
            $episodes = $this->groupEpisodes($bucket['events']);
            if (count($episodes) < self::MIN_EPISODES) {
                continue;   // came in once — that is a repair, not a pattern
            }
            $faults[] = $this->describeFault($key, $bucket, $episodes, $contracts['numbers']);
        }

        // Worst first: most returns, then the most recent return (a 3× from last week outranks a 3×
        // from two years ago), then the biggest spend.
        usort($faults, fn ($a, $b) => [$b['episodes'], $b['last_seen'], $b['total_cost']]
                                  <=> [$a['episodes'], $a['last_seen'], $a['total_cost']]);

        return [
            'vehicle_id' => (int) $vehicle->id,
            'faults'     => $faults,
            'summary'    => $this->summarise($faults),
            'rules'      => [
                'min_episodes'        => self::MIN_EPISODES,
                'waiting_buffer_days' => self::WAITING_BUFFER_DAYS,
                'excludes'            => ['routine planned service', 'cosmetic / rental-return work', 'faults ruled incorrect'],
            ],
            'sources' => [
                self::SOURCE_LOG    => 'Workshop log (N-Maintenance)',
                self::SOURCE_TICKET => 'Maintenance ticket',
            ],
            'origin' => 'Both fault systems merged — the workshop log (N-Maintenance) and the maintenance ticket '
                      . 'workflow — grouped into repair episodes by Type-U maintenance contract, else by a '
                      . self::WAITING_BUFFER_DAYS . '-day return gap. Same episode rules as the fleet engine.',
        ];
    }

    // ── Sources ────────────────────────────────────────────────────────────────────────────────────

    /**
     * SOURCE 1 — the legacy workshop log, collapsed into VISITS (vehicle + out_date), so a multi-row
     * visit and a garage-to-garage shuffle on the same day count once. Rows the ticket workflow owns are
     * skipped (they arrive via ticketEvents instead), as is routine planned service.
     *
     * One visit can carry several fault labels; each becomes its own event so it can join its own chain.
     *
     * @param  array<int,int>  $excludeMaintenanceIds
     * @return array<int,array<string,mixed>>
     */
    private function workshopLogEvents(int $vehicleId, array $excludeMaintenanceIds): array
    {
        $rows = DB::table('maintenances as m')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->where('m.vehicle_id', $vehicleId)
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('m.out_date')
            ->when($excludeMaintenanceIds, fn ($q) => $q->whereNotIn('m.id', $excludeMaintenanceIds))
            ->where(function ($q) {
                $q->whereNull('m.visit_context')
                  ->orWhere('m.visit_context', '<>', Maintenance::CONTEXT_ROUTINE);
            })
            ->orderBy('m.out_date')
            ->get(['m.id', 'm.out_date', 'm.actual_in_date', 'm.service_main', 'm.service_sup', 'm.cost',
                   'm.vendor_id', 'm.garage', 'vd.name as vendor']);

        $day = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        // Collapse to one visit per day, remembering every label seen on it.
        $visits = [];
        foreach ($rows as $r) {
            $key = $day($r->out_date) ?? '';
            $visits[$key] ??= ['date' => $day($r->out_date), 'ret' => null, 'labels' => [], 'cost' => 0.0,
                               'garages' => [], 'vendor_id' => null, 'vendor' => null, 'ref_ids' => []];
            $vi = &$visits[$key];

            $in = $day($r->actual_in_date);
            if ($in && (! $vi['ret'] || $in > $vi['ret'])) {
                $vi['ret'] = $in;               // the visit ends when the LAST of its rows came back
            }
            foreach (FaultVocabulary::splitIssues($r->service_main, $r->service_sup) as $t) {
                $vi['labels'][$t] = true;
            }
            $vi['cost'] += (float) $r->cost;
            $vi['ref_ids'][] = (int) $r->id;

            $garage = $r->vendor ?: $r->garage;
            if ($garage) {
                $vi['garages'][trim((string) $garage)] = true;
            }
            if (! $vi['vendor_id'] && $r->vendor_id) {
                $vi['vendor_id'] = (int) $r->vendor_id;
                $vi['vendor']    = $garage;
            } elseif (! $vi['vendor'] && $garage) {
                $vi['vendor'] = $garage;
            }
            unset($vi);
        }

        // Fan each visit out into one event per fault label it carried.
        $events = [];
        foreach ($visits as $vi) {
            if (! $vi['date']) {
                continue;
            }
            foreach (array_keys($vi['labels']) as $label) {
                $events[] = [
                    'source'    => self::SOURCE_LOG,
                    'label'     => $label,
                    'date'      => $vi['date'],
                    'days'      => $vi['ret'] ? max(0, (int) Carbon::parse($vi['date'])->diffInDays(Carbon::parse($vi['ret']))) : null,
                    'cost'      => $vi['cost'],
                    'garages'   => array_keys($vi['garages']),
                    'vendor_id' => $vi['vendor_id'],
                    'vendor'    => $vi['vendor'],
                    'ref_ids'   => $vi['ref_ids'],
                    'ticket_id' => null,
                    'pending'   => false,
                ];
            }
        }

        return $events;
    }

    /**
     * SOURCE 2 — the ticket workflow: one event per FAULT task on this car. This is what keeps the panel
     * live, since every new fault is raised here rather than on the sheet.
     *
     * Dated by the day the car went out to the garage; a fault that has been reported but not yet
     * dispatched falls back to when it was identified and is marked `pending`, so a returning fault shows
     * up the moment it is reported instead of only once the car physically moves.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ticketEvents(int $vehicleId): array
    {
        $tasks = MaintenanceTask::query()
            ->where('maintenance_tasks.vehicle_id', $vehicleId)
            // A fault the workshop cancelled or never found did not happen — it can't be a recurrence.
            ->whereNotIn('maintenance_tasks.status', MaintenanceTask::NON_REPAIR_TERMINAL)
            // Event Type layer: planned services and inspections are not breakdowns.
            ->when(EventKind::enforced(), fn ($q) => $q->faults())
            ->join('maintenances as m', 'm.id', '=', 'maintenance_tasks.maintenance_id')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'maintenance_tasks.current_vendor_id')
            ->leftJoin('vendors as mv', 'mv.id', '=', 'm.vendor_id')
            // Routine tickets are planned upkeep, not failures — same exclusion as the workshop log.
            ->where(function ($q) {
                $q->whereNull('m.visit_context')
                  ->orWhere('m.visit_context', '<>', Maintenance::CONTEXT_ROUTINE);
            })
            ->orderBy('m.out_date')
            ->get([
                'maintenance_tasks.id', 'maintenance_tasks.maintenance_id', 'maintenance_tasks.symptom',
                'maintenance_tasks.category_key', 'maintenance_tasks.parts_cost', 'maintenance_tasks.labor_cost',
                'maintenance_tasks.identified_at', 'maintenance_tasks.resolved_at', 'maintenance_tasks.current_vendor_id',
                'm.out_date', 'm.actual_in_date', 'm.garage',
                'vd.name as task_vendor', 'mv.name as ticket_vendor',
            ]);

        $day = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        $events = [];
        foreach ($tasks as $t) {
            $date = $day($t->out_date) ?: $day($t->identified_at) ?: $day($t->resolved_at);
            if (! $date) {
                continue;   // undateable — it can't be placed on a timeline
            }

            $ret = $day($t->actual_in_date);
            $garage = $t->task_vendor ?: $t->ticket_vendor ?: $t->garage;

            $events[] = [
                'source' => self::SOURCE_TICKET,
                // category_key is the authoritative classification; the symptom is the human wording and
                // the fallback when a task predates the catalog picker.
                'label'     => $t->category_key ?: $t->symptom,
                'wording'   => $t->symptom,
                'date'      => $date,
                'days'      => ($t->out_date && $ret) ? max(0, (int) Carbon::parse($day($t->out_date))->diffInDays(Carbon::parse($ret))) : null,
                'cost'      => (float) $t->parts_cost + (float) $t->labor_cost,
                'garages'   => $garage ? [trim((string) $garage)] : [],
                'vendor_id' => $t->current_vendor_id ? (int) $t->current_vendor_id : null,
                'vendor'    => $garage,
                'ref_ids'   => [(int) $t->id],
                'ticket_id' => (int) $t->maintenance_id,
                // Reported, but the car has not gone to a garage for it yet.
                'pending'   => empty($t->out_date),
            ];
        }

        return $events;
    }

    /**
     * The canonical fault bucket a label belongs to — the join between the two vocabularies. Cosmetic
     * damage, routine upkeep and unplaceable wording return null (excluded).
     *
     * @return array{key:string,label:string}|null
     */
    private function bucketFor(string $label): ?array
    {
        if ($category = FaultVocabulary::categoryOf($label)) {
            return FaultVocabulary::isFailureCategory($category['key']) ? $category : null;
        }

        // Unknown wording: fall back to the deny-first mechanical test and let it be its own bucket, so a
        // genuinely repeating fault is never dropped just because the catalog has no word for it yet.
        if (! FaultVocabulary::isMechanical($label)) {
            return null;
        }

        return ['key' => FaultVocabulary::normalise($label), 'label' => $label];
    }

    /**
     * This car's Type-U maintenance contracts (the "the car went out for repair" records) + their
     * display numbers.
     *
     * @return array{rows:\Illuminate\Support\Collection<int,object>, numbers:array<int,mixed>}
     */
    private function maintenanceContracts(int $vehicleId): array
    {
        $rows = Contract::where('contract_type', 'U')
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('out_date')
            ->orderBy('out_date')
            ->get(['id', 'contract_no', 'out_date', 'in_date']);

        return ['rows' => $rows, 'numbers' => $rows->pluck('contract_no', 'id')->all()];
    }

    /**
     * The Type-U contract an event on $date belongs to (latest contract whose window —
     * out_date − buffer … in_date — covers it), or null when no maintenance contract covers it.
     * Null is NOT evidence of a rental: Type-C contracts are never consulted here.
     *
     * @param  \Illuminate\Support\Collection<int,object>  $contracts
     */
    private function resolveContract($contracts, ?string $date): ?int
    {
        if (! $date || $contracts->isEmpty()) {
            return null;
        }
        $d = Carbon::parse($date);

        $bestId = null;
        $bestOut = null;
        foreach ($contracts as $c) {
            if ($d->lt(Carbon::parse($c->out_date)->subDays(self::CONTRACT_BUFFER_DAYS))) {
                continue;                                       // before this contract started
            }
            if ($c->in_date && $d->gt(Carbon::parse($c->in_date)->endOfDay())) {
                continue;                                       // after this (closed) contract ended
            }
            if ($bestOut === null || $c->out_date > $bestOut) {  // prefer the latest covering contract
                $bestOut = $c->out_date;
                $bestId  = (int) $c->id;
            }
        }

        return $bestId;
    }

    // ── The episode engine ─────────────────────────────────────────────────────────────────────────

    /**
     * Split one fault's events into distinct repair EPISODES (Rules A + B above), each keeping the events
     * behind it so the chain can show cost, garages, sources and shop days per link.
     *
     * @param  array<int,array<string,mixed>>  $events
     * @return array<int,array<string,mixed>>
     */
    private function groupEpisodes(array $events): array
    {
        usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);

        $episodes = [];
        $i = -1;
        foreach ($events as $e) {
            $c = $e['contract'] ?? null;

            $same = false;
            if ($i >= 0) {
                $cur = $episodes[$i];
                if ($c !== null && $cur['contract'] !== null) {
                    $same = $c === $cur['contract'];                       // Rule A
                } elseif ($c === null && $cur['contract'] === null) {      // Rule B
                    $same = (int) Carbon::parse($cur['last'])->diffInDays(Carbon::parse($e['date'])) <= self::WAITING_BUFFER_DAYS;
                }
            }

            if ($same) {
                $episodes[$i]['events'][] = $e;
                if ($e['date'] > $episodes[$i]['last']) {
                    $episodes[$i]['last'] = $e['date'];
                }
                continue;
            }

            // A new episode — record how long the car held, and what broke the chain.
            $gap = null;
            $boundary = null;
            if ($i >= 0) {
                $prev = $episodes[$i];
                $gap  = (int) Carbon::parse($prev['last'])->diffInDays(Carbon::parse($e['date']));
                if ($c !== null && $prev['contract'] !== null) {
                    $boundary = 'contract_change';
                } elseif ($c !== null) {
                    $boundary = 'contract_opened';
                } elseif ($prev['contract'] !== null) {
                    $boundary = 'contract_closed';
                } else {
                    $boundary = 'gap';
                }
            }

            $episodes[] = [
                'contract' => $c, 'events' => [$e], 'first' => $e['date'], 'last' => $e['date'],
                'gap_days' => $gap, 'boundary' => $boundary,
            ];
            $i++;
        }

        return $episodes;
    }

    /**
     * Turn a fault's episodes into the panel's payload: the headline counters, the human "why this is
     * a repeat" hint, and the chain of links the UI draws.
     *
     * @param  array{label:string, events:array<int,array<string,mixed>>}  $bucket
     * @param  array<int,array<string,mixed>>  $episodes
     * @param  array<int,mixed>  $contractNumbers
     * @return array<string,mixed>
     */
    private function describeFault(string $key, array $bucket, array $episodes, array $contractNumbers): array
    {
        $events = $bucket['events'];

        $chain = [];
        foreach ($episodes as $ep) {
            $garages = [];
            $sources = [];
            $tickets = [];
            foreach ($ep['events'] as $e) {
                foreach ($e['garages'] as $g) {
                    $garages[$g] = true;
                }
                $sources[$e['source']] = true;
                if ($e['ticket_id']) {
                    $tickets[$e['ticket_id']] = true;
                }
            }
            $shopDays = array_sum(array_map(fn ($e) => (int) ($e['days'] ?? 0), $ep['events']));

            $chain[] = [
                'contract_id' => $ep['contract'],
                'contract_no' => $ep['contract'] ? ($contractNumbers[$ep['contract']] ?? $ep['contract']) : null,
                // 'contract' = a Type-U maintenance contract covers this date, so the car was
                // provably in the shop. 'unknown' = it does NOT — and that is ALL it means.
                // This used to be reported as 'rental' and rendered as "During rental", which was an
                // inference from a negative: nothing here looks at a Type-C rental contract, so an
                // idle car, an unsynced contract or shop work with no contract raised all read as
                // "a customer had it". Measured on vehicle 1805's Brakes chain, 1 of 7 such episodes
                // had no rental contract at all. Until the rental side is actually resolved (then:
                // in maintenance / with customer / no active contract), this stays 'unknown' and the
                // UI shows no status rather than a claim it cannot support.
                'kind'        => $ep['contract'] ? 'contract' : 'unknown',
                'visits'      => count($ep['events']),
                'gap_days'    => $ep['gap_days'],
                'boundary'    => $ep['boundary'],
                'first'       => $ep['first'],
                'last'        => $ep['last'],
                'shop_days'   => $shopDays ?: null,
                'cost'        => round(array_sum(array_map(fn ($e) => (float) $e['cost'], $ep['events'])), 2),
                'garages'     => array_keys($garages),
                'sources'     => array_keys($sources),
                'ticket_ids'  => array_keys($tickets),
                // Reported through a ticket but not yet sent to a garage — the newest, most urgent case.
                'pending'     => (bool) array_filter($ep['events'], fn ($e) => $e['pending']),
                'ref_ids'     => array_merge(...array_map(fn ($e) => $e['ref_ids'], $ep['events'])),
            ];
        }

        $gaps = array_values(array_filter(array_column($chain, 'gap_days'), fn ($g) => $g !== null));

        $garages = [];
        $sources = [];
        $variants = [];
        foreach ($events as $e) {
            foreach ($e['garages'] as $g) {
                $garages[$g] = true;
            }
            $sources[$e['source']] = true;
            // The distinct wordings that fed this one chain — the proof behind the merge.
            $wording = $e['wording'] ?? $e['label'];
            if ($wording && FaultVocabulary::normalise($wording) !== $key) {
                $variants[trim((string) $wording)] = true;
            }
        }

        $dates    = array_column($events, 'date');
        $lastSeen = max($dates);

        return [
            'issue'             => $bucket['label'],
            'key'               => $key,
            // Severity of the fault family itself (critical / minor / routine) — the same classifier the
            // maintenance board uses, so a repeating brake fault outranks a repeating rattle.
            'level'             => $this->analytics->classifyPriority([$bucket['label']])['level'],
            'episodes'          => count($episodes),
            'hint'              => $this->hint($chain),
            'chain'             => $chain,
            'variants'          => array_slice(array_keys($variants), 0, 6),
            'sources'           => array_keys($sources),
            'total_visits'      => count($events),
            'total_cost'        => round(array_sum(array_map(fn ($e) => (float) $e['cost'], $events)), 2),
            'shop_days'         => array_sum(array_map(fn ($e) => (int) ($e['days'] ?? 0), $events)),
            'first_seen'        => min($dates),
            'last_seen'         => $lastSeen,
            'days_since_last'   => (int) Carbon::parse($lastSeen)->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
            'shortest_gap_days' => $gaps ? min($gaps) : null,
            'avg_gap_days'      => $gaps ? (int) round(array_sum($gaps) / count($gaps)) : null,
            'garages'           => array_keys($garages),
            'stalling'          => $this->detectStalling($events),
        ];
    }

    /**
     * The plain-language reason this fault reads as a repeat, taken from what actually broke each
     * chain link — "came back a week later", "fixed before, broke again", or for 3+ episodes the
     * blunt "broke again N separate times".
     *
     * @param  array<int,array<string,mixed>>  $chain
     */
    private function hint(array $chain): ?string
    {
        $boundaries = array_values(array_filter(array_column($chain, 'boundary')));
        if (empty($boundaries)) {
            return null;
        }
        if (count($boundaries) > 1) {
            return 'broke again ' . count($chain) . ' separate times';
        }

        return match ($boundaries[0]) {
            'gap'   => 'came back a week later',
            default => 'fixed before, broke again',
        };
    }

    /**
     * The counter-signal: the SAME garage holding the car STALL_MIN_VISITS times for this fault inside
     * STALL_WINDOW_DAYS. That is a slow workshop, not a failing car — the panel says so instead of
     * blaming the vehicle.
     *
     * @param  array<int,array<string,mixed>>  $events
     * @return array{garage:?string, vendor_id:?int, visits:int, span_days:int}|null
     */
    private function detectStalling(array $events): ?array
    {
        $byGarage = [];
        foreach ($events as $e) {
            $key = $e['vendor_id'] ?: $e['vendor'];
            if (! $key) {
                continue;   // can't attribute to a specific workshop
            }
            $byGarage[$key]['garage']    = $e['vendor'];
            $byGarage[$key]['vendor_id'] = $e['vendor_id'] ?: null;
            $byGarage[$key]['dates'][]   = $e['date'];
        }

        $best = null;
        foreach ($byGarage as $info) {
            $dates = array_values(array_unique($info['dates']));
            sort($dates);
            $n = count($dates);
            for ($i = 0; $i < $n; $i++) {
                $count = 1;
                $last  = $dates[$i];
                for ($j = $i + 1; $j < $n; $j++) {
                    if ((int) Carbon::parse($dates[$i])->diffInDays(Carbon::parse($dates[$j])) > self::STALL_WINDOW_DAYS) {
                        break;   // dates are sorted — no later one fits either
                    }
                    $count++;
                    $last = $dates[$j];
                }
                if ($count < self::STALL_MIN_VISITS) {
                    continue;
                }
                $span = (int) Carbon::parse($dates[$i])->diffInDays(Carbon::parse($last));
                if (! $best || $count > $best['visits']) {
                    $best = [
                        'garage'    => $info['garage'],
                        'vendor_id' => $info['vendor_id'],
                        'visits'    => $count,
                        'span_days' => $span,
                    ];
                }
            }
        }

        return $best;
    }

    /** @param array<int,array<string,mixed>> $faults */
    private function summarise(array $faults): array
    {
        $sources = [];
        foreach ($faults as $f) {
            foreach ($f['sources'] as $s) {
                $sources[$s] = true;
            }
        }

        return [
            'repeat_faults' => count($faults),
            // Total RETURNS, not visits: how many times this car came back for something it had already
            // been in for. One episode is the original; every episode after it is a return.
            'returns'       => array_sum(array_map(fn ($f) => $f['episodes'] - 1, $faults)),
            'total_cost'    => round(array_sum(array_column($faults, 'total_cost')), 2),
            'shop_days'     => array_sum(array_column($faults, 'shop_days')),
            'worst'         => $faults[0]['issue'] ?? null,
            'last_return'   => $faults ? max(array_column($faults, 'last_seen')) : null,
            'stalling'      => count(array_filter($faults, fn ($f) => ! empty($f['stalling']))),
            'sources'       => array_keys($sources),
        ];
    }
}
