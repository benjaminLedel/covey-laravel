<?php

namespace Covey\Laravel\Tests;

use Illuminate\Support\Facades\Schema;

class HiddenTablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['db']->table('sessions')->insert(['id' => 'session-secret']);
    }

    public function test_query_refuses_a_hidden_table(): void
    {
        foreach ([
            'SELECT * FROM sessions',
            'select id from SESSIONS',
            'SELECT * FROM "sessions"',
            'SELECT * FROM `sessions`',
            'SELECT * FROM main.sessions',
            'SELECT c.name FROM customers c JOIN sessions s ON s.id = c.name',
            'SELECT (SELECT count(*) FROM sessions) AS n',
            'WITH x AS (SELECT * FROM sessions) SELECT * FROM x',
        ] as $sql) {
            $r = $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => $sql]);
            $r->assertStatus(422);
            $this->assertStringContainsString('sessions', (string) $r->json('error'), $sql);
        }
    }

    public function test_a_hidden_name_in_a_comment_is_not_a_reference(): void
    {
        $this->as(self::READ)->postJson('/covey/v1/query', [
            'sql' => "SELECT name FROM customers -- not from sessions\n",
        ])->assertOk();
    }

    public function test_a_prefixed_connection_lists_hides_and_describes_tables(): void
    {
        $this->app['config']->set('database.connections.prefixed', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'app_']);
        $this->app['config']->set('covey.connection', 'prefixed');
        Schema::connection('prefixed')->create('orders', function ($t) {
            $t->id();
            $t->string('number');
            $t->string('token')->nullable();
        });
        Schema::connection('prefixed')->create('sessions', function ($t) {
            $t->string('id')->primary();
        });
        $this->app['db']->connection('prefixed')->table('sessions')->insert(['id' => 'session-secret']);

        $names = array_column($this->as(self::READ)->getJson('/covey/v1/schema')->assertOk()->json('tables'), 'name');
        // The names as the database knows them: a statement sent to /query
        // has to use those, the prefix is not added to raw SQL.
        $this->assertSame(['app_orders'], $names);

        // The name as listed, and the name without the prefix, both describe it.
        foreach (['app_orders', 'orders'] as $name) {
            $r = $this->as(self::READ)->getJson('/covey/v1/schema/'.$name)->assertOk();
            $this->assertSame('app_orders', $r->json('table'));
            $cols = array_column($r->json('columns'), 'name');
            $this->assertContains('number', $cols);
            $this->assertNotContains('token', $cols);
        }

        $this->as(self::READ)->getJson('/covey/v1/schema/app_sessions')->assertStatus(404);
        $this->as(self::READ)->getJson('/covey/v1/schema/sessions')->assertStatus(404);
        $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => 'SELECT * FROM app_sessions'])->assertStatus(422);
        $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => 'SELECT number FROM app_orders'])->assertOk();
    }
}
