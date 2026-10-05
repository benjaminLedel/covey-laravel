# Changelog

## 0.1.0 — unreleased

- First cut: `health`, `schema`, `schema/{table}`, `query` behind the read token; `tinker` behind the write token and `COVEY_TINKER_ENABLED`; `covey:token` command; the covey manifest `covey/laravel.json` with the subject `laravel:tinker` for writes.
- `GET /covey/v1/logs` lists the log files, `POST /covey/v1/logs` searches them: records (header plus stack trace) from the end backwards, with `grep`, `level`/`min_level`, `since`/`until`, `tail`, `context` and `lines`; a scan budget, redaction patterns and the package's own audit lines hidden. The manifest action `logs` carries the subject `laravel:logs`. (#1)
