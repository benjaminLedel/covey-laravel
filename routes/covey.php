<?php

use Covey\Laravel\Http\Controllers\HealthController;
use Covey\Laravel\Http\Controllers\LogsController;
use Covey\Laravel\Http\Controllers\QueryController;
use Covey\Laravel\Http\Controllers\SchemaController;
use Covey\Laravel\Http\Controllers\TinkerController;
use Illuminate\Support\Facades\Route;

// The read side: whoever holds the read token (or the write token, which
// implies it) may look. The log search is a POST for the same reason query
// is: covey's manifest engine carries parameters in a JSON body, and a GET
// has none.
Route::middleware('covey.token:read')->group(function () {
    Route::get('health', HealthController::class);
    Route::get('schema', [SchemaController::class, 'tables']);
    Route::get('schema/{table}', [SchemaController::class, 'columns']);
    Route::post('query', QueryController::class);
    Route::get('logs', [LogsController::class, 'files']);
    Route::post('logs', [LogsController::class, 'search']);
});

// The write side: a different token, a different covey guard-rail subject
// (laravel:tinker), and off unless the application says otherwise.
Route::middleware('covey.token:write')->group(function () {
    Route::post('tinker', TinkerController::class);
});
