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

    /** The full attribute set, so a test can assert on needs_review as well as kind. */
    private function resolve(string $symptom, ?string $categoryKey, bool $routineTicket): array
    {
        $ticket = new Maintenance();
        if ($routineTicket) {
            $ticket->visit_context    = Maintenance::CONTEXT_ROUTINE;
            $ticket->maintenance_type = Maintenance::TYPE_ROUTINE;
            $ticket->trigger_reason   = Maintenance::TRIGGER_PERIODIC;
        }
        $task = new MaintenanceTask();
        $task->symptom = $symptom;
        $task->category_key = $categoryKey;
        $task->setRelation('maintenance', $ticket);

        return app(EventClassificationService::class)->resolveLegacyKind($task);
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

    /**
     * REGRESSION — audit C1. A fault reported in Arabic on a routine-typed ticket was stored as a
     * SERVICE with needs_review = false: no rule had tested the symptom, yet the answer was recorded as
     * confident, so it could never surface in the classification review queue.
     *
     * The Arabic evidence list is the cheap pass being asserted here; the ontology check behind it needs
     * a seeded knowledge platform, which this suite deliberately does not build.
     */
    public function test_arabic_fault_wording_is_not_swallowed_by_a_routine_ticket(): void
    {
        $this->assertSame('fault', $this->classify('صوت باب امامي', null, true), 'front door NOISE is a fault');
        $this->assertSame('fault', $this->classify('تسريب زيت', null, true), 'oil LEAK is a fault');
        $this->assertSame('fault', $this->classify('الموتر يحما', null, true), 'overheating is a fault');
    }

    /**
     * REGRESSION — audit C1, second half. Ticket context describes the VISIT, not the finding, so when it
     * is the only thing that decided the answer the row must be reviewable. Without this, a
     * misclassification is invisible.
     */
    public function test_ticket_context_alone_never_asserts_confidence(): void
    {
        $unknown = $this->resolve('a wording nothing in the vocabulary has ever seen', null, true);

        $this->assertSame('service', $unknown['kind'], 'routine context still decides the fallback');
        $this->assertTrue($unknown['needs_review'], 'but it must be flagged for a human');

        // A routine chip picked BY the inspector is a statement about the finding itself — still confident.
        $picked = $this->resolve('some routine item', 'routine', true);
        $this->assertSame('service', $picked['kind']);
        $this->assertFalse($picked['needs_review']);

        // And a rule that tested the symptom stays confident.
        $this->assertFalse($this->resolve('Brake Failure', 'brakes', true)['needs_review']);
    }
}
