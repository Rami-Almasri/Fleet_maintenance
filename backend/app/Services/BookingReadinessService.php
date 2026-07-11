<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Contract;
use App\Models\InspectionRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Booking Readiness — the pickup-prep board's brain. For every UPCOMING booking (type-R reservation)
 * inside the look-ahead horizon it answers two questions the rental desk needs before the customer
 * arrives: "how soon is pickup (in working days, skipping holidays)?" and "is the car actually ready?".
 *
 * Readiness reuses the app's single authority — ContractEligibilityService::evaluate() — so the board
 * can never disagree with the store()-time rental gate. On top of that checklist it adds ONE
 * booking-specific condition the eligibility guard doesn't cover: a valid PRE-RENTAL INSPECTION.
 * That condition is deliberately "smart" — a car inspected a few days ago is still Satisfied, so the
 * team is never asked to re-test a car that was just tested (the user's key requirement).
 *
 * All four tunables (horizon, alert lead, inspection validity, excluded holidays) are runtime settings
 * (AppSetting), editable from the Readiness Settings panel — no redeploy to change the trigger.
 */
class BookingReadinessService
{
    // Setting keys + their code-side defaults. Defaults live here (not in a seeded row) so the feature
    // works on a fresh install and a missing key simply falls back.
    public const KEY_HORIZON            = 'booking_readiness.horizon_days';
    public const KEY_ALERT_LEAD         = 'booking_readiness.alert_lead_days';
    public const KEY_INSPECTION_VALID   = 'booking_readiness.inspection_validity_days';
    public const KEY_EXCLUDED_DATES     = 'booking_readiness.excluded_dates';

    public const DEFAULT_HORIZON          = 7;
    public const DEFAULT_ALERT_LEAD       = 2;
    public const DEFAULT_INSPECTION_VALID = 7;

    /** Verdicts, worst → best. A single hard block = blocked; any warn / pending test = needs attention. */
    public const VERDICT_BLOCKED  = 'blocked';
    public const VERDICT_ATTENTION = 'needs_attention';
    public const VERDICT_READY    = 'ready';

    public function __construct(
        private ContractEligibilityService $eligibility,
    ) {}

    // ── Settings ──────────────────────────────────────────────────────────────

    /** The four runtime tunables, each falling back to its code default. */
    public function settings(): array
    {
        return [
            'horizon_days'             => (int) AppSetting::get(self::KEY_HORIZON, self::DEFAULT_HORIZON),
            'alert_lead_days'          => (int) AppSetting::get(self::KEY_ALERT_LEAD, self::DEFAULT_ALERT_LEAD),
            'inspection_validity_days' => (int) AppSetting::get(self::KEY_INSPECTION_VALID, self::DEFAULT_INSPECTION_VALID),
            'excluded_dates'           => $this->excludedDates(),
        ];
    }

    /** Persist a validated settings payload (see BookingReadinessController::updateSettings). */
    public function saveSettings(array $data): array
    {
        if (array_key_exists('horizon_days', $data)) {
            AppSetting::put(self::KEY_HORIZON, (int) $data['horizon_days']);
        }
        if (array_key_exists('alert_lead_days', $data)) {
            AppSetting::put(self::KEY_ALERT_LEAD, (int) $data['alert_lead_days']);
        }
        if (array_key_exists('inspection_validity_days', $data)) {
            AppSetting::put(self::KEY_INSPECTION_VALID, (int) $data['inspection_validity_days']);
        }
        if (array_key_exists('excluded_dates', $data)) {
            // Normalise to a sorted, de-duplicated list of Y-m-d strings.
            $dates = collect($data['excluded_dates'] ?? [])
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->unique()->sort()->values()->all();
            AppSetting::put(self::KEY_EXCLUDED_DATES, $dates);
        }

        return $this->settings();
    }

    /** Excluded holiday dates as a clean Y-m-d list. */
    public function excludedDates(): array
    {
        $raw = AppSetting::get(self::KEY_EXCLUDED_DATES, []);

        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($d) => (string) $d)
            ->values()->all();
    }

    // ── The board ─────────────────────────────────────────────────────────────

    /**
     * Every upcoming booking inside the look-ahead horizon, each with its readiness checklist,
     * working-days-to-pickup and rolled-up verdict. Ordered soonest-first.
     */
    public function upcomingBookings(): Collection
    {
        $settings = $this->settings();
        $today    = Carbon::today();
        $horizon  = $today->copy()->addDays($settings['horizon_days']);

        $bookings = Contract::query()
            ->upcomingReservation()                       // type-R, reserved window not yet ended
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '>=', $today)
            ->whereDate('out_date', '<=', $horizon)
            ->with(['vehicle:id,plate_no,make,model', 'customer:id,name_en,name_ar'])
            ->orderBy('out_date')
            ->orderBy('out_time')
            ->get();

        return $bookings
            ->filter(fn (Contract $c) => $c->vehicle !== null)
            ->map(fn (Contract $c) => $this->assembleBooking($c, $today, $settings))
            ->values();
    }

    /** Headline tallies for the board's KPI row. */
    public function summary(Collection $bookings): array
    {
        return [
            'upcoming'        => $bookings->count(),
            'urgent'          => $bookings->where('urgent', true)->count(),
            'needs_attention' => $bookings->where('verdict', self::VERDICT_ATTENTION)->count(),
            'blocked'         => $bookings->where('verdict', self::VERDICT_BLOCKED)->count(),
        ];
    }

    /** Build one board row from a booking contract. */
    private function assembleBooking(Contract $c, Carbon $today, array $settings): array
    {
        $vehicle = $c->vehicle;
        $pickup  = Carbon::parse($c->out_date);

        $calendarDaysLeft = (int) $today->diffInDays($pickup, false);              // 0 today · 1 · 2 …
        $workingDaysLeft  = self::workingDaysLeft($today, $pickup, $settings['excluded_dates']);
        $urgent           = $workingDaysLeft <= $settings['alert_lead_days'];

        // The shared rental checklist (status / maintenance / condition / damage / cleaning / documents).
        $eligibility = $this->eligibility->evaluate($vehicle);
        $checks = collect($eligibility['checks'])
            ->map(fn (array $chk) => $this->presentCheck($chk, $vehicle))
            ->all();

        // The booking-specific "smart" pre-rental inspection condition.
        $inspection = $this->inspectionCheck($c, $settings['inspection_validity_days']);
        $checks[] = $inspection;

        $verdict = $this->rollupVerdict($checks);

        $customer = $c->customer;
        $customerName = $customer ? (trim((string) $customer->name_en) ?: trim((string) $customer->name_ar)) : null;
        $carLabel = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ($vehicle->plate_no ?: 'Vehicle');

        return [
            'id'                => $c->id,
            'contract_no'       => $c->contract_no,
            'out_date'          => $pickup->toDateString(),
            'out_time'          => $c->out_time,
            'days_left'         => max(0, $calendarDaysLeft),
            'working_days_left' => $workingDaysLeft,
            'urgent'            => $urgent,
            'verdict'           => $verdict,
            'vehicle'           => [
                'id'       => $vehicle->id,
                'plate_no' => $vehicle->plate_no,
                'make'     => $vehicle->make,
                'model'    => $vehicle->model,
                'label'    => $carLabel,
            ],
            'customer'          => $customerName ?: ($customer?->customer_no ? 'Customer #' . $customer->customer_no : 'Customer'),
            'checks'            => $checks,
            'blockers_count'    => collect($checks)->where('status', ContractEligibilityService::BLOCK)->count(),
            'warnings_count'    => collect($checks)->whereIn('status', [ContractEligibilityService::WARN, ContractEligibilityService::MANAGER_OVERRIDE])->count(),
            'inspection_ok'     => $inspection['status'] === ContractEligibilityService::PASS,
        ];
    }

    /**
     * The pre-rental inspection ("test") condition — the piece the eligibility guard doesn't cover.
     * Satisfied when EITHER an inspection is already on file for THIS booking's contract (the exact
     * signal ReadinessDashboardService::handoverQueue uses: contract_id + phase='pre'), OR the same
     * vehicle carries a recent phase='pre' inspection within the configurable validity window — so a
     * car tested a few days ago is not asked to be re-tested. Otherwise it's a (non-blocking) Pending.
     */
    private function inspectionCheck(Contract $c, int $validityDays): array
    {
        $onContract = InspectionRecord::where('contract_id', $c->id)
            ->where('phase', 'pre')
            ->exists();

        if ($onContract) {
            return $this->check('pre_rental_inspection', 'Pre-rental inspection',
                ContractEligibilityService::PASS, 'Pre-rental inspection on file for this booking', $c->vehicle_id, $this->inspectionFixUrl($c));
        }

        $recent = InspectionRecord::where('vehicle_id', $c->vehicle_id)
            ->where('phase', 'pre')
            ->whereNotNull('captured_at')
            ->where('captured_at', '>=', Carbon::now()->subDays(max(0, $validityDays)))
            ->latest('captured_at')
            ->first();

        if ($recent) {
            $ago = (int) Carbon::parse($recent->captured_at)->diffInDays(Carbon::now());
            return $this->check('pre_rental_inspection', 'Pre-rental inspection',
                ContractEligibilityService::PASS,
                'Inspected ' . ($ago === 0 ? 'today' : $ago . ' day' . ($ago === 1 ? '' : 's') . ' ago') . ' — still valid',
                $c->vehicle_id, $this->inspectionFixUrl($c));
        }

        return $this->check('pre_rental_inspection', 'Pre-rental inspection',
            ContractEligibilityService::WARN, 'Pending — a pre-rental inspection is required',
            $c->vehicle_id, $this->inspectionFixUrl($c));
    }

    /**
     * "Fix" deep-link for the pre-rental inspection condition — sends the team straight into the
     * Inspection prototype with this booking's contract + vehicle already carried as query params
     * (vehicle_id/contract_id/contract_no/plate/vehicle label), so the page opens pre-filled instead
     * of the placeholder "Not linked (prototype)" state. See InspectionPrototype.js.
     */
    private function inspectionFixUrl(Contract $c): string
    {
        $vehicle = $c->vehicle;
        $label = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ($vehicle->plate_no ?: 'Vehicle');

        $params = http_build_query([
            'vehicle_id'  => $c->vehicle_id,
            'contract_id' => $c->id,
            'contract_no' => $c->contract_no,
            'plate'       => $vehicle->plate_no,
            'vehicle'     => $label,
            'phase'       => 'pre',
        ]);

        return '/inspection-prototype?' . $params;
    }

    /** Worst-status-wins rollup across every check. */
    private function rollupVerdict(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');

        if ($statuses->contains(ContractEligibilityService::BLOCK)) {
            return self::VERDICT_BLOCKED;
        }
        if ($statuses->contains(ContractEligibilityService::WARN)
            || $statuses->contains(ContractEligibilityService::MANAGER_OVERRIDE)) {
            return self::VERDICT_ATTENTION;
        }

        return self::VERDICT_READY;
    }

    /** Attach a fix deep-link to an eligibility check (its `key` decides where "fix it" goes). */
    private function presentCheck(array $chk, \App\Models\Vehicle $vehicle): array
    {
        return $this->check($chk['key'], $chk['label'], $chk['status'], $chk['detail'], $vehicle->id, $this->fixUrl($chk['key'], $vehicle));
    }

    private function check(string $key, string $label, string $status, string $detail, int $vehicleId, ?string $url = null): array
    {
        return [
            'key'    => $key,
            'label'  => $label,
            'status' => $status,
            'detail' => $detail,
            'url'    => $url ?? '/vehicles/' . $vehicleId,
        ];
    }

    /** Where "fix this" takes the user for each condition. Most route to the vehicle profile hub. */
    private function fixUrl(string $key, \App\Models\Vehicle $vehicle): string
    {
        return match ($key) {
            // The Cleaning point opens the dedicated before/after cleaning capture page, pre-filled with
            // this car (so the crew can shoot the dirty car, clean it, shoot it again, then mark it clean).
            'cleaning' => '/cleaning?' . http_build_query([
                'vehicle_id' => $vehicle->id,
                'plate'      => $vehicle->plate_no,
                'vehicle'    => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ($vehicle->plate_no ?: 'Vehicle'),
            ]),
            'inspection' => '/inspections/schedules',
            default => '/vehicles/' . $vehicle->id,
        };
    }

    /**
     * Working days between today and pickup, honouring the excluded-holiday list: a holiday falling in
     * the window is NOT a day the team can prep, so it's subtracted — meaning a booking still gets its
     * full N working-days of warning (the urgency threshold trips earlier when a holiday intervenes).
     */
    public static function workingDaysLeft(Carbon $today, Carbon $pickup, array $excluded): int
    {
        $calendar = (int) $today->diffInDays($pickup, false);
        if ($calendar <= 0) {
            return 0;
        }

        $excludedSet = array_flip($excluded);
        $lost = 0;
        // Count excluded days strictly after today, up to and including pickup.
        for ($d = $today->copy()->addDay(); $d->lte($pickup); $d->addDay()) {
            if (isset($excludedSet[$d->toDateString()])) {
                $lost++;
            }
        }

        return max(0, $calendar - $lost);
    }
}
