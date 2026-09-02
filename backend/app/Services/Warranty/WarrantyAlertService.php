<?php

namespace App\Services\Warranty;

use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Support\Collection;

/**
 * The warranty conditions worth telling somebody about, as FleetAlert payloads.
 *
 * Evidence class: D (derived). Produces: alert payloads. Consumes: warranties, warranty_claims.
 * Writes nothing.
 *
 * ── WHY THIS IS NOT ITS OWN SCANNER ────────────────────────────────────────────────────────────
 *
 * It would have been easy to write `warranty:notify` and schedule it nightly. That would also have
 * duplicated, badly, three things NotificationScanner has already got right and that are much harder
 * than they look: the stable dedup key (so a condition that persists for six weeks does not raise
 * forty-two cards), the per-user permission gate applied BEFORE the row is written, and the
 * auto-resolve sweep that marks a card read once its condition clears — which is the only reason the
 * bell reflects live conditions instead of growing forever.
 *
 * So this class produces payloads and NOTHING else. NotificationScanner::detect() concatenates them
 * with everything else it detects, and they inherit all of the above for free, including the
 * ten-minute cadence and the 08:00 start-of-day sweep. Adding a warranty alert means adding a
 * detector here and one line in the ALERT_PERMISSIONS map — never a new schedule.
 *
 * ── EVERY KEY IS STABLE AND CARRIES ITS ANCHOR ─────────────────────────────────────────────────
 *
 * `warranty_expiring:{warranty_id}` and not `:{vehicle_id}`, because a car with two warranties has
 * two separate things expiring and collapsing them would silence the second. Keys are prefixed so
 * the scanner's auto-resolve can recognise them as its own — see MANAGED_KEY_PREFIXES there.
 *
 * ── THE ONE THING THIS DELIBERATELY DOES NOT DO ────────────────────────────────────────────────
 *
 * It does not alert on "vehicle is under warranty". That is a state, not an event, and a bell that
 * announces states is a bell people turn off. Every detector below fires on something CHANGING or on
 * somebody being LATE, which is the only kind of thing worth interrupting a day for.
 */
class WarrantyAlertService
{
    /** Per-detector cap, matching the scanner's own: a messy first run must not raise thousands. */
    private const CAP = 100;

    /**
     * Everything worth saying today.
     *
     * @return array<int,array> FleetAlert payloads
     */
    public function detect(): array
    {
        return collect()
            ->concat($this->expiringCover())
            ->concat($this->reviewsOverdue())
            ->concat($this->providerSilent())
            ->concat($this->casesGoingStale())
            ->all();
    }

    /**
     * Cover about to run out — on months OR on kilometres.
     *
     * The SQL narrows on the date leg only (it is the only leg that can be range-scanned), then each
     * row is judged properly against its car's odometer. That second pass is what catches the case a
     * date-only query cannot see at all: a car with eight months left and four hundred kilometres of
     * cover, which is expiring today and looks healthy in every query that only reads `expires_on`.
     *
     * Deliberately includes the distance-only warranties the date filter would exclude entirely.
     */
    private function expiringCover(): Collection
    {
        $days = (int) config('warranty.expiring_soon_days', 60);

        $rows = Warranty::query()
            ->where('status', Warranty::STATUS_ACTIVE)
            ->where(fn ($q) => $q
                ->whereNull('expires_on')                                     // distance-only: judge in PHP
                ->orWhereDate('expires_on', '>=', now()->toDateString()))
            ->where(fn ($q) => $q
                ->whereNull('expires_on')
                ->orWhereDate('expires_on', '<=', now()->addDays($days)->toDateString())
                ->orWhereNotNull('expires_at_km'))                            // km leg may bind first
            ->with(['vehicle:id,plate_no,make,model,odometer'])
            ->get()
            ->filter(fn (Warranty $w) => $w->vehicle !== null);

        return $rows
            ->map(fn (Warranty $w) => ['w' => $w, 'v' => $w->evaluate(null, $w->vehicle->odometer !== null ? (int) $w->vehicle->odometer : null)])
            ->filter(fn ($j) => $j['v']['state'] === Warranty::STATE_ACTIVE && $j['v']['expiring_soon'] === true)
            ->take(self::CAP)
            ->map(function ($j) {
                /** @var Warranty $w */
                $w = $j['w'];
                $left = $j['v']['remaining_evidence'] ?: 'ending soon';

                return [
                    'type'     => 'warranty_expiring',
                    'category' => 'warranty',
                    // Warning, not critical: nothing is broken and nobody is late. What is being
                    // asked for is a decision to look, which is a different urgency from a car that
                    // is already stuck.
                    'severity' => 'warning',
                    'title'    => 'Warranty expiring · ' . $left . ' left',
                    'body'     => trim(($w->vehicle->plate_no ?: 'Vehicle') . ' · ' . $w->subject
                        . ' — ' . ($w->provider_name ?: 'provider') . '. '
                        . 'Inspect before cover ends: anything found afterwards is ours to pay for.'),
                    'url'      => '/vehicles/' . $w->vehicle_id . '?tab=warranty',
                    'key'      => 'warranty_expiring:' . $w->id,
                    'icon'     => 'shield',
                    'meta'     => [
                        'warranty_id'    => $w->id,
                        'vehicle_id'     => $w->vehicle_id,
                        'plate'          => $w->vehicle->plate_no,
                        'expires_on'     => $w->expires_on?->toDateString(),
                        'days_remaining' => $j['v']['days_remaining'],
                        'km_remaining'   => $j['v']['km_remaining'],
                    ],
                ];
            })
            ->values();
    }

