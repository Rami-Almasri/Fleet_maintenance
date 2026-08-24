<?php

namespace App\Services\Reports;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\GarageRecommendationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * DAILY FLEET MAINTENANCE INTELLIGENCE — the day's workshop file, read back as a report.
 *
 * This is the report the Controllers used to assemble by hand out of the maintenance sheet each
 * morning: who is in a garage today, how bad it is, how long it has been there, what the garage
 * actually did, and which cases a manager must look at before anything else.
 *
 * WHERE EVERY NUMBER COMES FROM (see [[traceability-visibility-requirement]] — the page prints this):
 *
 *   • The rows are the LIVE workshop log — `maintenances` rows with origin 'sheet' or 'manual'
 *     (Maintenance::WORKSHOP_LOG_ORIGINS). That is the same log the maintenance board reads, so the
 *     report and the board can never disagree.
 *   • A car is on the report for a given day if the day TOUCHED it: the car went out, was followed
 *     up, or came back that day — OR the visit is still open (no actual_in_date) and started within
 *     the look-back window. Nothing is inferred: a car with no entry is simply not on the report.
 *   • Severity is the ticket's own `fault_severity`, falling back to the legacy `severity` column.
 *     A row that carries neither is reported as UNRATED, never guessed at.
 *   • The category counts (Brake/Tyre, A/C, Body, Electrical …) come from the finding keyword the
 *     row was logged under, resolved through config/maintenance_findings.php by
 *     Maintenance::categoryForKeyword(). A row whose wording matches no keyword counts in nothing.
 *
 * Nothing here writes. It is a read-only view over the log — see [[evidence-layer-governance]]:
 * every field below is a FACT already recorded by a person, or a COUNT of those facts. There are no
 * confidence scores and no predictions.
 */
class DailyMaintenanceIntelligenceService
{
    public function __construct(private GarageRecommendationService $categories)
    {
    }

    /**
     * How far back an unclosed visit still counts as "active today". A car that went to a garage two
     * months ago and never had a return logged is a data problem, not today's operational picture, so
     * it drops off the report rather than padding the counts forever.
     */
    private const OPEN_VISIT_LOOKBACK_DAYS = 45;

    /**
     * The category groupings the KPI strip reports, in the wording the daily file has always used.
     * Each maps onto one or more category keys from config/maintenance_findings.php.
     */
    private const KPI_GROUPS = [
        'brake_tyre'          => ['label' => 'Brake / Tyre',          'keys' => ['brakes', 'tyres']],
        'ac_cooling'          => ['label' => 'A/C / Cooling',         'keys' => ['ac']],
        'body_cosmetic'       => ['label' => 'Body / Cosmetic',       'keys' => ['bodywork']],
        'electrical_interior' => ['label' => 'Electrical / Interior', 'keys' => ['electrical', 'interior', 'lights']],
        'engine_drivetrain'   => ['label' => 'Engine / Drivetrain',   'keys' => ['engine', 'transmission', 'fluids']],
    ];

    /** Severity ordering, worst first — drives the alert list and the table sort. */
    private const SEVERITY_RANK = [
        Maintenance::FAULT_SEVERITY_CRITICAL => 4,
        Maintenance::FAULT_SEVERITY_HIGH     => 3,
        Maintenance::FAULT_SEVERITY_MODERATE => 2,
        Maintenance::FAULT_SEVERITY_ROUTINE  => 1,
    ];

    /**
     * Build the whole report for one calendar day.
     *
     * @param  CarbonInterface|null  $date  The day to report on; today when omitted.
     */
    public function build(?CarbonInterface $date = null): array
    {
        $day  = ($date ? Carbon::parse($date) : Carbon::today())->startOfDay();
        $rows = $this->activeCases($day);

        return [
            'meta'      => $this->meta($day, $rows),
            'kpis'      => $this->kpis($rows),
            'cases'     => $rows->values()->all(),
            'alerts'    => $this->alerts($rows),
            'garages'   => $this->garageLoad($rows),
            'provenance'=> $this->provenance($day, $rows),
        ];
    }

    // ── The rows ────────────────────────────────────────────────────────────────────────────────

