# covey-laravel

[![covey.work](https://img.shields.io/badge/website-covey.work-cc7a5b)](https://covey.work)
[![Packagist](https://img.shields.io/packagist/v/benjaminledel/covey-laravel?color=1f883d)](https://packagist.org/packages/benjaminledel/covey-laravel)
[![Tests](https://github.com/benjaminLedel/covey-laravel/actions/workflows/test.yml/badge.svg)](https://github.com/benjaminLedel/covey-laravel/actions/workflows/test.yml)
[![Licence](https://img.shields.io/badge/licence-MIT-336791)](LICENSE)

A door into a Laravel application for a [covey](https://covey.work) agent. covey runs AI agents like employees — each with its own sandbox, its own logins, a backlog and central guard rails ([covey.work](https://covey.work), [docs](https://covey.work/docs), [source](https://github.com/benjaminLedel/covey)). This package is what the agent reaches when one of its target systems is your Laravel application. Installed with Composer, it exposes five endpoints under one prefix:

| Endpoint | Token | What it does |
|---|---|---|
| `GET /covey/v1/health` | read | the probe: that the token works, as what, and whether tinker is on |
| `GET /covey/v1/schema`, `GET /covey/v1/schema/{table}` | read | the tables and columns, minus the ones the application hides |
| `POST /covey/v1/query` | read | **one** read-only SQL statement, rows capped, hidden columns stripped |
| `GET /covey/v1/logs`, `POST /covey/v1/logs` | read | the log files, and a search through them: records from the end backwards, secrets redacted |
| `POST /covey/v1/tinker` | **write** | PHP in the application's context, as `php artisan tinker` runs it |

The line between the first three and the last one is the point of the package. **Reading and writing are different tokens**, and on the covey side they are different actions with different guard-rail subjects. An organisation can let an agent read everything and put an approval in front of `laravel:tinker`, or hand an agent the read token and nothing else. Nothing on the application side has to know which agent is which.

## Install

```bash
composer require benjaminledel/covey-laravel
php artisan vendor:publish --tag=covey-config   # optional
php artisan covey:token read
```

`covey:token` prints a token **once** and the hash to configure:

```env
COVEY_READ_TOKEN_HASH=…
COVEY_DB_CONNECTION=readonly     # optional: a read-only user or a replica for the read endpoints
```

The token goes into covey as the agent's `laravel_token`; the hash stays with the application. Neither is written anywhere by the command.

This installs the read side only. Until the write side is switched on, the `tinker` route does not exist and nothing in the package runs code.

### Switching on the write side

A decision for a person, not a step of the installation: it lets an agent run PHP in the application. It takes three things together:

```bash
php artisan covey:token write    # a second token, kept apart from the read token
```

```env
COVEY_WRITE_TOKEN_HASH=…
COVEY_TINKER_ENABLED=true        # registers the tinker route; the write token alone is not enough
```

and, on the covey side, a guard rail in front of `laravel:tinker` if a person is to approve each call. Tinker needs `psy/psysh`, which a Laravel application usually has through `laravel/tinker`. With a cached route list, run `php artisan route:cache` again after changing `COVEY_TINKER_ENABLED`.

## On the covey side

`covey/laravel.json` in this repository is a covey **manifest plugin** (how those work: [covey.work/docs/target-systems](https://covey.work/docs/target-systems)). Install it from the [plugin catalogue](https://github.com/benjaminLedel/covey-plugins) in the store (Target systems → Laravel application), or upload the file by hand. Then, per agent:

- secrets `laravel_url` (the application's base URL, without the prefix) and `laravel_token` (a read or a write token);
- `ACCESS.md`: `- system: laravel scope: read` or `scope: read,write`;
- the egress allowlist: the application's host.

The actions an agent sees are `schema`, `columns`, `query`, `log_files`, `logs` and, with the write scope, `tinker`. Two of them carry a [guard-rail](https://covey.work/docs/guard-rails) subject of their own: `tinker` is `laravel:tinker`, so a rule such as *require approval for `laravel:tinker`* governs every change an agent makes through this door while the reads stay free; `logs` is `laravel:logs`, because a log holds what a column list does not — the request that failed, with whatever was in it — and an organisation may want an approval in front of that without touching schema or query.

## What the read side guarantees

`query` refuses everything that is not a single `SELECT`, `WITH`, `SHOW`, `EXPLAIN` or `DESCRIBE` — checked on the statement text with comments and string literals stripped first, so a customer called *Update GmbH* is not a write and `-- drop` in a comment is not either. The statement then runs inside a transaction that is always rolled back, with a statement timeout where the driver has one, and the rows are capped (`COVEY_QUERY_MAX_ROWS`, default 200). Columns listed under `covey.hidden.columns` (password hashes, tokens, secrets by default) are stripped from every row and never shown in the schema. Tables under `covey.hidden.tables` are left out of the schema, and a statement that names one is refused — with or without the connection's table prefix, quoted, schema-qualified or inside a string literal; Postgres functions that run a statement assembled from strings (`query_to_xml`, `dblink`) are refused outright.

The schema lists tables by the name the database knows, prefix included, because that is what a statement has to use; `schema/{table}` takes that name or the one without the prefix.

Both are checks on the statement text. Point `COVEY_DB_CONNECTION` at a connection with a read-only database user that has no grants on the hidden tables, and the database enforces the same rules a third time — that is the guarantee; the text checks are what spare the agent the round trip.

## What the log side returns

`POST /covey/v1/logs` reads the application log the way a person on call does: from the end, in **records** rather than lines — the `[timestamp] env.LEVEL: message` header plus the lines of its stack trace — and it stops as soon as it has what was asked for. The filters are `grep` (text, or a regex with `regex: true`; case-insensitive unless `case_sensitive: true`), `level` (a list) or `min_level`, `since`/`until` (absolute, or relative such as `-2 hours`), `tail` (records, default 50), `context` (neighbouring records around each match) and `lines` (lines kept per record — the head of the trace, where the frame that matters is). Without `file` a search runs through every file newest first; files modified before `since` are not opened.

The scan is bounded three times: by `tail`, by `since`, and by `COVEY_LOGS_MAX_SCAN_BYTES` (default 32 MB from the end), and the answer says how far it got (`scanned_bytes`, `scan_complete`). Every line passes through the patterns under `covey.logs.redact` before it leaves — bearer tokens, password fields, app keys in a dumped request — and the package's own audit lines are left out. File names are bare names resolved against `covey.logs.paths` (default `storage/logs`); a path never crosses the wire in either direction. `COVEY_LOGS_ENABLED=false` removes the door.

```env
COVEY_LOGS_ENABLED=true           # default on; off removes the endpoints
COVEY_LOGS_MAX_ENTRIES=200        # records per answer, at most
COVEY_LOGS_MAX_ENTRY_LINES=40     # lines per record, at most
COVEY_LOGS_MAX_SCAN_BYTES=33554432
```

## What the write side does not pretend

There is no way to let an agent run PHP in your application and keep it from changing things. `tinker` does not try. Its route does not exist until `COVEY_TINKER_ENABLED=true`, it needs the write token, and every call is logged with the code it ran. The place to decide *when* an agent may use it is covey's guard rails, in front of `laravel:tinker`, where the person who approves sees the code before it runs.

## Logging

Every call writes one line to the application log (`COVEY_LOG_CHANNEL` to pick a channel): the action, the ability of the token used, the statement or code, the row count or error, and the duration.

## Requirements

Laravel 10.34 or newer — 10, 11, 12 and 13 are tested — on any PHP version that release supports (8.1 at the lowest). 10.34 is the first release with `Schema::getTables()`, which the schema endpoint reads. `psy/psysh` 0.11 or 0.12 for tinker, if it is switched on.

Laravel 10 and 11 no longer get security fixes, and every release of either carries open advisories, which Composer 2.9 and later refuse by default. That is the state of an application still on them, not something this package adds; it runs there so that such an application can have an agent too — including one that helps it move on.

## About covey

[covey](https://covey.work) is the IT and HR department for AI agents: an identity, an isolated sandbox, brokered access, a backlog and a place in the org chart for every agent, with governance in one place. It is open source ([github.com/benjaminLedel/covey](https://github.com/benjaminLedel/covey)) and there is a hosted beta at [app.covey.work](https://app.covey.work). This package is one of its target-system plugins; the others, and how to write your own, are in the [docs](https://covey.work/docs).

## Licence

MIT.
