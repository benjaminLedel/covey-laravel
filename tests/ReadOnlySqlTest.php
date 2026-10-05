<?php

namespace Covey\Laravel\Tests;

use Covey\Laravel\ReadOnlySql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReadOnlySqlTest extends TestCase
{
    public static function reads(): array
    {
        return [
            ['SELECT * FROM customers WHERE id = ?'],
            ['select name from customers where name = \'Update GmbH\''],
            ["SELECT 1 -- drop table customers\n"],
            ['/* delete */ SELECT 1'],
            ['WITH c AS (SELECT 1) SELECT * FROM c;'],
            ['SHOW TABLES'],
            ['EXPLAIN SELECT 1'],
            ['SELECT "insert" FROM customers'],
            ['SELECT `update` FROM customers'],
        ];
    }

    public static function writes(): array
    {
        return [
            ['UPDATE customers SET status = \'x\''],
            ['DELETE FROM customers'],
            ['SELECT 1; DROP TABLE customers'],
            ['SELECT * FROM customers FOR UPDATE'],
            ['SELECT * INTO OUTFILE \'/tmp/x\' FROM customers'],
            ['INSERT INTO customers VALUES (1)'],
            ['CALL cleanup()'],
            ['SELECT pg_sleep(100)'],
            ['BEGIN'],
            [''],
            ['   ;  '],
            ["SELECT 1 /* unterminated\nUPDATE customers SET name = 'x'"],
        ];
    }

    #[DataProvider('reads')]
    public function test_reads_pass(string $sql): void
    {
        $this->assertNull(ReadOnlySql::check($sql), $sql);
    }

    #[DataProvider('writes')]
    public function test_writes_are_refused(string $sql): void
    {
        $this->assertNotNull(ReadOnlySql::check($sql), $sql);
    }
}