    /**
     * Every vehicle with a workshop entry that the given day touched, plus the visits still open.
     * One line per VEHICLE — a car with two entries the same day (out in the morning, back in the
     * afternoon) is one case, reported at its most recent entry.
     */
    private function activeCases(Carbon $day): Collection
    {
        $cutoff = $day->copy()->subDays(self::OPEN_VISIT_LOOKBACK_DAYS);

        $events = Maintenance::query()
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->where(function ($q) use ($day, $cutoff) {
                // The day touched this visit …
                $q->whereDate('out_date', $day)
                  ->orWhereDate('follow_date', $day)
                  ->orWhereDate('actual_in_date', $day)
                  // … or the car went in and no return has been logged yet.
                  ->orWhere(function ($open) use ($day, $cutoff) {
                      $open->whereNull('actual_in_date')
                           ->whereNotNull('out_date')
                           ->whereDate('out_date', '<=', $day)
                           ->whereDate('out_date', '>=', $cutoff);
                  });
            })
            ->with(['vehicle:id,plate_no,make,model,year,color,vin', 'vendor:id,name'])
            ->orderByDesc('out_date')
            ->orderByDesc('id')
            ->get();

        return $events
            ->groupBy(fn (Maintenance $m) => $this->carKey($m))
            ->map(fn (Collection $group) => $this->case($group->first(), $group, $day))
            ->sortByDesc(fn (array $case) => [$case['severity_rank'], $case['days']])
            ->values();
    }

    /**
     * One car, one line — even when the sheet row never got linked to a vehicle record. The linked
     * vehicle is the best key; failing that the plate, then the label the sheet wrote. Only a row
     * that identifies its car in NO way at all falls back to standing alone, because merging those
     * would silently fuse unrelated cars into one case.
     */
    private function carKey(Maintenance $m): string
    {
        if ($m->vehicle_id) {
            return 'v:' . $m->vehicle_id;
        }

        $plate = $m->plate ?: ($m->vehicle->plate_no ?? null);
        if ($plate && trim($plate) !== '') {
            return 'p:' . mb_strtolower(preg_replace('/\s+/', '', $plate));
        }

        if ($m->car_label && trim($m->car_label) !== '') {
            return 'l:' . mb_strtolower(trim($m->car_label));
        }

        return 'row:' . $m->id;
    }

    /** One line of the report: the car, how bad, where, how long, what is wrong, what was done. */
    private function case(Maintenance $m, Collection $group, Carbon $day): array
    {
        $severity = $this->severityOf($m);

        return [
            'id'            => $m->id,
            'vehicle_id'    => $m->vehicle_id,
            'vehicle'       => $this->vehicleLabel($m),
            'plate'         => $m->plate ?: ($m->vehicle->plate_no ?? null),
            'vin'           => $m->vehicle->vin ?? null,
            'severity'      => $severity,
            'severity_label'=> $severity ? (Maintenance::FAULT_SEVERITY_META[$severity]['label'] ?? Str::title($severity)) : 'Unrated',
            'severity_rank' => $severity ? (self::SEVERITY_RANK[$severity] ?? 0) : 0,
            'status'        => $this->statusLabel($group),
            'garage'        => $this->garageName($group),
            'days'          => $this->daysOpen($m, $day),
            'issues'        => $this->issueText($m),
            'progress'      => $this->progressText($m),
            'categories'    => $this->categoriesOf($m),
            'entries'       => $group->count(),
            'out_date'      => optional($m->out_date)->toDateString(),
            'in_date'       => optional($m->actual_in_date)->toDateString(),
            'bucket'        => $this->bucket($group, $day),
        ];
    }

    /**
     * Which of the two populations this case belongs to. They are kept apart on purpose: the first is
     * the day's file, the second is the standing backlog, and averaging them into one number is how a
     * quiet day reads as a busy one.
     *
     *   'today'     — the day recorded something against this car: it went out, was followed up, or
     *                 came back.
     *   'still_out' — the car went to a garage on an earlier day and no return has been logged. It is
     *                 still the fleet's problem, but nothing happened to it today.
     */
    private function bucket(Collection $group, Carbon $day): string
    {
        $touchedToday = $group->contains(function (Maintenance $m) use ($day) {
            foreach ([$m->out_date, $m->follow_date, $m->actual_in_date] as $date) {
                if ($date && $date->isSameDay($day)) {
                    return true;
                }
            }

            return false;
        });

        return $touchedToday ? 'today' : 'still_out';
    }

    // ── Field resolvers — each one says what it read, and stays silent when nothing was recorded ──

    /** "FORD MUSTANG - Red - 2023 - V 23733", the way the daily file has always written a car. */
    private function vehicleLabel(Maintenance $m): string
    {
        if ($m->car_label) {
            return $m->car_label;
        }

        /** @var Vehicle|null $v */
        $v = $m->vehicle;
        if (! $v) {
            return $m->plate ?: 'Unidentified vehicle';
        }

        return collect([
            trim(($v->make ?? '') . ' ' . ($v->model ?? '')),
            $v->color,
            $v->year,
            $v->plate_no,
        ])->filter(fn ($p) => $p !== null && $p !== '')->implode(' - ');
    }

    /** The ticket's own severity. The legacy column is the fallback; nothing is inferred from text. */
    private function severityOf(Maintenance $m): ?string
    {
        $value = $m->fault_severity ?: $m->severity;
        $value = $value ? mb_strtolower(trim($value)) : null;

        return isset(self::SEVERITY_RANK[$value]) ? $value : null;
    }

