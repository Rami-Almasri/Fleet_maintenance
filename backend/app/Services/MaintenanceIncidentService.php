<?php

namespace App\Services;

use App\Models\Maintenance;

/**
 * The damage & accident log, presented straight from the maintenance records.
 *
 * The maintenance log is the single source of truth: each row already records the
 * vehicle, what happened (accident / body damage / scratch), the damage detail,
 * severity, location, garage and — crucially — who was at fault (`liable_party`).
 * We DON'T infer anything: no date-matching to rentals, no guessed renter, no
 * confidence scores. We read the facts and colour them.
 *
 * Fault colour (exactly the red/green the owner asked for):
 *   renter      (RED)   — liable_party Customer/Personal, or an uninsured accident.
 *   third_party (GREEN) — an insured accident (the other driver / insurer).
 *   unspecified (GREY)  — the record doesn't attribute fault; shown as recorded.
 */
class MaintenanceIncidentService
{
    /** liable_party values that put the fault on the renter. */
    private const RENTER = ['Customer', 'Personal'];

    /**
     * Every damage / accident record, colour-coded by the fault stated in the data.
     *
     * @return array{incidents: array<int,array<string,mixed>>, summary: array<string,mixed>}
     */
    public function log(): array
    {
        // A row is a "damage / accident" event when it carries any damage signal:
        // renter liability, an accident, body-damage / scratch / lip work, or a
        // recorded severity / damage location. Routine service (oil, etc.) is excluded.
        $rows = Maintenance::query()
            ->whereNotNull('vehicle_id')
            ->where(function ($w) {
                $w->whereIn('liable_party', self::RENTER)
                  ->orWhere('maintenance_type', 'like', '%ccident%')
                  ->orWhere('maintenance_type', 'like', '%Body Damage%')
                  ->orWhere('maintenance_type', 'like', '%scratch%')
                  ->orWhere('maintenance_type', 'like', '%Front Lip%')
                  ->orWhereNotNull('severity')
                  ->orWhereNotNull('damage_location');
            })
            ->with(['vehicle:id,plate_no,make,model', 'reason:id,reason_en,reason_ar,level'])
            ->orderByRaw('out_date IS NULL, out_date DESC')
            ->orderByDesc('id')
            ->get();

        $incidents = $rows->map(fn (Maintenance $m) => $this->present($m))->all();

        $summary = [
            'incidents'   => count($incidents),
            'renter'      => $this->countBy($incidents, 'fault', 'renter'),
            'third_party' => $this->countBy($incidents, 'fault', 'third_party'),
            'unspecified' => $this->countBy($incidents, 'fault', 'unspecified'),
            'accidents'   => count(array_filter($incidents, fn ($i) => $i['is_accident'])),
            'vehicles'    => count(array_unique(array_filter(array_column($incidents, 'vehicle_id')))),
            'categorized' => count(array_filter($incidents, fn ($i) => $i['reason'] !== null)),
            'by_level'    => [
                'critical' => $this->countBy($incidents, 'level', 'critical'),
                'minor'    => $this->countBy($incidents, 'level', 'minor'),
                'routine'  => $this->countBy($incidents, 'level', 'routine'),
                'special'  => $this->countBy($incidents, 'level', 'special'),
            ],
        ];

        return ['incidents' => $incidents, 'summary' => $summary];
    }

    /** Shape one maintenance record for the UI, exactly as recorded + a fault colour. */
    private function present(Maintenance $m): array
    {
        $type = mb_strtolower((string) $m->maintenance_type);
        $isAccident = str_contains($type, 'ccident');

        $insurance = null;
        if ($isAccident) {
            $insurance = str_contains($type, 'without insurance') ? 'without'
                : (str_contains($type, 'with insurance') ? 'with' : null);
        }

        // Fault straight from the record — no inference.
        if (in_array($m->liable_party, self::RENTER, true)) {
            $fault = 'renter';
        } elseif ($isAccident && $insurance === 'with') {
            $fault = 'third_party';
        } elseif ($isAccident && $insurance === 'without') {
            $fault = 'renter';
        } else {
            $fault = 'unspecified';
        }

        return [
            'maint_id'        => $m->id,
            'vehicle_id'      => $m->vehicle_id,
            'plate'           => $m->vehicle?->plate_no ?: $m->plate,
            'car'             => $m->vehicle ? trim($m->vehicle->make . ' ' . $m->vehicle->model) : $m->car_label,
            'date'            => optional($m->out_date)->toDateString(),
            'type'            => $m->maintenance_type,
            'service_main'    => $m->service_main,
            'service_sup'     => $m->service_sup,
            // Cross-referenced reason category (from MAIN, refined by exact SUP) — no guessing.
            'reason'          => $m->reason?->reason_en,
            'reason_ar'       => $m->reason?->reason_ar,
            'level'           => $m->reason?->level,   // critical | minor | routine | special
            'severity'        => $m->severity,
            'damage_location' => $m->damage_location,
            'driver'          => $m->driver,            // free text, as recorded (not a customer record)
            'liable_party'    => $m->liable_party,       // raw, so the data speaks for itself
            'is_accident'     => $isAccident,
            'insurance'       => $insurance,             // with | without | null
            'fault'           => $fault,                 // renter | third_party | unspecified
            'garage'          => $m->garage,
            'cost'            => (float) $m->cost,
            'notes'           => $m->maintenance_notes ?: $m->cost_notes,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function countBy(array $rows, string $key, string $value): int
    {
        return count(array_filter($rows, fn ($r) => $r[$key] === $value));
    }
}
