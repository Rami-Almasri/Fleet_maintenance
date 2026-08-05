<?php

namespace App\Intelligence\Evidence\Queries;

use App\Intelligence\Evidence\EvidenceQuery;
use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use Illuminate\Support\Facades\DB;

/**
 * The SCHEDULED WORK a garage did — the other half of the evidence drawer.
 *
 * ── THIS IS A LIST, NOT A RATE ───────────────────────────────────────────────────────────────────
 * Every other evidence query proves a number. This one deliberately proves nothing: services recur
 * because they are supposed to, so "512 of 1,073 came back within 90 days" is a true sentence about
 * scheduled maintenance and a meaningless one about garage quality. Under contract v2.0.0 that
 * number was being computed anyway and folded into every garage's comeback rate at 47.72% — above
 * the 46.38% real faults recur at — which is what v2.1.0 corrected.
 *
 * So these rows exist to be SEEN, not scored. Keeping them visible is what makes the exclusion
 * auditable: "we removed 1,073 services from the rate" is a claim, and this is where somebody checks
 * it. Deleting them from the corpus would have been simpler and would have made the correction
 * indistinguishable from data loss.
 *
 * ── WHAT THE INTERVAL COLUMN CAN AND CANNOT SAY ──────────────────────────────────────────────────
 * A service is due on time, on distance, or on whichever comes first, and `service_catalog` records
 * that per service (oil_change: 10,000 km OR 6 months; brake_pads: km only; ac_service: months
 * only). So the EXPECTED interval is published in whichever units actually govern it.
 *
 * What is measured against it is time only. The odometer is absent from this corpus — 5 of 1,181
 * oil-service pairs carry a reading at the repair and 3 at the return — so "km since last service"
 * would be blank on 99.6% of rows. It is reported as not recorded rather than left empty, because a
 * blank column reads as a broken page and an honest one reads as a gap somebody could go and close.
 */
class GarageServiceQuery implements EvidenceQuery
{
    /**
     * Recurrence signature → the catalog row that governs its cadence.
     *
     * One entry, matching RepairSignatureClassifier::SERVICE_SIGNATURES. It is a map rather than a
     * constant so that widening the service vocabulary is a data change here, not a rewrite.
     */
    private const SIGNATURE_CATALOG = ['OIL_SERVICE' => 'oil_change'];

    private ?object $vendor = null;

    /** @var array<string, object>|null */
    private ?array $catalog = null;

    public function __construct(
        private RecurrenceRepository $recurrence,
        private int $vendorId,
    ) {
    }

    public function id(): string
    {
        return "recurrence.garage_services:{$this->vendorId}";
    }

    public function claim(): string
    {
        $n = $this->scope()->count();

        if ($n === 0) {
            return "{$this->garageName()} has no scheduled services on record in this window.";
        }

        $median = $this->medianGap();

        return sprintf(
            '%s carried out %s scheduled service%s. %sThese are excluded from the garage\'s comeback '
            . 'rate: a service repeating is the schedule working, not a repair failing.',
            $this->garageName(),
            number_format($n),
            $n === 1 ? '' : 's',
            $median !== null ? "They were repeated after {$median} days on average. " : '',
        );
    }

    public function method(): string
    {
        return 'Every repair typed as scheduled work rather than a fault, and where the same service '
            . 'was carried out again on the same car, the gap between the two. The expected interval '
            . 'comes from the service schedule — some services are due on time, some on distance, and '
            . 'each row shows whichever governs it. Nothing here is scored.';
    }

    public function technicalNote(): ?string
    {
        return sprintf(
            'Source: fault_recurrence_pairs WHERE kind = service (metric contract v%s, '
            . 'filters.exclude_services). Kind is stamped at rebuild from '
            . 'RepairSignatureClassifier::SERVICE_SIGNATURES. Expected intervals read from '
            . 'service_catalog. Distance travelled is NOT measured: the odometer is present on 5 of '
            . '1,181 service pairs at the repair and 3 at the return, so km-since-service is reported '
            . 'as not recorded rather than computed from almost nothing.',
            config('metrics.recurrence.version'),
        );
    }

