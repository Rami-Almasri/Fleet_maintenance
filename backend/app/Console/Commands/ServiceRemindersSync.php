<?php

namespace App\Console\Commands;

use App\Models\ServiceReminder;
use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Auto-derive the per-car "oil_change" service reminder from the Oil Change sheet data
 * already on `vehicles` (last_service_odometer + service_interval_km). Idempotent and
 * non-destructive:
 *   - creates/refreshes the reminder only while source = 'auto'
 *   - a reminder a human has edited (source = 'manual') is left untouched (manual wins)
 * The anchors mirror Vehicle::serviceStatus(), so the reminder's status always agrees
 * with the car's own service status. Manual reminders for other service types (filters,
 * brakes, …) are created via the UI, not here.
 *
 * It ALSO seeds tire reminders (rotation + change) for every in-fleet car, so the team never
 * has to create those by hand. Unlike oil-change (whose anchor is authoritative sheet data),
 * tires have no historical service point to anchor to, so they are seeded ONCE — anchored to
 * the car's odometer/date at first seed — and never re-anchored, letting them count down and
 * actually come due. Intervals live in self::TIRE_REMINDERS (tune them there).
 */
class ServiceRemindersSync extends Command
{
    protected $signature = 'service:sync-reminders {--dry-run : Report what would change without writing}';

    protected $description = 'Auto-derive oil-change reminders from sheet data + seed tire rotation/change reminders fleet-wide';

    /** Fleet-wide default cadence for auto-seeded tire reminders (create-once). Adjust freely. */
    private const TIRE_REMINDERS = [
        'tire_rotation' => ['name' => 'Tire Rotation', 'interval_km' => 10000, 'interval_days' => null],
        'tire_change'   => ['name' => 'Tire Change',   'interval_km' => 50000, 'interval_days' => 1825], // ~5-year age cap
    ];

    /** Lifecycle statuses whose cars have left the fleet — no point seeding tire reminders. */
    private const LEFT_FLEET = ['sold', 'disposed'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $created = $updated = $skippedManual = 0;

        $vehicles = Vehicle::query()
            ->whereNotNull('service_interval_km')
            ->whereNotNull('last_service_odometer')
            ->get();

        foreach ($vehicles as $vehicle) {
            $reminder = ServiceReminder::firstOrNew([
                'vehicle_id'   => $vehicle->id,
                'service_type' => 'oil_change',
            ]);

            // Never overwrite a human's edit.
            if ($reminder->exists && $reminder->source === 'manual') {
                $skippedManual++;
                continue;
            }

            $isNew = ! $reminder->exists;

            $reminder->fill([
                'name'                  => 'Oil Change',
                'interval_km'           => $vehicle->service_interval_km,
                'last_service_odometer' => $vehicle->last_service_odometer,
                'source'                => 'auto',
                'active'                => true,
            ]);
            $reminder->recomputeNextDue();

            if ($dry) {
                $this->line(sprintf(
                    '  [%s] %s — interval %skm, last @ %s',
                    $isNew ? 'new' : 'refresh',
                    $vehicle->plate_no ?: $vehicle->code,
                    $vehicle->service_interval_km,
                    $vehicle->last_service_odometer
                ));
            } else {
                $reminder->save();
            }

            $isNew ? $created++ : $updated++;
        }

        $this->info(sprintf(
            'Oil-change reminders %s — %d created, %d refreshed, %d manual left untouched (of %d cars with sheet data).',
            $dry ? 'DRY-RUN' : 'synced',
            $created,
            $updated,
            $skippedManual,
            $vehicles->count()
        ));

        [$tireCreated, $tireExisting] = $this->seedTireReminders($dry);
        $this->info(sprintf(
            'Tire reminders %s — %d created, %d already present (rotation + change across in-fleet cars).',
            $dry ? 'DRY-RUN' : 'seeded',
            $tireCreated,
            $tireExisting,
        ));

        return self::SUCCESS;
    }

    /**
     * Seed rotation + change reminders for every in-fleet car that doesn't already have them.
     * Create-once: an existing reminder (auto OR manual) is left exactly as-is, so the anchor
     * never resets and the reminder can actually reach its due point.
     *
     * @return array{0:int,1:int} [created, alreadyPresent]
     */
    private function seedTireReminders(bool $dry): array
    {
        $created = $existing = 0;

        $vehicles = Vehicle::query()
            ->whereNotIn('status', self::LEFT_FLEET)
            ->whereNotNull('odometer')
            ->get();

        foreach ($vehicles as $vehicle) {
            foreach (self::TIRE_REMINDERS as $type => $cfg) {
                $reminder = ServiceReminder::firstOrNew([
                    'vehicle_id'   => $vehicle->id,
                    'service_type' => $type,
                ]);

                if ($reminder->exists) {
                    $existing++;
                    continue; // create-once — never re-anchor
                }

                // Anchor a fresh reminder to the car's current odometer / today, so it counts
                // down from now (there is no historical tire-service point to anchor to).
                $reminder->fill([
                    'name'                  => $cfg['name'],
                    'interval_km'           => $cfg['interval_km'],
                    'interval_days'         => $cfg['interval_days'],
                    'last_service_odometer' => $vehicle->odometer,
                    'last_service_at'       => now()->toDateString(),
                    'source'                => 'auto',
                    'active'                => true,
                ]);
                $reminder->recomputeNextDue();

                if (! $dry) {
                    $reminder->save();
                }
                $created++;
            }
        }

        return [$created, $existing];
    }
}
