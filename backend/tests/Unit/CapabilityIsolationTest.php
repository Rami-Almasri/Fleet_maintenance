<?php

namespace Tests\Unit;

use App\Services\Intelligence\Contracts\IntelligenceCapability;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * THE STANDING PROHIBITIONS, enforced by CI rather than by code review.
 *
 * 1. A capability never talks to another capability.
 * 2. A capability never composes SQL.
 *
 * Both are the kind of rule that holds perfectly for a year and then quietly breaks on a Friday,
 * because in the moment each violation looks like the pragmatic choice. By the time Garage
 * Recommendation depends on Comeback and Comeback on Cost, the Decision Engine is ranking cards
 * that silently contain each other's conclusions: three cards agreeing is then no longer three
 * pieces of evidence, and one wrong inference has been laundered into what looks like corroboration.
 * That is unrecoverable without rewriting every capability, so it is checked automatically.
 *
 * The test reflects over every registered capability rather than a hardcoded list, so a new
 * capability is covered the moment it is registered — including one added by someone who has never
 * read this file.
 */
class CapabilityIsolationTest extends TestCase
{
    /** @return IntelligenceCapability[] */
    private function capabilities(): array
    {
        $capabilities = app(\App\Services\Intelligence\DecisionEngine::class)->capabilities();

        $this->assertNotEmpty($capabilities, 'No capabilities registered — this guard would pass vacuously.');

        return $capabilities;
    }

    public function test_no_capability_depends_on_another_capability(): void
    {
        foreach ($this->capabilities() as $capability) {
            $constructor = (new ReflectionClass($capability))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $this->assertFalse(
                    is_a($type->getName(), IntelligenceCapability::class, true),
                    sprintf(
                        '%s takes %s. Capabilities must never form dependency chains — ask the query layer instead.',
                        $capability::class,
                        $type->getName(),
                    ),
                );
            }
        }
    }

    /**
     * A capability's file must not mention Eloquent, the query builder or raw SQL. It asks
     * [[RepairHistoryQuery]] questions; anything else has made it a data-access layer, and the
     * storage model is frozen from that day on.
     */
    public function test_no_capability_reaches_past_the_query_layer(): void
    {
        $forbidden = ['App\\Models\\', 'Illuminate\\Support\\Facades\\DB', 'DB::', '::query()', 'Cache::'];

        foreach ($this->capabilities() as $capability) {
            $source = file_get_contents((new ReflectionClass($capability))->getFileName());

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    sprintf('%s references "%s". Capabilities ask the query layer; they never compose SQL.', $capability::class, $needle),
                );
            }
        }
    }

    /** Whatever else a capability takes, it must take the query layer by INTERFACE, never the impl. */
    public function test_capabilities_depend_on_the_query_interface_not_an_implementation(): void
    {
        foreach ($this->capabilities() as $capability) {
            $constructor = (new ReflectionClass($capability))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if (is_a($type->getName(), RepairHistoryQuery::class, true)) {
                    $this->assertSame(
                        RepairHistoryQuery::class,
                        $type->getName(),
                        sprintf('%s injects a concrete query implementation. Depend on the interface.', $capability::class),
                    );
                }
            }
        }
    }

    /** Every capability must declare a version, or its recommendations cannot be compared later. */
    public function test_every_capability_declares_a_version(): void
    {
        foreach ($this->capabilities() as $capability) {
            $this->assertNotSame('', $capability->version(), $capability::class.' has no version.');
        }
    }

    /**
     * A registered capability without a policy falls back to the permissive default and would fire
     * at every workflow state — which is how a card becomes noise.
     */
    public function test_every_registered_capability_has_a_registered_policy(): void
    {
        $registry = app(\App\Services\Intelligence\PolicyRegistry::class);

        foreach ($this->capabilities() as $capability) {
            $this->assertTrue(
                $registry->has($capability->id()),
                sprintf('Capability "%s" is registered without a policy.', $capability->id()),
            );
        }
    }
}
