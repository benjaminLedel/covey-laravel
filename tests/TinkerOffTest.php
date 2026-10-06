<?php

namespace Covey\Laravel\Tests;

use Illuminate\Support\Facades\Route;

// The default installation: tinker not switched on.
class TinkerOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('covey.tinker.enabled', false);
    }

    public function test_there_is_no_tinker_route(): void
    {
        $uris = array_map(fn ($r) => $r->uri(), Route::getRoutes()->getRoutes());
        $this->assertContains('covey/v1/query', $uris);
        $this->assertNotContains('covey/v1/tinker', $uris);
        $this->as(self::WRITE)->postJson('/covey/v1/tinker', ['code' => 'return 1;'])->assertStatus(404);
    }

    public function test_health_says_tinker_is_off(): void
    {
        $this->as(self::READ)->getJson('/covey/v1/health')->assertOk()->assertJson(['tinker_enabled' => false]);
    }
}
