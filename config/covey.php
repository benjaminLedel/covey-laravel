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

    // The application log, behind the read token. On the covey side this is
    // its own guard-rail subject (laravel:logs): a log holds the request that
    // failed, with whatever was in it, which is more than a column list does.
    'logs' => [
        // Off removes the door entirely, whatever token the agent holds.
        'enabled' => (bool) env('COVEY_LOGS_ENABLED', true),

        // Where to look. Null = the application's storage/logs. Only bare file
        // names ever cross the wire; the directories stay here.
        'paths' => null,
        'glob' => env('COVEY_LOGS_GLOB', '*.log'),

        // How much one call may return: records per answer, lines kept per
        // record (the head of a stack trace, not its tail), and how many
        // bytes a search may read from the end of the files before it stops
        // and says so.
        'max_entries' => (int) env('COVEY_LOGS_MAX_ENTRIES', 200),
        'max_entry_lines' => (int) env('COVEY_LOGS_MAX_ENTRY_LINES', 40),
        'max_scan_bytes' => (int) env('COVEY_LOGS_MAX_SCAN_BYTES', 32 * 1024 * 1024),

        // Leave out the lines this package writes itself (see log_channel),
        // so an agent does not read its own trail as the application's.
        'hide_own' => true,

        // Applied to every line before it leaves: pattern => replacement.
        // What a dumped request carried — a bearer token, a password field,
        // an app key — is not something an agent writes into a ticket.
        'redact' => [
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [redacted]',
            // A quoted value runs to its closing quote, a bare one to the next
            // separator — the (?(2)…|…) conditional picks which.
            '/(["\']?(?:password|passwd|password_confirmation|secret|token|api_key|apikey|authorization)["\']?\s*(?:=>|[:=])\s*(["\'])?)(?(2)[^"\']*|[^"\',\s}\]]+)/i' => '$1[redacted]',
            '/base64:[A-Za-z0-9+\/]{40,}={0,2}/' => '[redacted]',
        ],
    ],

    // Every call is written to this log channel with the ability used, the
    // statement or code, and how long it took. Null = the default channel.
    'log_channel' => env('COVEY_LOG_CHANNEL'),
];
