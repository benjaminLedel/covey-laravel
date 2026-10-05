<?php

namespace Covey\Laravel\Tests;

class LogsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/covey-logs-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app['config']->set('covey.logs.paths', [$this->dir]);
        $this->app['config']->set('app.timezone', 'UTC');

        file_put_contents($this->dir.'/laravel-2026-10-04.log', implode("\n", [
            '[2026-10-04 08:00:00] production.INFO: Old day begins',
            '[2026-10-04 23:59:00] production.ERROR: Yesterday it broke too {"request_id":"req-old"}',
            '#0 /app/vendor/old.php(1): old()',
            '',
        ]));
        touch($this->dir.'/laravel-2026-10-04.log', strtotime('2026-10-04 23:59:30 UTC'));

        file_put_contents($this->dir.'/laravel-2026-10-05.log', implode("\n", [
            '[2026-10-05 09:00:00] production.INFO: Request started {"request_id":"req-1"}',
            '[2026-10-05 09:00:01] production.DEBUG: covey {"tables":3,"action":"schema","ability":"read","ms":2}',
            '[2026-10-05 09:00:02] production.WARNING: Slow query {"request_id":"req-1","ms":2400}',
            '[2026-10-05 09:00:03] production.ERROR: Call to undefined method App\Models\Order::ship() {"request_id":"req-2","userId":7,"exception":"[object] (Error(code: 0): Call to undefined method at /app/app/Http/Controllers/OrderController.php:42)',
            '[stacktrace]',
            '#0 /app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\Http\Controllers\OrderController->ship()',
            '#1 /app/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php(43): Illuminate\Routing\Controller->callAction()',
            '#2 /app/vendor/laravel/framework/src/Illuminate/Routing/Route.php(259): Illuminate\Routing\ControllerDispatcher->dispatch()',
            '#3 {main}',
            '"}',
            '[2026-10-05 09:00:04] production.INFO: Login {"email":"ada@example.org","password":"hunter2","headers":{"authorization":"Bearer eyJhbGciOi.payload.sig"}}',
            '[2026-10-05 09:00:05] production.INFO: Request finished {"request_id":"req-2"}',
            '',
        ]));
        touch($this->dir.'/laravel-2026-10-05.log', strtotime('2026-10-05 09:00:05 UTC'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_files_lists_newest_first_behind_the_read_token(): void
    {
        $this->getJson('/covey/v1/logs')->assertStatus(401);
        $r = $this->as(self::READ)->getJson('/covey/v1/logs')->assertOk();
        $this->assertSame(['laravel-2026-10-05.log', 'laravel-2026-10-04.log'], array_column($r->json('files'), 'name'));
        $this->assertGreaterThan(0, $r->json('files.0.size'));
    }

    public function test_tail_without_filters_is_the_end_of_the_newest_file(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['tail' => 2])->assertOk();
        $this->assertSame(['laravel-2026-10-05.log'], $r->json('files'));
        $this->assertSame(2, $r->json('count'));
        $this->assertSame('Login {"email":"ada@example.org","password":"[redacted]","headers":{"authorization":"[redacted]"}}', $r->json('entries.0.message'));
        $this->assertSame('Request finished {"request_id":"req-2"}', $r->json('entries.1.message'));
        $this->assertTrue($r->json('truncated'));
        $this->assertTrue($r->json('scan_complete'));
    }

    public function test_a_record_is_the_header_and_its_trace(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'Controller.php(54)', 'lines' => 4])->assertOk();
        $this->assertSame(1, $r->json('matches'));
        $e = $r->json('entries.0');
        $this->assertSame('error', $e['level']);
        $this->assertSame('2026-10-05 09:00:03', $e['at']);
        $this->assertStringStartsWith('[2026-10-05 09:00:03] production.ERROR: Call to undefined method', $e['lines'][0]);
        $this->assertSame('[stacktrace]', $e['lines'][1]);
        $this->assertCount(4, $e['lines']);
        $this->assertSame(3, $e['truncated_lines']);
        $this->assertTrue($e['match']);
    }

    public function test_grep_is_case_insensitive_unless_told_and_supports_regex(): void
    {
        $this->assertSame(1, $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'slow QUERY'])->json('matches'));
        $this->assertSame(0, $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'slow QUERY', 'case_sensitive' => true])->json('matches'));
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'req-[12]"\}$', 'regex' => true])->assertOk();
        $this->assertSame(2, $r->json('matches'));
        $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => '(', 'regex' => true])->assertStatus(422);
    }

    public function test_grep_without_a_file_searches_every_file_newest_first(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'broke'])->assertOk();
        $this->assertSame(1, $r->json('matches'));
        $this->assertSame('laravel-2026-10-04.log', $r->json('entries.0.file'));
        $this->assertSame(['laravel-2026-10-05.log', 'laravel-2026-10-04.log'], $r->json('files'));
    }

    public function test_level_and_min_level(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['level' => 'error,warning'])->assertOk();
        $this->assertSame(['error', 'warning', 'error'], array_reverse(array_column($r->json('entries'), 'level')));
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['min_level' => 'error'])->assertOk();
        $this->assertSame(2, $r->json('matches'));
        $this->as(self::READ)->postJson('/covey/v1/logs', ['min_level' => 'loud'])->assertStatus(422);
    }

    public function test_since_and_until_bound_the_scan(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['since' => '2026-10-05 09:00:02', 'until' => '2026-10-05 09:00:03'])->assertOk();
        $this->assertSame(['2026-10-05 09:00:02', '2026-10-05 09:00:03'], array_column($r->json('entries'), 'at'));
        // The old file is modified before `since` and is not opened at all.
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['since' => '2026-10-05 00:00:00', 'grep' => 'broke'])->assertOk();
        $this->assertSame(0, $r->json('matches'));
        $this->as(self::READ)->postJson('/covey/v1/logs', ['since' => 'yesterday-ish'])->assertStatus(422);
    }

    public function test_context_brings_the_neighbours(): void
    {
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'Slow query', 'context' => 1])->assertOk();
        $this->assertSame(['Request started {"request_id":"req-1"}', 'Slow query {"request_id":"req-1","ms":2400}'], array_map(fn ($e) => $e['message'], array_slice($r->json('entries'), 0, 2)));
        $this->assertSame([false, true, false], array_column($r->json('entries'), 'match'));
        // The package's own audit line sits between the two and is left out.
        $this->assertStringStartsWith('Call to undefined', $r->json('entries.2.message'));
    }

    public function test_own_audit_lines_are_hidden_unless_told_otherwise(): void
    {
        $this->assertSame(0, $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => '"action":"schema"'])->json('matches'));
        $this->app['config']->set('covey.logs.hide_own', false);
        $this->assertSame(1, $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => '"action":"schema"'])->json('matches'));
    }

    public function test_file_names_are_bare_names_only(): void
    {
        $this->as(self::READ)->postJson('/covey/v1/logs', ['file' => 'laravel-2026-10-04.log', 'tail' => 1])->assertOk()
            ->assertJsonPath('entries.0.message', 'Yesterday it broke too {"request_id":"req-old"}');
        $this->as(self::READ)->postJson('/covey/v1/logs', ['file' => '../../.env'])->assertStatus(404);
        $this->as(self::READ)->postJson('/covey/v1/logs', ['file' => 'nope.log'])->assertStatus(404);
    }

    public function test_switched_off_means_off_and_health_says_so(): void
    {
        $this->assertTrue($this->as(self::READ)->getJson('/covey/v1/health')->json('logs_enabled'));
        $this->app['config']->set('covey.logs.enabled', false);
        $this->as(self::WRITE)->getJson('/covey/v1/logs')->assertStatus(403);
        $this->as(self::WRITE)->postJson('/covey/v1/logs')->assertStatus(403);
        $this->assertFalse($this->as(self::READ)->getJson('/covey/v1/health')->json('logs_enabled'));
    }

    public function test_a_long_file_is_read_from_the_end_and_the_budget_ends_the_scan(): void
    {
        $path = $this->dir.'/laravel-2026-10-06.log';
        $fh = fopen($path, 'wb');
        for ($i = 0; $i < 20000; $i++) {
            fwrite($fh, sprintf("[2026-10-06 %02d:%02d:%02d] production.INFO: line %d with some padding to make the file wider than a chunk\n", intdiv($i, 3600), intdiv($i % 3600, 60), $i % 60, $i));
        }
        fwrite($fh, "[2026-10-06 10:00:00] production.ERROR: needle at the end\n");
        fclose($fh);
        touch($path, strtotime('2026-10-06 10:00:00 UTC'));
        $this->assertGreaterThan(65536 * 10, filesize($path));

        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['tail' => 3])->assertOk();
        $this->assertSame(['line 19998 with some padding to make the file wider than a chunk', 'line 19999 with some padding to make the file wider than a chunk', 'needle at the end'], array_column($r->json('entries'), 'message'));
        $this->assertLessThan(filesize($path), $r->json('scanned_bytes'));

        $this->app['config']->set('covey.logs.max_scan_bytes', 100000);
        $r = $this->as(self::READ)->postJson('/covey/v1/logs', ['grep' => 'line 0 with'])->assertOk();
        $this->assertSame(0, $r->json('matches'));
        $this->assertFalse($r->json('scan_complete'));
        $this->assertSame(100000, $r->json('scanned_bytes'));
    }
}
