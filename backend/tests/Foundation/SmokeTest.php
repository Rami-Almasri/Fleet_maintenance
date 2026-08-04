<?php

namespace Tests\Foundation;

class SmokeTest extends FoundationTestCase
{
    public function test_harness_boots_and_reaches_the_catalog(): void
    {
        $res = $this->getJson('/api/parts-catalog');
        $res->assertSuccessful();
        $this->assertGreaterThan(100, count($res->json('data.parts')));
    }
}
