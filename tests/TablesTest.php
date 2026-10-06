<?php

namespace Covey\Laravel\Tests;

use Covey\Laravel\Tables;
use PHPUnit\Framework\TestCase;

class TablesTest extends TestCase
{
    public function test_a_hidden_table_is_found_however_it_is_written(): void
    {
        $t = new Tables('', ['sessions']);
        foreach ([
            'SELECT * FROM sessions',
            'SELECT * FROM "Sessions"',
            'SELECT * FROM `sessions`',
            'SELECT * FROM [sessions]',
            'SELECT * FROM public.sessions',
            "SELECT * FROM table_to_xml('sessions', true, false, '')",
            "SELECT 1 /* unterminated sessions",
        ] as $sql) {
            $this->assertSame('sessions', $t->hiddenIn($sql), $sql);
        }
    }

    public function test_comments_and_longer_names_are_not_references(): void
    {
        $t = new Tables('', ['sessions']);
        $this->assertNull($t->hiddenIn("SELECT 1 -- sessions\n"));
        $this->assertNull($t->hiddenIn('SELECT 1 /* sessions */'));
        $this->assertNull($t->hiddenIn('SELECT * FROM user_sessions'));
        $this->assertNull($t->hiddenIn("SELECT '-- not a comment', name FROM customers"));
    }

    public function test_the_prefix_counts_both_ways(): void
    {
        $t = new Tables('app_', ['sessions']);
        $this->assertSame('app_sessions', $t->hiddenIn('SELECT * FROM app_sessions'));
        $this->assertSame('sessions', $t->hiddenIn('SELECT * FROM sessions'));
        $this->assertTrue($t->isHidden('APP_SESSIONS'));
        $this->assertFalse($t->isHidden('app_orders'));
        $this->assertSame('orders', $t->unprefixed('app_orders'));
        $this->assertSame('orders', $t->unprefixed('orders'));
        $this->assertSame('app_orders', $t->prefixed('orders'));
        $this->assertSame('app_orders', $t->prefixed('app_orders'));
    }
}
