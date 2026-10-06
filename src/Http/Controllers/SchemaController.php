<?php

namespace Covey\Laravel\Http\Controllers;

use Covey\Laravel\Audit;
use Covey\Laravel\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The shape of the database, minus what an agent has no business seeing. An
// agent that knows the tables writes a query that hits; one that does not
// guesses column names and burns turns.
//
// The list names tables as the database knows them, with the connection's
// prefix: that is the name a statement sent to /query has to use. The
// columns of a table can be asked for by that name or by the one without the
// prefix.
class SchemaController
{
    public function tables(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $tables = Tables::for(DB::connection($this->connection()));
        $out = [];
        foreach (Schema::connection($this->connection())->getTables() as $table) {
            $name = $table['name'];
            if ($tables->isHidden($name)) {
                continue;
            }
            $out[] = ['name' => $name, 'comment' => $table['comment'] ?? null];
        }
        Audit::record('schema', $request->attributes->get('covey.ability'), ['tables' => count($out)], $t0);

        return response()->json(['tables' => $out]);
    }

    public function columns(Request $request, string $table): JsonResponse
    {
        $t0 = microtime(true);
        $tables = Tables::for(DB::connection($this->connection()));
        // The schema builder adds the prefix itself, so it gets the name without.
        $unprefixed = $tables->unprefixed($table);
        if ($tables->isHidden($table) || ! Schema::connection($this->connection())->hasTable($unprefixed)) {
            return response()->json(['error' => "no table named $table"], 404);
        }
        $hiddenColumns = array_map('strtolower', (array) config('covey.hidden.columns', []));
        $out = [];
        foreach (Schema::connection($this->connection())->getColumns($unprefixed) as $col) {
            if (in_array(strtolower($col['name']), $hiddenColumns, true)) {
                continue;
            }
            $out[] = [
                'name' => $col['name'],
                'type' => $col['type'],
                'nullable' => (bool) ($col['nullable'] ?? false),
                'default' => $col['default'] ?? null,
                'comment' => $col['comment'] ?? null,
            ];
        }
        $name = $tables->prefixed($unprefixed);
        Audit::record('schema', $request->attributes->get('covey.ability'), ['table' => $name], $t0);

        return response()->json(['table' => $name, 'columns' => $out]);
    }

    private function connection(): ?string
    {
        return config('covey.connection') ?: null;
    }
}
