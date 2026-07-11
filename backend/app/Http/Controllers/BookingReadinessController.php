<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\BookingReadinessService;
use Illuminate\Http\Request;

/**
 * Booking Readiness — the pickup-prep board API. `index()` returns the look-ahead list of upcoming
 * bookings (each with its live readiness checklist + working-days-to-pickup + verdict) plus the KPI
 * summary and the current trigger settings, so the page renders in one request. `settings()` /
 * `updateSettings()` are the Readiness Settings panel: read + persist the four runtime tunables.
 *
 * Reads gated to booking_readiness.view, the settings write to booking_readiness.manage (route-level).
 */
class BookingReadinessController extends Controller
{
    public function __construct(
        private BookingReadinessService $readiness,
    ) {}

    /** The board: upcoming bookings + summary + settings, in one payload. */
    public function index()
    {
        $bookings = $this->readiness->upcomingBookings();

        return ResponseHelper::SuccessResponse(
            [
                'bookings' => $bookings->all(),
                'summary'  => $this->readiness->summary($bookings),
                'settings' => $this->readiness->settings(),
            ],
            'Booking readiness retrieved successfully',
            200
        );
    }

    /** Current trigger settings on their own (for the settings panel's initial load). */
    public function settings()
    {
        return ResponseHelper::SuccessResponse($this->readiness->settings(), 'Booking readiness settings retrieved', 200);
    }

    /** Persist the trigger settings. Ints are clamped ≥ 0; excluded dates must be real Y-m-d dates. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'horizon_days'             => ['nullable', 'integer', 'min:1', 'max:60'],
            'alert_lead_days'          => ['nullable', 'integer', 'min:0', 'max:30'],
            'inspection_validity_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'excluded_dates'           => ['nullable', 'array'],
            'excluded_dates.*'         => ['date'],
        ]);

        $settings = $this->readiness->saveSettings($data);

        return ResponseHelper::SuccessResponse($settings, 'Booking readiness settings saved', 200);
    }
}
