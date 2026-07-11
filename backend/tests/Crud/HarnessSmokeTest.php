<?php

namespace Tests\Crud;

class HarnessSmokeTest extends CrudTestCase
{
    public function test_auth_and_a_read_endpoint_work(): void
    {
        $res = $this->getJson('/api/Vendor');
        $res->assertSuccessful();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        // Drop the acting-as user to confirm the auth wall is real.
        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders(['Authorization' => 'Bearer nope'])->getJson('/api/Vendor');
        $res->assertStatus(401);
    }

    public function test_can_create_a_vendor(): void
    {
        $id = $this->makeVendor();
        $this->assertGreaterThan(0, $id);
    }
}
