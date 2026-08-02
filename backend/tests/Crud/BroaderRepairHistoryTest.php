<?php

namespace Tests\Crud;

use App\Models\FindingKeyword;
use App\Models\Maintenance;
use App\Services\Knowledge\RepairHistoryQueryService;
use App\Services\Knowledge\SimilarRepairQuery;
use Illuminate\Support\Facades\DB;

/**
 * "Previous Similar Repairs" answered out of the fleet's real repair record.
 *
 * THE BUG THIS CLOSES. The panel searched `maintenance_tasks` — the structured workflow, ~100 rows —
 * and reported "no comparable repairs in fleet history yet" for a brake fault on a fleet with 1,405
 * recorded brake repairs. It was accurate about its own corpus and wrong about the fleet, which is not
 * a distinction the supervisor reading it can be expected to make.
 *
 * These pin the three things that make the broader answer safe rather than merely bigger: it counts
 * VISITS not signature rows, it never inflates a total with its own page size, and it declines to
 * answer at all for a category with no honest counterpart in the signature vocabulary.
 */
class BroaderRepairHistoryTest extends CrudTestCase
{
    private function keyword(string $name, string $category): void
    {
        FindingKeyword::create([
            'category_key' => $category, 'category_label' => ucfirst($category),
            'keyword' => $name, 'risk' => 'moderate', 'is_active' => true,
        ]);
    }

    /** A historical repair carrying one or more signatures. */
    private function repair(int $vehicleId, array $signatures, string $when = '2026-01-10', ?string $work = null): int
    {
        $id = DB::table('maintenances')->insertGetId([
            'vehicle_id' => $vehicleId, 'service_main' => $work,
            'created_at' => $when, 'updated_at' => $when,
        ]);

        foreach ($signatures as $signature) {
            DB::table('maintenance_signatures')->insert([
                'maintenance_id' => $id, 'vehicle_id' => $vehicleId, 'occurred_at' => $when,
                'signature' => $signature, 'source' => 'notes', 'is_exposure' => 0,
                'classifier_version' => 'test', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function ask(string $symptom, ?int $vehicleId = null, ?string $make = null, ?string $model = null): array
    {
        return app(RepairHistoryQueryService::class)->broaderHistory(
            new SimilarRepairQuery(vehicleId: $vehicleId, make: $make, model: $model, symptom: $symptom),
        );
    }

    public function test_a_fault_finds_the_history_of_its_area(): void
    {
        $this->keyword('Soft / spongy pedal', 'brakes');
        $vehicle = $this->makeVehicle();

        $this->repair($vehicle, ['BRAKES']);
        $this->repair($vehicle, ['BRAKES']);
        $this->repair($vehicle, ['ENGINE_MECH']);   // different area — must not be counted

        $result = $this->ask('Soft / spongy pedal', $vehicle);

        $this->assertSame(['BRAKES'], $result['signatures']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('brakes', $result['category']);
    }

    public function test_the_category_is_resolved_from_the_symptom_when_the_task_has_none(): void
    {
        // Every maintenance_tasks row written so far has category_key NULL. Without this fallback the
        // feature would resolve nothing at all on a real ticket.
        $this->keyword('Soft / spongy pedal', 'brakes');
        $vehicle = $this->makeVehicle();
        $this->repair($vehicle, ['BRAKES']);

        $this->assertSame('brakes', $this->ask('Soft / spongy pedal', $vehicle)['category']);
    }

    public function test_one_visit_carrying_two_signatures_counts_once(): void
    {
        // `suspension` maps to SUSPENSION + STEERING and a single job is routinely logged under both.
        // Counting rows instead of visits would report two repairs where the workshop did one.
        $this->keyword('Knocking over bumps', 'suspension');
        $vehicle = $this->makeVehicle();

        $this->repair($vehicle, ['SUSPENSION', 'STEERING']);

        $result = $this->ask('Knocking over bumps', $vehicle);

        $this->assertSame(1, $result['total'], 'a visit is one repair however many signatures it carries');
        $this->assertCount(1, $result['examples'], 'and it appears once in the examples');
    }

    public function test_a_category_with_no_honest_counterpart_declines_to_answer(): void
    {
        // `safety` maps to [] on purpose — the historical sheet never separated airbags and belts from
        // general electrical work, and borrowing ELECTRICAL would answer a safety question with
        // unrelated repairs.
        $this->keyword('Airbag warning', 'safety');
        $vehicle = $this->makeVehicle();
        $this->repair($vehicle, ['ELECTRICAL']);

        $result = $this->ask('Airbag warning', $vehicle);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['signatures']);
    }

    public function test_repairs_are_split_by_how_close_they_are_to_this_car(): void
    {
        $this->keyword('Soft / spongy pedal', 'brakes');
        $mine  = $this->makeVehicle(['make' => 'GMC', 'model' => 'Yukon']);
        $twin  = $this->makeVehicle(['make' => 'GMC', 'model' => 'Yukon']);
        $other = $this->makeVehicle(['make' => 'Nissan', 'model' => 'Patrol']);

        $this->repair($mine, ['BRAKES']);
        $this->repair($twin, ['BRAKES']);
        $this->repair($other, ['BRAKES']);

        $result = $this->ask('Soft / spongy pedal', $mine, 'GMC', 'Yukon');

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['by_tier']['vehicle'] ?? 0);
        $this->assertSame(1, $result['by_tier']['model'] ?? 0);
        $this->assertSame(1, $result['by_tier']['fleet'] ?? 0);

        // This car's own history is the first thing a supervisor should read.
        $this->assertSame('vehicle', $result['examples'][0]['tier_label']);
    }

    public function test_a_retired_ticket_is_not_offered_back_as_precedent(): void
    {
        $this->keyword('Soft / spongy pedal', 'brakes');
        $vehicle = $this->makeVehicle();

        $kept    = $this->repair($vehicle, ['BRAKES']);
        $retired = $this->repair($vehicle, ['BRAKES']);
        Maintenance::whereKey($retired)->delete();   // soft delete

        $result = $this->ask('Soft / spongy pedal', $vehicle);

        $this->assertSame(1, $result['total'], 'a deleted ticket is one the fleet decided did not happen');
        $this->assertSame($kept, $result['examples'][0]['maintenance_id']);
    }

    public function test_the_current_ticket_is_never_its_own_precedent(): void
    {
        $this->keyword('Soft / spongy pedal', 'brakes');
        $vehicle = $this->makeVehicle();
        $self    = $this->repair($vehicle, ['BRAKES']);

        $result = app(RepairHistoryQueryService::class)->broaderHistory(
            new SimilarRepairQuery(vehicleId: $vehicle, make: null, model: null, symptom: 'Soft / spongy pedal', excludeMaintenanceId: $self),
        );

        $this->assertSame(0, $result['total']);
    }
}
