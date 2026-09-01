<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /** Memoized code→letter map so a collection render is one query, not one per row. */
    private static ?array $letterMap = null;

    /**
     * Render a plate as "<letter> <digits>" via the dictionary; digits alone if no code/letter.
     *
     * PUBLIC because it is the canonical spelling of a plate and services that put a car's name in a
     * sentence (notifications, board rows) must produce the same string this resource does. The
     * alternative was a fourth copy of the letter lookup — the app already carries two.
     */
    public static function plateDisplay($code, $digits): string
    {
        $digits = (string) $digits;
        if ($code === null || $code === '') {
            return $digits;
        }
        if (self::$letterMap === null) {
            self::$letterMap = \App\Models\PlateCode::letterMap();
        }
        $letter = self::$letterMap[$code] ?? self::$letterMap[(int) $code] ?? null;

        return $letter ? trim($letter . ' ' . $digits) : $digits;
    }

    /** contract_type code → the human name used across the app (mirrors Badge.js CONTRACT_TYPE). */
    private const CONTRACT_TYPES = ['C' => 'Rental', 'U' => 'Maintenance', 'R' => 'Booking'];

    /** Print Rental before Maintenance before Booking, whatever order the rows came back in. */
    private const TYPE_ORDER = ['C' => 0, 'U' => 1, 'R' => 2];

    /**
     * Everything currently ON this car, in the order a reader wants it — one entry per live piece of
     * paperwork, plus a NOTE where paperwork deliberately does not exist. Two kinds:
     *
     *   kind 'contract' — a real contract (Rental / Maintenance / Booking) synced from OfficeManager.
     *                     Carries its number, id and dates, so the UI can link straight to it.
     *   kind 'note'     — our own maintenance workflow raised this repair. NO OM contract was ever
     *                     opened for it, so instead of a number we state that in words. Without this
     *                     the row would look like a contract whose number simply failed to load, and
     *                     someone would go hunting in OM for a document that never existed.
     *
     * A car can legitimately have several at once (out on rent AND in the garage), hence a list.
     * Returns [] unless the list eager-loaded the relations, so single-vehicle reads never lazy-load.
     */
    private function contractLines(): array
    {
        if (! $this->relationLoaded('openContracts') && ! $this->relationLoaded('openMaintenanceTicket')) {
            return [];
        }

        $contracts = $this->relationLoaded('openContracts') ? $this->openContracts : collect();
        $ticket    = $this->relationLoaded('openMaintenanceTicket') ? $this->openMaintenanceTicket : null;

        // Every open contract is shown, whatever the car's status says — if a contract exists, the
        // reader wants to see it AND what type it is. A car reading "Office Use" while still holding
        // an open rental is exactly the kind of thing worth surfacing, not hiding.
        //
        // The only thinning is one line per TYPE, newest first: two simultaneously-open rentals on
        // one car is a data fault, not two rentals to read, and the latest is the live one.
        $contracts = $contracts->sortByDesc('id')->unique('contract_type');

        $lines = $contracts->map(fn ($c) => [
            'kind'        => 'contract',
            'type'        => $c->contract_type,
            'label'       => self::CONTRACT_TYPES[$c->contract_type] ?? $c->contract_type,
            'source'      => 'om',
            'contract_id' => $c->id,
            'contract_no' => $c->contract_no,
            'since'       => optional($c->out_date)->toDateString(),
            'due'         => optional(optional($c->maintenance)->effectiveExpectedCompletion())->toDateString(),
            'note'        => null,
        ])->sortBy(fn ($l) => self::TYPE_ORDER[$l['type']] ?? 9)->values()->all();

        // The system-raised repair. Only a note when NO maintenance contract covers it — if an OM
        // type-U contract is already listed, that contract is the document of record and the ticket
        // only contributes its workflow stage (see maintenance_state).
        $hasMaintenanceContract = $contracts->contains(fn ($c) => $c->contract_type === 'U');
        if ($ticket && ! $hasMaintenanceContract) {
            array_splice($lines, min(1, count($lines)), 0, [[
                'kind'        => 'note',
                'type'        => 'U',
                'label'       => 'Maintenance',
                'source'      => 'system',
                'contract_id' => null,
                'contract_no' => null,
                'since'       => optional($ticket->created_at)->toDateString(),
                'due'         => optional($ticket->effectiveExpectedCompletion())->toDateString(),
                'note'        => 'Created by System — no OM contract',
            ]]);
        }

        return $lines;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = $this->contractLines();
        $maintLine = collect($lines)->firstWhere('type', 'U');

        return [
            "id" => $this->id,
            "code" => $this->code,
            "vin" => $this->vin,
            "engine_no" => $this->engine_no,
            "driver_no" => $this->driver_no,
            "plate_no" => $this->plate_no,
            // Plate-history feature: the canonical plate key (digits, leading zeros stripped) lets
            // the client group vehicles that share a reused plate. is_current_plate_holder marks the
            // plate's live holder (only present when the plate_assignment relation was eager-loaded,
            // e.g. the fleet list — never triggers a lazy query on single-vehicle reads).
            "plate_key" => $this->plate_key,
            // The plate CODE (OM PlateColorNo) + a ready-made display that maps it to the plate
            // letter via the dictionary — "P 76722", never the raw code. Same digits under a
            // different code are a different plate.
            "plate_code" => $this->plate_code,
            "plate_display" => self::plateDisplay($this->plate_code, $this->plate_no),
            "is_current_plate_holder" => $this->when(
                $this->relationLoaded('plateAssignment'),
                fn () => (bool) optional($this->plateAssignment)->is_current
            ),
            "make" => $this->make,
            "model" => $this->model,
            "year" => $this->year,
            "color" => $this->color,
            "category" => $this->category,
            "sheet_category" => $this->sheet_category,
            "status" => $this->status,
            // What the fleet register itself calls this car — "Active" / "For sale" / "Office" /
            // "Under process" / "Insurance claim" / "Sold" — verbatim, NOT one of our status slugs.
            // `status` above is our operational answer; this is the register's claim. They can
            // legitimately disagree (a car the register calls "Office" can be out on a live rental),
            // and showing both is the point — the disagreement is the thing worth seeing.
            "sheet_status" => $this->sheet_status,
            "status_no" => $this->status_no,
            "for_sale" => (bool) $this->for_sale,
            "operational_status" => $this->operational_status,
            "operational_status_label" => \App\Models\Vehicle::OPERATIONAL_LABELS[$this->operational_status] ?? $this->operational_status,
            // While in_transit, where the car is being driven (set by a Logistics Dispatch).
            "transit_destination" => $this->transit_destination,
            // Visual Condition Grade (Abu Marouf): green Perfect · orange Cosmetic (rentable,
            // warn the customer) · yellow Maintenance-needed (NOT rentable, route to garage)
            // · red Critical/grounded (blocked from renting). Yellow & red are both hidden
            // from Available; only green & orange stay rentable.
            "condition_grade" => $this->condition_grade ?: 'green',
            "condition_grade_label" => \App\Models\Vehicle::CONDITION_LABELS[$this->condition_grade] ?? 'Perfect',
            "condition_note" => $this->condition_note,
            "condition_graded_at" => $this->condition_graded_at,
            "condition_graded_by" => $this->condition_graded_by,
            // Deferred Maintenance: the car was pulled out of the workshop early for a customer and
            // still owes the garage a visit. A standing 🛠️↩️ flag until it's checked back in / dismissed.
            "is_deferred_maintenance" => (bool) $this->is_deferred_maintenance,
            "deferred_maintenance_reason" => $this->deferred_maintenance_reason,
            "deferred_maintenance_flagged_at" => $this->deferred_maintenance_flagged_at,
            "deferred_maintenance_flagged_by" => $this->deferred_maintenance_flagged_by,
            // true when the car has a currently-open maintenance contract (in the garage now)
            "under_maintenance" => (bool) ($this->open_maintenance_count ?? 0),
            // Every live contract on the car (Rental / Maintenance / Booking) plus a NOTE wherever
            // our own workflow raised the repair and no OM contract exists. See contractLines().
            // List-only: an empty array when the relations weren't eager-loaded.
            "contract_lines" => $lines,
            // WHERE the current garage visit came from — 'om' (a real OM contract) or 'system'
            // (our workflow raised it; nothing to look up in OM). Null when the car isn't in a shop.
            "maintenance_source" => $maintLine['source'] ?? null,
            // The live workflow stage ('under_repair', 'awaiting_dispatch', …) so the shared
            // <DualState> chip can name the actual stage instead of a generic "In Workshop".
            "maintenance_state" => $this->relationLoaded('openMaintenanceTicket')
                ? optional($this->openMaintenanceTicket)->workflow_status
                : null,
            // MANDATORY maintenance — an open committed ticket the inspector marked NON-deferrable. The car
            // cannot be rented until the workshop finishes; the rental form blocks the pull-out outright.
            "maintenance_mandatory" => (bool) ($this->mandatory_maintenance_count ?? 0),
            // Repair Location (On-Site): the car has an OPEN on-site (mobile) ticket — a minor job to be
            // done where it's parked. It STAYS available/rentable and merely carries a "Pending
            // Maintenance" tag until it's marked serviced. List-only (present when the count was loaded).
            "pending_on_site_maintenance" => (bool) ($this->open_on_site_count ?? 0),
            // true when the car has a currently-open rental contract (out on rent now)
            "rented" => (bool) ($this->open_rental_count ?? 0),
            // true when the car has a currently-open booking/reservation (type R)
            "reserved" => (bool) ($this->open_booking_count ?? 0),
            // available = an in-service ("active") car with NO open movement AND no
            // active/upcoming reservation (truly free to rent out or send for maintenance).
            // A sold / for-sale / reserved car is never "available". A Red (critical /
            // grounded) or Yellow (maintenance needed) condition grade also drops it from the
            // pool; only Green & Orange stay rentable. List-only.
            "available" => $this->when(
                $this->open_contract_count !== null,
                fn () => $this->status === 'ready'
                    && ! in_array($this->condition_grade, ['red', 'yellow'], true)
                    && ! (bool) $this->open_contract_count
                    && ! (bool) ($this->open_booking_count ?? 0)
            ),
            "odometer" => $this->odometer,
            // Where this mileage came from — the sheet, OM, a handover, a ticket or a hand edit.
            // The highest reading wins between them (Vehicle::advanceOdometer), so the label says
            // which source is currently ahead rather than which source "owns" the number.
            "odometer_source" => $this->odometer_source,
            "odometer_source_label" => $this->resource->odometerSourceLabel(),
            "odometer_source_at" => optional($this->odometer_source_at)->toIso8601String(),
            "engine_hours" => $this->engine_hours,
            "source" => $this->source,
            // --- specs from the API car card ---
            "keys_number" => $this->keys_number,
            "auto_gear" => $this->auto_gear === null ? null : (bool) $this->auto_gear,
            "cylinders" => $this->cylinders,
            "horse_power" => $this->horse_power,
            "doors" => $this->doors,
            "seats" => $this->seats,
            "passengers" => $this->passengers,
            "wheel_drive" => $this->wheel_drive,
            "location" => $this->location,
            "salik_tag_no" => $this->salik_tag_no,
            // --- standard rental defaults from the API car card ---
            "hour_rent_value" => $this->hour_rent_value,
            "day_rent_value" => $this->day_rent_value,
            "week_rent_value" => $this->week_rent_value,
            "month_rent_value" => $this->month_rent_value,
            "year_rent_value" => $this->year_rent_value,
            "miles_allowed_pd" => $this->miles_allowed_pd,
            "miles_allowed_pm" => $this->miles_allowed_pm,
            "extra_mile_charge" => $this->extra_mile_charge,
            "full_fuel_cost" => $this->full_fuel_cost,
            "purchase_price" => $this->purchase_price,
            "purchase_date" => $this->purchase_date,
            "warranty_end_date" => $this->warranty_end_date,
            "warranty_end_km" => $this->warranty_end_km,
            "service_due_date" => $this->service_due_date,
            "service_due_km" => $this->service_due_km,
            "battery_last_changed" => $this->battery_last_changed,
            // Oil Change sheet baseline/interval + the strict km-based service-due verdict.
            "last_service_odometer" => $this->last_service_odometer,
            "service_interval_km" => $this->service_interval_km,
            "service_status" => $this->serviceStatus(),
            // Global Mileage Baseline: the earliest contract reading the odometer is anchored to.
            "baseline_odometer" => $this->baseline_odometer,
            "baseline_synced_at" => $this->baseline_synced_at,
            "replacement_due_date" => $this->replacement_due_date,
            "notes" => $this->notes,
            "origin" => $this->origin,
        ];
    }
}
