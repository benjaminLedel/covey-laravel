# Changelog

## 0.1.2 — 2026-10-06

- Laravel 10.34 through 13 on PHP 8.1 and up, where 0.1.1 claimed Laravel 12 and PHP 8.2 only. 10.34 is the first release with `Schema::getTables()`. `psy/psysh` 0.11 is accepted beside 0.12. CI tests every supported Laravel on the PHP versions it runs on, plus a prefer-lowest run. (#5)
- `query` refuses a statement that names a table under `covey.hidden.tables`. Until now the tables were only left out of the schema, and `SELECT * FROM sessions` returned the rows. Postgres functions that run a statement assembled from strings (`query_to_xml`, `dblink`) are refused as well. (#7)
- A connection with a table prefix: hidden tables are matched with and without the prefix, so they no longer show up in the schema list, and `schema/{table}` finds a table under the name the list gives it instead of answering 404. (#8)

## 0.1.1 — 2026-10-05

- Package metadata: homepage covey.work, docs and issues under `support`, the description names the log endpoint. No code change.

## 0.1.0 — 2026-10-05

- First cut: `health`, `schema`, `schema/{table}`, `query` behind the read token; `tinker` behind the write token and `COVEY_TINKER_ENABLED`; `covey:token` command; the covey manifest `covey/laravel.json` with the subject `laravel:tinker` for writes.
- `GET /covey/v1/logs` lists the log files, `POST /covey/v1/logs` searches them: records (header plus stack trace) from the end backwards, with `grep`, `level`/`min_level`, `since`/`until`, `tail`, `context` and `lines`; a scan budget, redaction patterns and the package's own audit lines hidden. The manifest action `logs` carries the subject `laravel:logs`. (#1)
