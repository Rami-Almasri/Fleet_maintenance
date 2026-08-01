<?php

namespace App\Console\Commands;

use App\Models\ComponentEvent;
use App\Models\VehicleComponent;
use Illuminate\Console\Command;

/**
 * components:verify — prove that a vehicle's installed configuration is DERIVABLE FROM EVENTS.
 *
 * The architecture claims vehicle_components is a read model over the append-only component_events
 * log, never a hand-maintained table. A claim nobody checks is just a comment, so this command
 * checks it: it replays every event from scratch, computes what each component's state MUST be, and
 * diffs that against the stored row. A clean run is evidence the two agree; a dirty run names the
 * exact component and the exact disagreement.
 *
 * It also re-asserts the slot invariant (at most ONE active component per vehicle+catalog+position)
 * across the whole fleet, which the write path enforces per-transaction but nothing checks globally.
 *
 * READ-ONLY. It never repairs anything: a divergence means a bug in the write path or a manual DB
 * edit, and silently "fixing" the symptom would destroy the evidence needed to find the cause.
 *
 *   php artisan components:verify              # whole fleet
 *   php artisan components:verify --vehicle=42 # one car
 */
class ComponentsVerifyCommand extends Command
{
    protected $signature = 'components:verify
        {--vehicle= : Restrict the check to one vehicle id}
        {--limit=50 : Maximum divergences to print}';

    protected $description = 'Replay component_events and verify the stored installed configuration matches';

    public function handle(): int
    {
        $vehicleId = $this->option('vehicle') ? (int) $this->option('vehicle') : null;
        $limit     = (int) $this->option('limit');

        $components = VehicleComponent::query()
            ->with('catalog:id,name,slug')
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->get();

        if ($components->isEmpty()) {
            $this->info($vehicleId ? "No components recorded for vehicle #{$vehicleId}." : 'No components recorded yet.');

            return self::SUCCESS;
        }

        $events = ComponentEvent::query()
            ->whereIn('vehicle_component_id', $components->pluck('id'))
            ->orderBy('at')
            ->orderBy('id')
            ->get()
            ->groupBy('vehicle_component_id');

        $divergences = [];

        foreach ($components as $component) {
            $replayed = $this->replay($events->get($component->id));

            // A component with no events at all cannot be derived from the log — it was written
            // straight to the table, which is precisely the drift this command exists to catch.
            if ($replayed === null) {
                $divergences[] = [$component->id, $component->catalog?->name, 'no events', 'stored: ' . $component->status];
                continue;
            }

            if ($replayed['status'] !== $component->status) {
                $divergences[] = [$component->id, $component->catalog?->name, 'status', "events say {$replayed['status']}, row says {$component->status}"];
            }

            if ($replayed['vehicle_id'] !== $component->vehicle_id) {
                $divergences[] = [
                    $component->id, $component->catalog?->name, 'vehicle',
                    'events say ' . ($replayed['vehicle_id'] ?? 'none') . ', row says ' . ($component->vehicle_id ?? 'none'),
                ];
            }
        }

        $slotConflicts = $this->slotConflicts($vehicleId);

        $this->newLine();
        $this->line('  Components checked : ' . $components->count());
        $this->line('  Events replayed    : ' . $events->flatten()->count());
        $this->line('  Divergences        : ' . count($divergences));
        $this->line('  Slot conflicts     : ' . $slotConflicts->count());
        $this->newLine();

        if ($divergences) {
            $this->error('Stored configuration does NOT match the event log:');
            $this->table(['Component', 'Type', 'Field', 'Detail'], array_slice($divergences, 0, $limit));
            if (count($divergences) > $limit) {
                $this->line('  … ' . (count($divergences) - $limit) . ' more (raise --limit to see them).');
            }
        }

        if ($slotConflicts->isNotEmpty()) {
            $this->error('Slots with more than one ACTIVE component (the physical car cannot have two):');
            $this->table(
                ['Vehicle', 'Catalog', 'Position', 'Active rows'],
                $slotConflicts->map(fn ($r) => [$r->vehicle_id, $r->component_catalog_id, $r->position ?? '—', $r->n])->all()
            );
        }

        if ($divergences || $slotConflicts->isNotEmpty()) {
            return self::FAILURE;
        }

        $this->info('  ✓ Every stored component matches its event history, and every slot holds at most one active part.');

        return self::SUCCESS;
    }

    /**
     * Fold one component's event stream into the state it implies. Returns null when there are no
     * events. Mirrors ComponentService's own transitions — deliberately reimplemented here rather
     * than shared, because a checker that reuses the code it is checking proves nothing.
     *
     * @return array{status: string, vehicle_id: ?int}|null
     */
    private function replay(?iterable $events): ?array
    {
        if (! $events || (is_countable($events) && count($events) === 0)) {
            return null;
        }

        $status    = null;
        $vehicleId = null;

        foreach ($events as $event) {
            switch ($event->event) {
                case ComponentEvent::EVENT_PURCHASED:
                case ComponentEvent::EVENT_STORED:
                    $status    = VehicleComponent::STATUS_IN_STOCK;
                    $vehicleId = null;
                    break;

                case ComponentEvent::EVENT_INSTALLED:
                case ComponentEvent::EVENT_TRANSFERRED:
                    $status    = VehicleComponent::STATUS_ACTIVE;
                    $vehicleId = $event->to_vehicle_id ?? $vehicleId;
                    break;

                case ComponentEvent::EVENT_REMOVED:
                    // Where a removal lands depends on its disposition; the terminal/stored event
                    // that follows in the same transaction settles it, so removal alone only takes
                    // the part off the car.
                    $status    = VehicleComponent::STATUS_IN_STOCK;
                    $vehicleId = null;
                    break;

                case ComponentEvent::EVENT_DISPOSED:
                case ComponentEvent::EVENT_RETURNED_SUPPLIER:
                case ComponentEvent::EVENT_SOLD:
                case ComponentEvent::EVENT_WARRANTY_CLAIMED:
                    $status = VehicleComponent::STATUS_RETIRED;
                    // A retired row KEEPS the vehicle it ended on (sold_with_vehicle, scrapped
                    // during a repair) — that is what makes per-vehicle history a single query.
                    $vehicleId = $event->from_vehicle_id ?? $vehicleId;
                    break;
            }
        }

        return $status === null ? null : ['status' => $status, 'vehicle_id' => $vehicleId];
    }

    /** Fleet-wide slot invariant: (vehicle, catalog, position) must hold at most one active part. */
    private function slotConflicts(?int $vehicleId)
    {
        return VehicleComponent::query()
            ->select('vehicle_id', 'component_catalog_id', 'position')
            ->selectRaw('COUNT(*) as n')
            ->where('status', VehicleComponent::STATUS_ACTIVE)
            ->whereNotNull('vehicle_id')
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->groupBy('vehicle_id', 'component_catalog_id', 'position')
            ->havingRaw('COUNT(*) > 1')
            ->get();
    }
}
