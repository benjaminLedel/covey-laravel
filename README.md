# covey-laravel

A door into a Laravel application for a [covey](https://github.com/benjaminLedel/covey) agent. Installed with Composer, it exposes four endpoints under one prefix:

| Endpoint | Token | What it does |
|---|---|---|
| `GET /covey/v1/health` | read | the probe: that the token works, as what, and whether tinker is on |
| `GET /covey/v1/schema`, `GET /covey/v1/schema/{table}` | read | the tables and columns, minus the ones the application hides |
| `POST /covey/v1/query` | read | **one** read-only SQL statement, rows capped, hidden columns stripped |
| `POST /covey/v1/tinker` | **write** | PHP in the application's context, as `php artisan tinker` runs it |

The line between the first three and the last one is the point of the package. **Reading and writing are different tokens**, and on the covey side they are different actions with different guard-rail subjects. An organisation can let an agent read everything and put an approval in front of `laravel:tinker`, or hand an agent the read token and nothing else. Nothing on the application side has to know which agent is which.

## Install

```bash
composer require benjaminledel/covey-laravel
php artisan vendor:publish --tag=covey-config   # optional
php artisan covey:token read
php artisan covey:token write                   # only if agents may change things
```

Each `covey:token` call prints a token **once** and the hash to configure:

```env
COVEY_READ_TOKEN_HASH=…
COVEY_WRITE_TOKEN_HASH=…
COVEY_TINKER_ENABLED=true        # off by default; the write token alone is not enough
COVEY_DB_CONNECTION=readonly     # optional: a read-only user or a replica for the read endpoints
```

The token goes into covey as the agent's `laravel_token`; the hash stays with the application. Neither is written anywhere by the command.

## On the covey side

`covey/laravel.json` in this repository is a covey **manifest plugin**. Upload it in the store (Target systems → upload a plugin), or install it from the catalogue once it is listed there. Then, per agent:

- secrets `laravel_url` (the application's base URL, without the prefix) and `laravel_token` (a read or a write token);
- `ACCESS.md`: `- system: laravel scope: read` or `scope: read,write`;
- the egress allowlist: the application's host.

The actions an agent sees are `schema`, `columns`, `query` and, with the write scope, `tinker`. `tinker` carries the guard-rail subject `laravel:tinker`, so a rule such as *require approval for `laravel:tinker`* governs every change an agent makes through this door while the reads stay free.

## What the read side guarantees

`query` refuses everything that is not a single `SELECT`, `WITH`, `SHOW`, `EXPLAIN` or `DESCRIBE` — checked on the statement text with comments and string literals stripped first, so a customer called *Update GmbH* is not a write and `-- drop` in a comment is not either. The statement then runs inside a transaction that is always rolled back, with a statement timeout where the driver has one, and the rows are capped (`COVEY_QUERY_MAX_ROWS`, default 200). Columns listed under `covey.hidden.columns` (password hashes, tokens, secrets by default) are stripped from every row and never shown in the schema; tables under `covey.hidden.tables` do not exist as far as the agent can tell.

Point `COVEY_DB_CONNECTION` at a connection with a read-only database user and the database enforces the same rule a third time.

## What the write side does not pretend

There is no way to let an agent run PHP in your application and keep it from changing things. `tinker` does not try. It is off until `COVEY_TINKER_ENABLED=true`, it needs the write token, and every call is logged with the code it ran. The place to decide *when* an agent may use it is covey's guard rails, in front of `laravel:tinker`, where the person who approves sees the code before it runs.

## Logging

Every call writes one line to the application log (`COVEY_LOG_CHANNEL` to pick a channel): the action, the ability of the token used, the statement or code, the row count or error, and the duration.

## Requirements

PHP 8.2, Laravel 11 or 12. `psy/psysh` for tinker.

## Licence

MIT.
