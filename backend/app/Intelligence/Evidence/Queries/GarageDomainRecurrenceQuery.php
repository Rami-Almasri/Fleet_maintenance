<?php

namespace App\Intelligence\Evidence\Queries;

use App\Intelligence\Evidence\EvidenceQuery;
use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Support\Facades\DB;

/**
 * One cell of the Garage × Fault matrix, opened up.
 *
 * The matrix says "this garage is weak at brakes". This is the sentence's evidence: the brake
 * repairs it did, which came back, and how long each lasted. It is the drill-down that turns a
 * coloured square into something a supervisor can act on or argue with.
 */
class GarageDomainRecurrenceQuery implements EvidenceQuery
{
    /** @var string[]|null signatures that map into this domain */
    private ?array $signatures = null;

    public function __construct(
        private RecurrenceRepository $recurrence,
        private int $vendorId,
        private string $domain,
    ) {
    }

    public function id(): string
    {
        return "recurrence.garage_domain:{$this->vendorId}:{$this->domain}";
    }

    public function claim(): string
    {
        $agg = $this->aggregate();

        if ($agg->n === 0) {
            return sprintf('%s has no measured %s repairs in this window.', $this->garageName(), $this->domainLabel());
        }

        return sprintf(
            '%s — %s work: %s of %s repairs saw the same fault return within %d days (%s%%).',
            $this->garageName(),
            $this->domainLabel(),
            number_format($agg->returned),
            number_format($agg->n),
            $this->window()->windowDays,
            round($agg->returned / $agg->n * 100, 2),
        );
    }

    public function method(): string
    {
        return sprintf(
            'Only %s work is counted here (%s). For each such repair we looked for the same fault '
            . 'returning on the same car within %d days. A garage is compared against what the fleet '
            . 'averages IN THIS AREA, not overall — comparing tyre work against the all-fleet rate '
            . 'would penalise every tyre shop for a fault type that recurs more everywhere.',
            $this->domainLabel(),
            implode(', ', $this->signatures()),
            $this->window()->windowDays,
        );
    }

    public function technicalNote(): ?string
    {
        return sprintf(
            'Source: fault_recurrence_pairs (metric contract v%s), filtered to signatures mapping to '
            . 'domain `%s` via garage_recommendation.criticality.signature_categories. Deduplicated to '
            . 'one row per fault per car per day; customer damage excluded; repairs newer than %d days '
            . 'excluded as not yet judgeable.',
            config('metrics.recurrence.version'),
            $this->domain,
            $this->window()->windowDays,
        );
    }

    public function columns(): array
    {
        return ['vehicle', 'fault', 'repaired_on', 'came_back_on', 'days_between', 'outcome', 'went_back_to'];
    }

    public function rows(int $page = 1, int $perPage = 50): array
    {
        $window = $this->window();
        $sigs   = $this->signatures();

        if ($sigs === []) {
            return ['total' => 0, 'rows' => []];
        }

        $base = fn () => DB::table('fault_recurrence_pairs as p')
            ->where('p.first_vendor_id', $this->vendorId)
            ->whereIn('p.signature', $sigs)
            ->where('p.days_observed', '>=', $window->windowDays);

        $total = $base()->count();

        $rows = $base()
            ->leftJoin('vehicles as v', 'v.id', '=', 'p.vehicle_id')
            ->leftJoin('vendors as nv', 'nv.id', '=', 'p.next_vendor_id')
            // MOST RECENT FIRST, not worst first. Ordering by the gap put every failure on page one
            // and every repair that held on the last, so a reader scanning the drawer saw a wall of
            // comebacks and concluded the garage never fixes anything. Chronological order is the
            // neutral view: failures appear interleaved, exactly as they happened.
            ->orderByDesc('p.occurred_at')
            ->forPage($page, $perPage)
            ->get([
                'p.vehicle_id', 'v.plate_no', 'p.signature',
                'p.occurred_at', 'p.next_occurred_at', 'p.days_to_return',
                'p.first_maintenance_id', 'p.next_maintenance_id',
                'nv.name as next_vendor', 'p.source_row_count',
            ]);

        return [
            'total' => $total,
            'rows'  => $rows->map(fn ($r) => [
                'vehicle'          => $r->plate_no ?: "#{$r->vehicle_id}",
                'vehicle_id'       => (int) $r->vehicle_id,
                'fault'            => $r->signature,
                'repaired_on'      => $r->occurred_at,
                'came_back_on'     => $r->next_occurred_at,
                'days_between'     => $r->days_to_return === null ? null : (int) $r->days_to_return,
                'outcome'          => $r->next_occurred_at === null
                    ? 'held'
                    : ((int) $r->days_to_return <= 30 ? 'back within a month' : 'came back'),
                'went_back_to'     => $r->next_vendor,
                'ticket_id'        => (int) $r->first_maintenance_id,
                'return_ticket_id' => $r->next_maintenance_id === null ? null : (int) $r->next_maintenance_id,
                'labels_merged'    => (int) $r->source_row_count,
            ])->all(),
        ];
    }

    private function window(): RecurrenceWindow
    {
        return RecurrenceWindow::fromContract();
    }

    private function aggregate(): object
    {
        $byDomain = $this->recurrence->byGarageAndDomain($this->window(), [$this->vendorId]);
        $stats    = $byDomain[$this->vendorId][$this->domain] ?? null;

        return (object) [
            'n'        => $stats?->n ?? 0,
            'returned' => $stats?->returned ?? 0,
        ];
    }

    /** The signatures that map into this domain — read from the shared vocabulary, never hardcoded. */
    private function signatures(): array
    {
        if ($this->signatures !== null) {
            return $this->signatures;
        }

        $map = (array) config('garage_recommendation.criticality.signature_categories', []);

        return $this->signatures = array_keys(array_filter($map, fn ($d) => $d === $this->domain));
    }

    private function domainLabel(): string
    {
        foreach ((array) config('maintenance_findings.categories', []) as $c) {
            if (($c['key'] ?? null) === $this->domain) {
                return $c['label'] ?? $this->domain;
            }
        }

        return $this->domain;
    }

    private function garageName(): string
    {
        return DB::table('vendors')->where('id', $this->vendorId)->value('name') ?? "Garage #{$this->vendorId}";
    }
}
