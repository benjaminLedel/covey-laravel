<?php

// What covey may do in this application, and with which token.
//
// Two tokens, not one, because the difference between reading and writing is
// the difference covey's guard rails are built around: an agent that holds the
// read token can look, an agent that holds the write token can change — and an
// organisation puts an approval in front of the second without touching the
// first. Both are compared as hashes of what the request carries, so a token
// in a log file is still a token, but a config dump is not.
//
// Generate a token with `php artisan covey:token read|write`; it prints the
// value once and the hash to put here (or into the environment).
return [
    // The route prefix every endpoint sits under. The health endpoint is
    // what covey's probe calls; the rest is behind the token middleware.
    'prefix' => env('COVEY_PREFIX', 'covey/v1'),

    // Hashes of the two tokens (sha256, hex). An empty hash disables that
    // ability entirely: no write token, no tinker — whatever the agent holds.
    'tokens' => [
        'read' => env('COVEY_READ_TOKEN_HASH'),
        'write' => env('COVEY_WRITE_TOKEN_HASH'),
    ],

    // The database connection the read endpoints use. Point it at a replica
    // or a read-only user where you have one — the query endpoint refuses
    // anything but SELECT, but a read-only connection refuses it twice.
    'connection' => env('COVEY_DB_CONNECTION'),

    // Limits for the query endpoint.
    'query' => [
        'max_rows' => (int) env('COVEY_QUERY_MAX_ROWS', 200),
        'timeout_seconds' => (int) env('COVEY_QUERY_TIMEOUT', 15),
    ],

    // Tables and columns the read endpoints never return — password hashes,
    // tokens, anything an agent has no business reading into a ticket.
    // Columns are matched by name across all tables.
    'hidden' => [
        'tables' => ['password_reset_tokens', 'personal_access_tokens', 'sessions', 'failed_jobs'],
        'columns' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token', 'secret', 'token'],
    ],

    // Tinker: whether the write endpoint exists at all. It needs the write
    // token on top. Off by default — switching it on is the decision that an
    // agent may execute code in this application.
    'tinker' => [
        'enabled' => (bool) env('COVEY_TINKER_ENABLED', false),
        'timeout_seconds' => (int) env('COVEY_TINKER_TIMEOUT', 30),
    ],

    // Every call is written to this log channel with the ability used, the
    // statement or code, and how long it took. Null = the default channel.
    'log_channel' => env('COVEY_LOG_CHANNEL'),
];
