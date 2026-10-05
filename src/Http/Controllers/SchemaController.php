<?php

namespace Covey\Laravel\Http\Controllers;

use Covey\Laravel\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

// The shape of the database, minus what an agent has no business seeing. An
// agent that knows the tables writes a query that hits; one that does not
// guesses column names and burns turns.
class SchemaController
{
    public function tables(Request $request): JsonResponse
    {
        $t0 = microtime(true);
        $hidden = array_map('strtolower', (array) config('covey.hidden.tables', []));
        $out = [];
        foreach (Schema::connection($this->connection())->getTables() as $table) {
            $name = $table['name'];
            if (in_array(strtolower($name), $hidden, true)) {
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
        $hiddenTables = array_map('strtolower', (array) config('covey.hidden.tables', []));
        if (in_array(strtolower($table), $hiddenTables, true) || ! Schema::connection($this->connection())->hasTable($table)) {
            return response()->json(['error' => "no table named $table"], 404);
        }
        $hiddenColumns = array_map('strtolower', (array) config('covey.hidden.columns', []));
        $out = [];
        foreach (Schema::connection($this->connection())->getColumns($table) as $col) {
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
        Audit::record('schema', $request->attributes->get('covey.ability'), ['table' => $table], $t0);

        return response()->json(['table' => $table, 'columns' => $out]);
    }

    private function connection(): ?string
    {
        return config('covey.connection') ?: null;
    }
}