    /**
     * A coverage review nobody has answered.
     *
     * The most expensive queue in the feature to leave unattended, and the least obviously urgent —
     * which is exactly why it is alerted on. An unanswered review is holding a purchase request
     * hostage: the car is not being repaired AND the money is not being spent, and the pressure that
     * builds is pressure to override the gate rather than to answer the question.
     */
    private function reviewsOverdue(): Collection
    {
        $cutoff = now()->subDays((int) config('warranty.review_overdue_days', 2));

        return WarrantyClaim::query()
            ->awaitingReview()
            ->where('created_at', '<=', $cutoff)
            ->with(['vehicle:id,plate_no,make,model'])
            ->orderBy('created_at')
            ->limit(self::CAP)
            ->get()
            ->map(fn (WarrantyClaim $c) => [
                'type'     => 'warranty_review_overdue',
                'category' => 'warranty',
                // Critical: something is actively blocked on this, and has been for days.
                'severity' => 'critical',
                'title'    => 'Warranty coverage review waiting',
                'body'     => trim(($c->vehicle?->plate_no ?: 'Vehicle') . ' · ' . $c->subject
                    . ' — open since ' . $c->created_at?->toDateString()
                    . '. A purchase is held until this is answered.'),
                'url'      => '/warranty/cases/' . $c->id,
                'key'      => 'warranty_review:' . $c->id,
                'icon'     => 'shield',
                'meta'     => [
                    'warranty_case_id' => $c->id,
                    'vehicle_id'       => $c->vehicle_id,
                    'plate'            => $c->vehicle?->plate_no,
                    'days_open'        => $c->created_at ? (int) $c->created_at->diffInDays(now()) : null,
                ],
            ])
            ->values();
    }

    /**
     * The provider has gone quiet past the date they were expected to answer.
     *
     * Undated cases are never overdue — see WarrantyClaim::providerOverdue(). Inventing an SLA the
     * dealer never agreed to would put a permanent alert on every open case, and a bell that is
     * always ringing is a bell nobody hears.
     */
    private function providerSilent(): Collection
    {
        return WarrantyClaim::query()
            ->awaitingProvider()
            ->whereNotNull('provider_response_due_on')
            ->whereDate('provider_response_due_on', '<', now()->toDateString())
            ->with(['vehicle:id,plate_no', 'warranty:id,provider_name,contact_name,contact_phone'])
            ->orderBy('provider_response_due_on')
            ->limit(self::CAP)
            ->get()
            ->map(function (WarrantyClaim $c) {
                $late = (int) $c->provider_response_due_on->diffInDays(now());

                return [
                    'type'     => 'warranty_provider_overdue',
                    'category' => 'warranty',
                    'severity' => $late >= 7 ? 'critical' : 'warning',
                    'title'    => 'Dealer response overdue · ' . $late . 'd',
                    'body'     => trim(($c->vehicle?->plate_no ?: 'Vehicle') . ' · ' . $c->subject
                        . ' — ' . ($c->warranty?->provider_name ?: 'the provider')
                        . ' was due to answer on ' . $c->provider_response_due_on->toDateString() . '.'
                        . ($c->warranty?->contact_phone ? ' Call ' . $c->warranty->contact_phone . '.' : '')),
                    'url'      => '/warranty/cases/' . $c->id,
                    'key'      => 'warranty_provider_overdue:' . $c->id,
                    'icon'     => 'clock',
                    'meta'     => [
                        'warranty_case_id' => $c->id,
                        'vehicle_id'       => $c->vehicle_id,
                        'plate'            => $c->vehicle?->plate_no,
                        'stage'            => $c->stage,
                        'days_late'        => $late,
                        'contact_name'     => $c->warranty?->contact_name,
                        'contact_phone'    => $c->warranty?->contact_phone,
                    ],
                ];
            })
            ->values();
    }

    /**
     * An open case nobody has touched in a fortnight.
     *
     * Claims are lost by being forgotten far more often than by being refused. This is the only
     * detector that fires on the ABSENCE of activity, which is why its key carries no stage: the
     * condition is precisely that the stage has not changed.
     */
    private function casesGoingStale(): Collection
    {
        $cutoff = now()->subDays((int) config('warranty.case_stale_days', 14));

        return WarrantyClaim::query()
            ->openCases()
            // The review queue has its own, sharper alert two days in — do not chase it twice.
            ->where('stage', '!=', WarrantyClaim::STAGE_COVERAGE_REVIEW)
            ->where('updated_at', '<=', $cutoff)
            ->with(['vehicle:id,plate_no'])
            ->orderBy('updated_at')
            ->limit(self::CAP)
            ->get()
            ->map(fn (WarrantyClaim $c) => [
                'type'     => 'warranty_case_stale',
                'category' => 'warranty',
                'severity' => 'warning',
                'title'    => 'Warranty case has not moved',
                'body'     => trim(($c->vehicle?->plate_no ?: 'Vehicle') . ' · ' . $c->subject
                    . ' — at ' . $c->stage . ' since ' . $c->updated_at?->toDateString()
                    . '. A claim nobody chases is a claim we lose.'),
                'url'      => '/warranty/cases/' . $c->id,
                'key'      => 'warranty_case_stale:' . $c->id,
                'icon'     => 'shield',
                'meta'     => [
                    'warranty_case_id' => $c->id,
                    'vehicle_id'       => $c->vehicle_id,
                    'plate'            => $c->vehicle?->plate_no,
                    'stage'            => $c->stage,
                ],
            ])
            ->values();
    }
}