    /**
     * The workshop stage(s) the day recorded. A car that went out and came back the same day reads
     * "IN / OUT" — the daily file's own shorthand — rather than hiding one of the two entries.
     */
    private function statusLabel(Collection $group): string
    {
        $stages = $group->pluck('event_status')
            ->filter(fn ($s) => $s !== null && trim($s) !== '')
            ->unique()
            ->values();

        return $stages->isEmpty() ? 'No stage recorded' : $stages->implode(' / ');
    }

    /**
     * The garage the work is at. The linked vendor record wins over the free-typed `garage` column,
     * because the vendor is the row a person picked; the text field is whatever the sheet said.
     */
    private function garageName(Collection $group): string
    {
        $names = $group
            ->map(fn (Maintenance $m) => $m->vendor->name ?? ($m->garage ?: null))
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? 'Not recorded' : $names->implode(' / ');
    }

    /** Days since the car went in, counted to the report date. Null out_date means we cannot say. */
    private function daysOpen(Maintenance $m, Carbon $day): ?int
    {
        if (! $m->out_date) {
            return null;
        }

        $end = $m->actual_in_date && $m->actual_in_date->lt($day) ? $m->actual_in_date : $day;

        return max(0, $m->out_date->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
    }

    /** What is wrong — the finding the row was logged under, plus the requester's own words. */
    private function issueText(Maintenance $m): string
    {
        $parts = collect([
            $m->service_main,
            $m->service_sup,
            $m->customer_complaint,
            $m->trigger_detail,
            $m->findings,
        ])->map(fn ($p) => is_string($p) ? trim($p) : null)->filter()->unique();

        return $parts->isEmpty() ? 'No issue text recorded' : $parts->implode(' · ');
    }

    /** What was actually done — the garage's feedback and the notes kept against the visit. */
    private function progressText(Maintenance $m): string
    {
        $parts = collect([$m->garage_feedback, $m->maintenance_notes, $m->spare_part])
            ->map(fn ($p) => is_string($p) ? trim($p) : null)
            ->filter()
            ->unique();

        return $parts->isEmpty() ? 'No work recorded yet' : $parts->implode("\n");
    }

    /**
     * Which systems the case touches.
     *
     * The sheet writes its own vocabulary in service_main / service_sup — "Body & Exterior", "Tires",
     * "Air Conditioning" — comma-separated, and ONE VISIT ROUTINELY NAMES SEVERAL SYSTEMS ("Engine,
     * Body & Exterior"). So this returns a list, not a single key, and the KPI groups below count a
     * car under every system it actually names rather than silently keeping only the first.
     *
     * The reading is done by GarageRecommendationService::extractCategories — the platform's single
     * sanctioned reader of that free text (config/fault_extraction.php), already used by the repair
     * cost estimator. Sharing it is the point: this report and the estimator group the same row the
     * same way. It is conservative by design, so wording it does not recognise yields no category and
     * the car is counted in no group — a smaller number rather than a wrong one.
     *
     * @return array<int, string>
     */
    private function categoriesOf(Maintenance $m): array
    {
        return $this->categories->extractCategories($m->service_main, $m->service_sup, $m->findings);
    }

    // ── The strip along the top ─────────────────────────────────────────────────────────────────

    private function kpis(Collection $rows): array
    {
        $today    = $rows->where('bucket', 'today');
        $stillOut = $rows->where('bucket', 'still_out');

        $kpis = [
            ['key' => 'today',     'label' => "Today's Entries",  'value' => $today->count(),
             'note' => 'Cars the day recorded something against.'],
            ['key' => 'still_out', 'label' => 'Still Out',        'value' => $stillOut->count(),
             'note' => 'Went in earlier; no return logged yet.'],
            ['key' => 'returned',  'label' => 'Returned Today',   'value' => $rows->filter(fn ($r) => $r['in_date'] !== null && $r['bucket'] === 'today')->count(),
             'note' => 'A return was logged on this date.'],
            ['key' => 'critical',  'label' => 'Critical Cases',   'value' => $rows->where('severity', Maintenance::FAULT_SEVERITY_CRITICAL)->count(),
             'note' => 'Severity set to Critical on the ticket.'],
            ['key' => 'high',      'label' => 'High Risk',        'value' => $rows->where('severity', Maintenance::FAULT_SEVERITY_HIGH)->count(),
             'note' => 'Severity set to High on the ticket.'],
        ];

        foreach (self::KPI_GROUPS as $key => $group) {
            $kpis[] = [
                'key'   => $key,
                'label' => $group['label'],
                'value' => $rows->filter(fn ($r) => array_intersect($r['categories'], $group['keys']) !== [])->count(),
                'note'  => 'Logged under a ' . mb_strtolower($group['label']) . ' finding.',
            ];
        }

        $unrated = $rows->whereNull('severity')->count();
        $kpis[] = [
            'key'   => 'unrated',
            'label' => 'Unrated',
            'value' => $unrated,
            'note'  => $unrated > 0 ? 'No severity was set on these tickets.' : 'Every case carries a severity.',
        ];

        return $kpis;
    }

    // ── The alert column ────────────────────────────────────────────────────────────────────────

    /**
     * The cases a manager must read first: everything Critical or High, worst and longest-standing
     * at the top. Each alert is the car, what is wrong and what has been done — a sentence assembled
     * from the recorded fields, not a judgement added on top of them.
     */
    private function alerts(Collection $rows): array
    {
        return $rows
            ->filter(fn ($r) => in_array($r['severity'], [Maintenance::FAULT_SEVERITY_CRITICAL, Maintenance::FAULT_SEVERITY_HIGH], true))
            ->take(12)
            ->map(fn ($r) => [
                'severity'   => $r['severity'],
                'label'      => mb_strtoupper($r['severity_label']),
                'vehicle'    => $r['vehicle'],
                'vehicle_id' => $r['vehicle_id'],
                'text'       => $this->alertSentence($r),
            ])
            ->values()
            ->all();
    }

    private function alertSentence(array $r): string
    {
        $where = $r['garage'] === 'Not recorded' ? 'no garage recorded' : 'at ' . $r['garage'];
        $age   = $r['days'] === null
            ? 'no start date on record'
            : ($r['days'] === 0 ? 'went in today' : $r['days'] . ' days in');

        return sprintf('%s — %s (%s, %s). %s', $r['vehicle'], $r['issues'], $where, $age, $r['progress']);
    }

    // ── Garage load ─────────────────────────────────────────────────────────────────────────────

    /** How many of today's cars each garage is holding, and which cars they are. */
    private function garageLoad(Collection $rows): array
    {
        return $rows
            ->groupBy('garage')
            ->map(fn (Collection $g, string $name) => [
                'garage'   => $name,
                'load'     => $g->count(),
                'critical' => $g->where('severity', Maintenance::FAULT_SEVERITY_CRITICAL)->count(),
                'activity' => $g->pluck('vehicle')->take(6)->implode(', '),
            ])
            ->sortByDesc('load')
            ->values()
            ->all();
    }

    // ── Header + Data Origin ────────────────────────────────────────────────────────────────────

    private function meta(Carbon $day, Collection $rows): array
    {
        $top = $rows->first();

        return [
            'date'       => $day->toDateString(),
            'date_label' => $day->format('d F Y'),
            'title'      => 'Daily Fleet Maintenance Intelligence',
            'active'     => $rows->count(),
            'top_alert'  => $top ? $this->alertSentence($top) : 'No workshop entries were logged for this day.',
            'generated'  => Carbon::now()->toDateTimeString(),
            'latest_entry_date' => $this->latestEntryDate(),
        ];
    }

    /**
     * The most recent day the workshop log has an entry for.
     *
     * The page defaults to TODAY and stays there even when today is empty — "nothing was logged
     * today" is a real finding, and quietly sliding the report back to the last busy day would hide
     * a stalled sheet import behind a full-looking screen. But an empty report with no explanation is
     * indistinguishable from a broken one, so the page names the last date that does have entries and
     * offers to jump there. See [[scheduler-audit]] — stale imports look like quiet days.
     */
    private function latestEntryDate(): ?string
    {
        $latest = Maintenance::query()
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->max('out_date');

        return $latest ? Carbon::parse($latest)->toDateString() : null;
    }

    /**
     * The Data Origin block the page prints under the report — what was read, over what window, and
     * what was deliberately left out. Required of every page; see [[traceability-visibility-requirement]].
     */
    private function provenance(Carbon $day, Collection $rows): array
    {
        return [
            'source'  => 'maintenances (origin: ' . implode(', ', Maintenance::WORKSHOP_LOG_ORIGINS) . ') — the live workshop log',
            'window'  => 'Entries dated ' . $day->toDateString() . ', plus visits opened since '
                         . $day->copy()->subDays(self::OPEN_VISIT_LOOKBACK_DAYS)->toDateString() . ' with no return logged',
            'grouping'=> 'One line per vehicle; a car with several entries the same day is shown at its most recent one',
            'omitted' => 'Retired tickets (soft-deleted) are excluded. Rows with no severity are listed as Unrated and counted separately, never assumed.',
            'counts'  => [
                'cases'     => $rows->count(),
                'today'     => $rows->where('bucket', 'today')->count(),
                'still_out' => $rows->where('bucket', 'still_out')->count(),
                'unrated'   => $rows->whereNull('severity')->count(),
                'no_garage' => $rows->where('garage', 'Not recorded')->count(),
            ],
        ];
    }
}