    public function columns(): array
    {
        return ['vehicle', 'service', 'done_on', 'done_again_on', 'days_between', 'due_every', 'garage'];
    }

    public function rows(int $page = 1, int $perPage = 50): array
    {
        $total = $this->scope()->count();

        $rows = $this->scope()
            ->leftJoin('vehicles as v', 'v.id', '=', 'p.vehicle_id')
            ->leftJoin('vendors as nv', 'nv.id', '=', 'p.next_vendor_id')
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
            'rows'  => $rows->map(function ($r) {
                $catalog = $this->catalogFor($r->signature);

                return [
                    'vehicle'          => $r->plate_no ?: "#{$r->vehicle_id}",
                    'vehicle_id'       => (int) $r->vehicle_id,
                    'service'          => $catalog?->name ?? $r->signature,
                    'done_on'          => $r->occurred_at,
                    'done_again_on'    => $r->next_occurred_at,
                    'days_between'     => $r->days_to_return === null ? null : (int) $r->days_to_return,
                    // The cadence that actually governs THIS service, in its own units.
                    'due_every'        => $this->dueEvery($catalog),
                    'due_every_km'     => $catalog?->interval_km === null ? null : (int) $catalog->interval_km,
                    'due_every_months' => $catalog?->interval_months === null ? null : (int) $catalog->interval_months,
                    // Stated, not blank. The reader learns the odometer is missing rather than
                    // assuming the page is broken.
                    'km_between'       => null,
                    'garage'           => $r->next_vendor,
                    'ticket_id'        => (int) $r->first_maintenance_id,
                    'return_ticket_id' => $r->next_maintenance_id === null ? null : (int) $r->next_maintenance_id,
                    'labels_merged'    => (int) $r->source_row_count,
                ];
            })->all(),
        ];
    }

    /** "10,000 km or 6 months" / "40,000 km" / "12 months" / null when the schedule says nothing. */
    private function dueEvery(?object $catalog): ?string
    {
        if ($catalog === null) {
            return null;
        }

        $parts = [];

        if ($catalog->interval_km !== null) {
            $parts[] = number_format((int) $catalog->interval_km) . ' km';
        }

        if ($catalog->interval_months !== null) {
            $months = (int) $catalog->interval_months;
            $parts[] = $months . ' month' . ($months === 1 ? '' : 's');
        }

        // "or", not "and" — whichever comes first is what makes a service due.
        return $parts === [] ? null : implode(' or ', $parts);
    }

    private function catalogFor(string $signature): ?object
    {
        $slug = self::SIGNATURE_CATALOG[$signature] ?? null;

        if ($slug === null) {
            return null;
        }

        $this->catalog ??= DB::table('service_catalog')
            ->get(['slug', 'name', 'interval_km', 'interval_months'])
            ->keyBy('slug')
            ->all();

        return $this->catalog[$slug] ?? null;
    }

    private function medianGap(): ?int
    {
        $gaps = $this->scope()
            ->whereNotNull('p.days_to_return')
            ->orderBy('p.days_to_return')
            ->pluck('p.days_to_return')
            ->all();

        if ($gaps === []) {
            return null;
        }

        $mid = intdiv(count($gaps), 2);

        return (int) round(count($gaps) % 2 ? $gaps[$mid] : ($gaps[$mid - 1] + $gaps[$mid]) / 2);
    }

    private function scope()
    {
        $window = RecurrenceWindow::fromContract();

        return DB::table('fault_recurrence_pairs as p')
            ->where('p.first_vendor_id', $this->vendorId)
            ->where('p.kind', RecurrenceRepository::KIND_SERVICE)
            ->where('p.days_observed', '>=', $window->windowDays);
    }

    private function garageName(): string
    {
        $this->vendor ??= DB::table('vendors')->where('id', $this->vendorId)->first(['name']);

        return $this->vendor->name ?? "Garage #{$this->vendorId}";
    }
}
