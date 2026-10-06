<?php

namespace Covey\Laravel\Tests;

class EndpointsTest extends TestCase
{
    public function test_no_token_is_refused(): void
    {
        $this->getJson('/covey/v1/health')->assertStatus(401);
        $this->as('wrong')->getJson('/covey/v1/health')->assertStatus(401);
    }

    public function test_health_names_the_ability(): void
    {
        $this->as(self::READ)->getJson('/covey/v1/health')
            ->assertOk()->assertJson(['ability' => 'read', 'identity' => 'Testapp (read)']);
        $this->as(self::WRITE)->getJson('/covey/v1/health')
            ->assertOk()->assertJson(['ability' => 'write']);
    }

    public function test_schema_hides_what_it_is_told_to(): void
    {
        $r = $this->as(self::READ)->getJson('/covey/v1/schema')->assertOk();
        $names = array_column($r->json('tables'), 'name');
        $this->assertContains('customers', $names);
        $this->assertNotContains('sessions', $names);

        $cols = array_column($this->as(self::READ)->getJson('/covey/v1/schema/customers')->assertOk()->json('columns'), 'name');
        $this->assertContains('email', $cols);
        $this->assertNotContains('password', $cols);

        $this->as(self::READ)->getJson('/covey/v1/schema/sessions')->assertStatus(404);
        $this->as(self::READ)->getJson('/covey/v1/schema/nope')->assertStatus(404);
    }

    public function test_query_reads_with_bindings_and_strips_hidden_columns(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/query', [
            'sql' => 'SELECT * FROM customers WHERE email = ?',
            'bindings' => ['ben@example.org'],
        ])->assertOk();
        $this->assertSame(1, $r->json('count'));
        $this->assertSame('Ben', $r->json('rows.0.name'));
        $this->assertArrayNotHasKey('password', $r->json('rows.0'));
    }

    public function test_query_caps_rows_and_says_so(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => 'SELECT * FROM customers', 'limit' => 1])->assertOk();
        $this->assertSame(1, $r->json('count'));
        $this->assertTrue($r->json('truncated'));
    }

    public function test_query_refuses_writes_and_leaves_nothing_behind(): void
    {
        $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => "UPDATE customers SET status = 'x'"])->assertStatus(422);
        $this->as(self::WRITE)->postJson('/covey/v1/query', ['sql' => 'DELETE FROM customers'])->assertStatus(422);
        $this->assertSame(2, $this->app['db']->table('customers')->count());
    }

    public function test_query_reports_a_bad_statement_instead_of_crashing(): void
    {
        $this->as(self::READ)->postJson('/covey/v1/query', ['sql' => 'SELECT nope FROM customers'])
            ->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_tinker_needs_the_write_token(): void
    {
        $this->as(self::READ)->postJson('/covey/v1/tinker', ['code' => 'return 1;'])->assertStatus(403);
    }

    public function test_tinker_runs_in_the_application(): void
    {
        $r = $this->as(self::WRITE)->postJson('/covey/v1/tinker', [
            'code' => "DB::table('customers')->where('name', 'Ben')->update(['status' => 'active']); DB::table('customers')->where('status', 'active')->count()",
        ])->assertOk();
        $this->assertStringContainsString('2', $r->json('output'));
        $this->assertSame('active', $this->app['db']->table('customers')->where('name', 'Ben')->value('status'));
    }

    public function test_tinker_reports_an_error_in_the_code(): void
    {
        $r = $this->as(self::WRITE)->postJson('/covey/v1/tinker', ['code' => "throw new RuntimeException('nope');"])->assertStatus(422);
        $this->assertStringContainsString('nope', $r->json('error'));
        $r = $this->as(self::WRITE)->postJson('/covey/v1/tinker', ['code' => 'DB::table('])->assertStatus(422);
        $this->assertNotNull($r->json('error'));
    }

    public function test_tinker_switched_off_after_the_routes_loaded_still_refuses(): void
    {
        config(['covey.tinker.enabled' => false]);
        $this->as(self::WRITE)->postJson('/covey/v1/tinker', ['code' => 'return 1;'])->assertStatus(403);
    }
}
