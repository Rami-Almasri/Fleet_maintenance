<?php

namespace App\Intelligence\Evidence\Queries;

use App\Intelligence\Evidence\EvidenceQuery;
use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Support\Facades\DB;

/**
 * "Why does this garage have that recurrence rate?" — answered with the repairs.
 *
 * ── THE PAIRED VIEW IS THE POINT ─────────────────────────────────────────────────────────────────
 * Each row is a repair AND the return that followed it: two tickets, two dates, and the gap. A list
 * of tickets alone would show what happened without showing why it counted against the garage, and
 * the reader would be back to trusting us.
 *
 * Repairs that HELD are included, not filtered out. Showing only the failures would make every
 * garage look catastrophic and would misrepresent the denominator — the rate is failures over
 * opportunities, and both halves belong on screen.
 */
class GarageRecurrenceQuery implements EvidenceQuery
{
    private ?object $vendor = null;

    public function __construct(
        private RecurrenceRepository $recurrence,
        private int $vendorId,
    ) {
    }

    public function id(): string
    {
        return "recurrence.garage:{$this->vendorId}";
    }

    public function claim(): string
    {
        $stats = $this->recurrence->byGarage($this->window(), [$this->vendorId])[$this->vendorId] ?? null;

        if ($stats === null || $stats->n === 0) {
            return "{$this->garageName()} has no measured repairs in this window.";
        }

        $median = $this->recurrence->medianGap($this->window(), ['first_vendor_id' => $this->vendorId]);

        return sprintf(
            '%s: %s of %s repairs saw the same fault return within %d days (%s%%)%s.',
            $this->garageName(),
            number_format($stats->returned),
            number_format($stats->n),
            $this->window()->windowDays,
            $stats->rate(),
            $median !== null ? ", typically after {$median} days" : '',
        );
    }

    public function method(): string
    {
        return 'For every repair this garage completed, we looked for the same fault being recorded '
            . 'again on the same car within ' . $this->window()->windowDays . ' days. Each row below is '
            . 'one repair and, where there was one, the visit it came back on. Repairs that held are '
            . 'shown too — the rate is returns out of opportunities, so both halves matter.';
    }

    public function technicalNote(): ?string
    {
        $c = $this->recurrence->coverage($this->window());

        return sprintf(
            'Source: fault_recurrence_pairs (metric contract v%s). One fault, on one car, on one day '
            . 'counts once however many times it was labelled. Customer damage (body, rim) is excluded. '
            . 'Repairs newer than %d days are excluded because they have not had time to fail yet — '
            . '%s of %s events are fully observed. Retired tickets are included: the repair happened.',
            config('metrics.recurrence.version'),
            $this->window()->windowDays,
            number_format($c->covered),
            number_format($c->total),
        );
    }

    public function columns(): array
    {
        return ['vehicle', 'fault', 'repaired_on', 'came_back_on', 'days_between', 'outcome', 'went_back_to'];
    }

    public function rows(int $page = 1, int $perPage = 50): array
    {
        $window = $this->window();

        $base = fn () => DB::table('fault_recurrence_pairs as p')
            ->where('p.first_vendor_id', $this->vendorId)
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
                'vehicle'       => $r->plate_no ?: "#{$r->vehicle_id}",
                'vehicle_id'    => (int) $r->vehicle_id,
                'fault'         => $r->signature,
                'repaired_on'   => $r->occurred_at,
                'came_back_on'  => $r->next_occurred_at,
                'days_between'  => $r->days_to_return === null ? null : (int) $r->days_to_return,
                // The word, not just the gap — a reader scanning fifty rows needs the verdict first.
                'outcome'       => $r->next_occurred_at === null
                    ? 'held'
                    : ((int) $r->days_to_return <= 30 ? 'back within a month' : 'came back'),
                'went_back_to'  => $r->next_vendor,
                'ticket_id'     => (int) $r->first_maintenance_id,
                'return_ticket_id' => $r->next_maintenance_id === null ? null : (int) $r->next_maintenance_id,
                // How many labels collapsed into this one repair. Visible so the deduplication is
                // auditable rather than something the reader has to take on faith.
                'labels_merged' => (int) $r->source_row_count,
            ])->all(),
        ];
    }

    private function window(): RecurrenceWindow
    {
        return RecurrenceWindow::fromContract();
    }

    private function garageName(): string
    {
        $this->vendor ??= DB::table('vendors')->where('id', $this->vendorId)->first(['name']);

        return $this->vendor->name ?? "Garage #{$this->vendorId}";
    }
}
