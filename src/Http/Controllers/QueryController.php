<?php

namespace Covey\Laravel\Http\Controllers;

use Covey\Laravel\Audit;
use Covey\Laravel\ReadOnlySql;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// A SELECT, and nothing else, with the row count capped and the hidden
// columns stripped from the result. The statement is checked twice: by
// ReadOnlySql before it runs, and by a transaction that is rolled back
// afterwards — so even a statement the checker misjudged leaves nothing
// behind. Point covey.connection at a read-only database user and it is
// checked a third time, by the database itself.
class QueryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $sql = (string) $request->input('sql', '');
        $bindings = (array) $request->input('bindings', []);
        $limit = min((int) $request->input('limit', 0) ?: config('covey.query.max_rows', 200), config('covey.query.max_rows', 200));

        $problem = ReadOnlySql::check($sql);
        if ($problem !== null) {
            return response()->json(['error' => $problem], 422);
        }

        $connection = DB::connection(config('covey.connection') ?: null);
        $hidden = array_map('strtolower', (array) config('covey.hidden.columns', []));
        $rows = [];
        $truncated = false;
        try {
            $connection->beginTransaction();
            $this->applyTimeout($connection);
            // One row more than asked for, so that "there was more" is a fact
            // and not a guess.
            $result = $connection->select($sql, $bindings);
            foreach ($result as $i => $row) {
                if ($i >= $limit) {
                    $truncated = true;
                    break;
                }
                $rows[] = $this->strip((array) $row, $hidden);
            }
        } catch (QueryException $e) {
            $connection->rollBack();
            Audit::record('query', $request->attributes->get('covey.ability'), ['sql' => $sql, 'error' => $e->getMessage()], $t0);

            return response()->json(['error' => $e->getMessage()], 422);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        Audit::record('query', $request->attributes->get('covey.ability'), ['sql' => $sql, 'rows' => count($rows), 'truncated' => $truncated], $t0);

        return response()->json([
            'rows' => $rows,
            'count' => count($rows),
            'truncated' => $truncated,
            'limit' => $limit,
        ]);
    }

    private function strip(array $row, array $hidden): array
    {
        foreach (array_keys($row) as $key) {
            if (in_array(strtolower((string) $key), $hidden, true)) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    // A statement timeout where the driver has one. A query that runs for
    // minutes on a production database is not a question, it is an incident.
    private function applyTimeout($connection): void
    {
        $seconds = max(1, (int) config('covey.query.timeout_seconds', 15));
        try {
            match ($connection->getDriverName()) {
                'pgsql' => $connection->statement('SET LOCAL statement_timeout = '.($seconds * 1000)),
                'mysql', 'mariadb' => $connection->statement('SET SESSION max_statement_time = '.$seconds),
                default => null,
            };
        } catch (QueryException) {
            // MySQL (as opposed to MariaDB) spells it max_execution_time, in
            // milliseconds, and only for SELECT — try that, and give up quietly
            // if the server knows neither. The timeout is a belt, not the trousers.
            try {
                $connection->statement('SET SESSION max_execution_time = '.($seconds * 1000));
            } catch (QueryException) {
            }
        }
    }
}
