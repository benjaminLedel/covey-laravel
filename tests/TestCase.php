<?php

namespace Covey\Laravel\Tests;

use Covey\Laravel\CoveyServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const READ = 'covey_read_test';

    public const WRITE = 'covey_write_test';

    protected function getPackageProviders($app): array
    {
        return [CoveyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('covey.tokens.read', hash('sha256', self::READ));
        $app['config']->set('covey.tokens.write', hash('sha256', self::WRITE));
        $app['config']->set('covey.tinker.enabled', true);
        $app['config']->set('app.name', 'Testapp');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('customers', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password')->nullable();
            $t->string('status')->default('active');
        });
        Schema::create('sessions', function ($t) {
            $t->string('id')->primary();
        });
        $this->app['db']->table('customers')->insert([
            ['name' => 'Ada', 'email' => 'ada@example.org', 'password' => 'hash1', 'status' => 'active'],
            ['name' => 'Ben', 'email' => 'ben@example.org', 'password' => 'hash2', 'status' => 'cancelled'],
        ]);
    }

    protected function as(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
