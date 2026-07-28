<?php

namespace Tests\Feature;

use App\Services\GarageRecommendationService;
use Tests\TestCase;

/**
 * Locks the CONSERVATIVE free-text → fault-category extractor (config/fault_extraction.php). Boots the
 * app for config() but needs no DB — extractCategories() is pure text→keys. These pin the precision
 * rules that matter on real N-Maintenance text (the ones easy to regress when tuning the keyword map).
 */
class FaultExtractionTest extends TestCase
{
    private GarageRecommendationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(GarageRecommendationService::class);
    }

    private function cats(?string $main, ?string $sup = null): array
    {
        return $this->svc->extractCategories($main, $sup, null);
    }

    public function test_maps_common_free_text_to_the_right_categories(): void
    {
        $this->assertSame(['engine'], $this->cats('Engine & Mechanical'));
        $this->assertSame(['engine'], $this->cats('Mechanical Issues'));
        $this->assertSame(['ac'], $this->cats('AC issues', 'AC Not Cooling'));
        $this->assertSame(['transmission'], $this->cats('Gearbox Not Engaging'));
        $this->assertSame(['brakes'], $this->cats('Braking Problems'));
        $this->assertSame(['suspension'], $this->cats('Steering box issue'));
        $this->assertSame(['tyres'], $this->cats('Flat Tire'));
        $this->assertSame(['routine'], $this->cats('Oil & Fillter Change'));
        $this->assertSame(['routine'], $this->cats('Periodic Maintenance'));
        $this->assertSame(['interior'], $this->cats('Deep Cleaning'));
    }

    public function test_rim_and_surface_scratch_are_bodywork_not_tyres(): void
    {
        $this->assertSame(['bodywork'], $this->cats('Rim Scratch'));
        $this->assertSame(['bodywork'], $this->cats('Minor Surface Scratch'));
        $this->assertNotContains('tyres', $this->cats('Rims scratch'));
    }

    public function test_indicator_lights_route_to_engine_or_electrical_not_lights(): void
    {
        // "Check Engine Light" and dashboard warnings must NOT become the exterior-lights category.
        $this->assertContains('engine', $this->cats('Check Engine Light'));
        $this->assertNotContains('lights', $this->cats('Check Engine Light'));
        $this->assertContains('electrical', $this->cats('Dashboard Warning Lights'));
        $this->assertNotContains('lights', $this->cats('Dashboard Warning Lights'));
        // But an actual exterior lamp fault IS lights.
        $this->assertContains('lights', $this->cats('Headlights / Taillights Fault'));
    }

    public function test_ac_token_does_not_fire_on_accident_or_accessories(): void
    {
        $this->assertSame(['bodywork'], $this->cats('Accident'));
        $this->assertNotContains('ac', $this->cats('Accessories'));
        $this->assertSame([], $this->cats('Accessories')); // pure noise → no category
    }

    public function test_noise_yields_no_category(): void
    {
        foreach (['Testing', 'New Car', 'Ready', 'Main reason', 'Customer', 'Maintenance', ''] as $noise) {
            $this->assertSame([], $this->cats($noise), "expected no category for '{$noise}'");
        }
    }

    public function test_multiple_categories_from_one_record(): void
    {
        $cats = $this->cats('Engine, Tires, Electrical, Body & Exterior', 'Oil & Fillter Change');
        foreach (['engine', 'tyres', 'electrical', 'bodywork', 'routine'] as $expected) {
            $this->assertContains($expected, $cats);
        }
    }
}
