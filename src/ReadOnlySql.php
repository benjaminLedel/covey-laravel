<?php

namespace Covey\Laravel;

// Decides whether a statement only reads. Deliberately strict rather than
// clever: one statement, starting with SELECT, WITH, SHOW, EXPLAIN or DESCRIBE,
// without a second statement behind a semicolon and without the words that
// write, lock or call. A false "no" costs the agent a rephrase; a false "yes"
// would cost somebody their data — and the transaction rollback behind this
// check is the second line, not the first.
class ReadOnlySql
{
    private const ALLOWED_START = ['select', 'with', 'show', 'explain', 'describe', 'desc'];

    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'merge', 'replace', 'upsert',
        'create', 'alter', 'drop', 'truncate', 'rename', 'grant', 'revoke',
        'call', 'exec', 'execute', 'do', 'load', 'copy', 'vacuum', 'analyze', 'reindex', 'cluster',
        'lock', 'set', 'reset', 'begin', 'commit', 'rollback', 'savepoint', 'start',
        'into', // SELECT … INTO OUTFILE / INTO new_table
        'for',  // SELECT … FOR UPDATE / FOR SHARE
        'pg_sleep', 'sleep', 'benchmark', 'pg_read_file', 'pg_terminate_backend', 'lo_import', 'lo_export',
    ];

    // check returns null when the statement may run, or the sentence that says
    // why not.
    public static function check(string $sql): ?string
    {
        $stripped = self::stripCommentsAndStrings($sql);
        $trimmed = trim($stripped);
        if ($trimmed === '') {
            return 'sql is empty';
        }
        $body = rtrim($trimmed, "; \t\n\r");
        if (str_contains($body, ';')) {
            return 'one statement per call — a second statement behind a semicolon is refused';
        }
        if (! preg_match('/^([a-z]+)/i', $body, $m) || ! in_array(strtolower($m[1]), self::ALLOWED_START, true)) {
            return 'only SELECT, WITH, SHOW, EXPLAIN and DESCRIBE run here — anything that writes goes through tinker, behind the write token';
        }
        $words = preg_split('/[^a-z_]+/i', strtolower($body), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if (in_array($w, self::FORBIDDEN, true)) {
                return "the statement contains \"$w\", which this endpoint does not run — reads only";
            }
        }

        return null;
    }

    // Comments and string literals are replaced by spaces before the word test:
    // a customer named "Update GmbH" in a WHERE clause is not a write, and
    // "-- drop" in a comment is not either. Conservative with what it cannot
    // parse: an unterminated string or comment leaves the rest in place, so
    // the word test still sees it.
    private static function stripCommentsAndStrings(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    // An unterminated comment is not a statement the database
                    // would run either; keep its text so the word test sees it.
                    $out .= substr($sql, $i);
                    break;
                }
                $i = $end + 2;
                $out .= ' ';
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $n && $sql[$j + 1] === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= $c === '`' ? ' `x` ' : " '' ";
                $i = $j + 1;
                continue;
            }
            $out .= $c;
            $i++;
        }

        return $out;
    }
}
