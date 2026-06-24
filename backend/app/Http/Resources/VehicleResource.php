<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "code" => $this->code,
            "vin" => $this->vin,
            "engine_no" => $this->engine_no,
            "driver_no" => $this->driver_no,
            "plate_no" => $this->plate_no,
            "make" => $this->make,
            "model" => $this->model,
            "year" => $this->year,
            "color" => $this->color,
            "category" => $this->category,
            "status" => $this->status,
            "status_no" => $this->status_no,
            "for_sale" => (bool) $this->for_sale,
            "operational_status" => $this->operational_status,
            "operational_status_label" => \App\Models\Vehicle::OPERATIONAL_LABELS[$this->operational_status] ?? $this->operational_status,
            // true when the car has a currently-open maintenance contract (in the garage now)
            "under_maintenance" => (bool) ($this->open_maintenance_count ?? 0),
            // true when the car has a currently-open rental contract (out on rent now)
            "rented" => (bool) ($this->open_rental_count ?? 0),
            // true when the car has a currently-open booking/reservation (type R)
            "reserved" => (bool) ($this->open_booking_count ?? 0),
            // available = an in-service ("active") car with NO open movement AND no
            // active/upcoming reservation (truly free to rent out or send for maintenance).
            // A sold / for-sale / reserved car is never "available". List-only.
            "available" => $this->when(
                $this->open_contract_count !== null,
                fn () => $this->status === 'ready'
                    && ! (bool) $this->open_contract_count
                    && ! (bool) ($this->open_booking_count ?? 0)
            ),
            "odometer" => $this->odometer,
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
