<?php

use Covey\Laravel\Http\Controllers\HealthController;
use Covey\Laravel\Http\Controllers\QueryController;
use Covey\Laravel\Http\Controllers\SchemaController;
use Covey\Laravel\Http\Controllers\TinkerController;
use Illuminate\Support\Facades\Route;

// The read side: whoever holds the read token (or the write token, which
// implies it) may look.
Route::middleware('covey.token:read')->group(function () {
    Route::get('health', HealthController::class);
    Route::get('schema', [SchemaController::class, 'tables']);
    Route::get('schema/{table}', [SchemaController::class, 'columns']);
    Route::post('query', QueryController::class);
});

// The write side: a different token, a different covey guard-rail subject
// (laravel:tinker), and off unless the application says otherwise.
Route::middleware('covey.token:write')->group(function () {
    Route::post('tinker', TinkerController::class);
});
