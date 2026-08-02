<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;

/**
 * Every fault is born knowing which part of the car it belongs to.
 *
 * THE BUG THESE CLOSE. `maintenance_tasks.category_key` was NULL on all 108 rows ever written. Both
 * creation paths read it off the caller with `?? null` and nothing upstream supplied it, so the column
 * looked exactly like "these faults genuinely have no category" and nothing errored, logged or failed.
 *
 * It is the middle tier of the repair-history matcher (fault_catalog_id → category_key → symptom), so
 * every lookup fell through to an exact symptom-string match and "Previous Similar Repairs" reported
 * nothing for faults the fleet had repaired hundreds of times.
 *
 * Pinned at the MODEL rather than at either writer, because the rule "remember to set the category" is
 * one both existing writers already forgot, and a third would forget it too.
 */
class MaintenanceTaskCategoryTest extends CrudTestCase
{
    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id' => $this->makeVehicle(),
            'origin'     => 'workflow',
        ]);
    }

    private function task(string $symptom, array $overrides = []): MaintenanceTask
    {
        $ticket = $this->ticket();

        return MaintenanceTask::create(array_merge([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => $symptom,
            'status'         => MaintenanceTask::STATUS_PENDING,
        ], $overrides));
    }

    public function test_a_task_is_born_with_the_category_of_its_symptom(): void
    {
        $this->assertSame('brakes', $this->task('Soft / spongy pedal')->category_key);
        $this->assertSame('engine', $this->task('Overheating')->category_key);
        $this->assertSame('tyres', $this->task('Wheel alignment')->category_key);
    }

    public function test_the_category_is_derived_however_the_task_is_created(): void
    {
        // The point of putting this on the model: neither writer passes a category, and both used to
        // land NULL. A third writer must not have to know the rule either.
        $ticket = $this->ticket();

        $viaCreate = MaintenanceTask::create([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $ticket->vehicle_id,
            'symptom' => 'Overheating', 'status' => MaintenanceTask::STATUS_PENDING,
        ]);

        $viaSave = new MaintenanceTask([
            'maintenance_id' => $ticket->id, 'vehicle_id' => $ticket->vehicle_id,
            'symptom' => 'Overheating', 'status' => MaintenanceTask::STATUS_PENDING,
        ]);
        $viaSave->save();

        $this->assertSame('engine', $viaCreate->category_key);
        $this->assertSame('engine', $viaSave->category_key);
    }

    public function test_a_category_the_caller_supplied_is_never_overwritten(): void
    {
        // Derivation is a fallback, not an opinion. A caller that knows better — a catalog pick, a
        // correction — keeps its answer.
        $task = $this->task('Overheating', ['category_key' => 'cooling']);

        $this->assertSame('cooling', $task->category_key);
    }

    public function test_a_symptom_the_catalog_does_not_know_stays_null(): void
    {
        // NOT fuzzy-matched into a neighbouring category. A task filed under the wrong category routes
        // to the wrong garage; silence is the cheaper error, and a custom issue having no category is
        // the truth rather than a gap.
        $this->assertNull($this->task('weird clunk i heard near the front')->category_key);
        $this->assertNull($this->task('صوت باب امامي')->category_key);
    }

    public function test_the_category_matches_case_insensitively(): void
    {
        // The findings JSON carries whatever the picker sent; historical rows vary in casing.
        $this->assertSame('engine', $this->task('OVERHEATING')->category_key);
    }

    public function test_an_edited_symptom_does_not_silently_recategorise_the_task(): void
    {
        // Derived on CREATE only. Re-deriving on every save would let a typo correction move a fault
        // into another category after it had already been routed to a garage on the strength of the
        // first one — a change nobody asked for and nobody would see.
        $task = $this->task('Overheating');
        $task->symptom = 'Soft / spongy pedal';
        $task->save();

        $this->assertSame('engine', $task->fresh()->category_key);
    }
}
