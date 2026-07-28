<?php

namespace Tests\Feature;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Services\EventClassificationService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Locks the resolver rule "a CLEAR fault symptom beats ticket context" — the shadow-validation regression
 * where a "Brake Failure" found on a Routine/Periodic visit was wrongly classified `service`.
 * Boots the app for config()/catalog maps; the assertions hold regardless of seeded catalog content.
 */
class EventClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The resolver reads the two catalogs for an exact-name match (which these fault symptoms don't
        // hit) — give it empty tables so the query runs on the :memory: test DB without full migrations.
        foreach (['service_catalog', 'fault_catalog'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function ($t) {
                    $t->id();
                    $t->string('slug')->nullable();
                    $t->string('name')->nullable();
                });
            }
        }
    }

    private function classify(string $symptom, ?string $categoryKey, bool $routineTicket): string
    {
        $ticket = new Maintenance();
        if ($routineTicket) {
            $ticket->visit_context   = Maintenance::CONTEXT_ROUTINE;
            $ticket->maintenance_type = Maintenance::TYPE_ROUTINE;
            $ticket->trigger_reason  = Maintenance::TRIGGER_PERIODIC;
        }
        $task = new MaintenanceTask();
        $task->symptom = $symptom;
        $task->category_key = $categoryKey;
        $task->setRelation('maintenance', $ticket);

        return app(EventClassificationService::class)->resolveLegacyKind($task)['kind'];
    }

    public function test_clear_fault_symptom_beats_routine_ticket_context(): void
    {
        // The exact regression: an explicit failure found during a routine visit is still a FAULT.
        $this->assertSame('fault', $this->classify('Brake Failure', 'brakes', true));
        $this->assertSame('fault', $this->classify('Engine noise', 'engine', true));
        $this->assertSame('fault', $this->classify('Oil leak', 'fluids', true));
    }

    public function test_genuine_routine_service_stays_service_on_a_routine_ticket(): void
    {
        // Routine allow-list (config) — no fault evidence, so still a planned service.
        $this->assertSame('service', $this->classify('Oil Change', null, true));
        // No fault word + routine ticket context → service (unchanged behaviour).
        $this->assertSame('service', $this->classify('Brake Pads', null, true));
    }
}
