<?php

namespace Covey\Laravel\Http\Controllers;

use Covey\Laravel\Audit;
use Covey\Laravel\LogReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The application's log, read the way a person on call reads it: the last
// records, or the records around a request id, a level, a time — never the
// whole file. Behind the read token on this side; on the covey side it is
// its own guard-rail subject (laravel:logs), because a log holds what a
// column list does not: the request that failed, with whatever was in it.
// Hence the redaction patterns, applied to every line that leaves here.
class LogsController
{
    // files lists what there is to read, newest first. The agent calls this
    // first when it wants one day's file; for a search it does not need to.
    public function files(Request $request): JsonResponse
    {
        if ($off = $this->switchedOff()) {
            return $off;
        }
        $t0 = microtime(true);
        $files = [];
        foreach ($this->candidates() as $path) {
            $files[] = [
                'name' => basename($path),
                'size' => filesize($path),
                'modified' => date(DATE_ATOM, filemtime($path)),
            ];
        }
        Audit::record('log_files', $request->attributes->get('covey.ability'), ['files' => count($files)], $t0);

        return response()->json(['files' => $files]);
    }

    // search returns records, oldest first within the response, chosen from
    // the end of the file backwards. Every parameter is optional: with none,
    // the last `tail` records of the newest file.
    public function search(Request $request): JsonResponse
    {
        if ($off = $this->switchedOff()) {
            return $off;
        }
        $t0 = microtime(true);
        $cfg = (array) config('covey.logs', []);
        $tz = new DateTimeZone(config('app.timezone', 'UTC'));

        $maxTail = max(1, (int) ($cfg['max_entries'] ?? 200));
        $tail = (int) $request->input('tail', 0) ?: min(50, $maxTail);
        $tail = max(1, min($tail, $maxTail));
        $context = max(0, min((int) $request->input('context', 0), 5));
        $maxLines = max(1, (int) ($cfg['max_entry_lines'] ?? 40));
        $lines = (int) $request->input('lines', 0) ?: min(20, $maxLines);
        $lines = max(1, min($lines, $maxLines));

        $grep = (string) $request->input('grep', '');
        $caseSensitive = (bool) $request->input('case_sensitive', false);
        $pattern = null;
        if ($grep !== '') {
            $pattern = $request->boolean('regex')
                ? '/'.str_replace('/', '\/', $grep).'/'.($caseSensitive ? '' : 'i')
                : '/'.preg_quote($grep, '/').'/'.($caseSensitive ? '' : 'i');
            if (@preg_match($pattern, '') === false) {
                return response()->json(['error' => 'grep: '.preg_last_error_msg()], 422);
            }
        }

        $levels = $this->levels($request->input('level'));
        $minLevel = null;
        if (($m = strtolower((string) $request->input('min_level', ''))) !== '') {
            if (! isset(LogReader::LEVELS[$m])) {
                return response()->json(['error' => "min_level: unknown level $m"], 422);
            }
            $minLevel = LogReader::LEVELS[$m];
        }

        try {
            $since = $this->time($request->input('since'), $tz);
            $until = $this->time($request->input('until'), $tz);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $file = $request->input('file');
        $paths = $this->candidates();
        if ($file !== null && $file !== '') {
            $paths = array_values(array_filter($paths, fn ($p) => basename($p) === $file));
            if ($paths === [] || ! $this->safeName((string) $file)) {
                return response()->json(['error' => "no log file named $file"], 404);
            }
        } elseif ($paths !== [] && $grep === '' && $levels === null && $minLevel === null && $since === null && $until === null) {
            // "The last records" means the last records of the newest file,
            // not fifty lines of an old one tacked onto the end.
            $paths = [$paths[0]];
        }

        $reader = new LogReader(maxScanBytes: (int) ($cfg['max_scan_bytes'] ?? 32 * 1024 * 1024));
        $redact = (array) ($cfg['redact'] ?? []);
        $hideOwn = (bool) ($cfg['hide_own'] ?? true);

        $out = [];         // newest first while collecting
        $matches = 0;
        $more = false;     // a match existed beyond tail
        foreach ($paths as $path) {
            if ($since !== null && filemtime($path) < $since->getTimestamp()) {
                continue; // nothing in it can be young enough
            }
            $name = basename($path);
            $after = [];        // the newer neighbours of the next match, newest first
            $pendingBefore = 0; // older neighbours still owed to the last match
            foreach ($reader->records($path) as $rec) {
                if ($hideOwn && $rec['message'] !== null && preg_match('/^covey \{.*"action":/', $rec['message'])) {
                    continue;
                }
                $at = $rec['at'] !== null ? $this->recordTime($rec['at'], $tz) : null;
                if ($until !== null && ($at === null || $at > $until)) {
                    continue;
                }
                if ($since !== null && ($at === null || $at < $since)) {
                    break; // records only get older from here
                }
                $isMatch = $this->matches($rec, $pattern, $levels, $minLevel);
                if ($isMatch && $matches >= $tail) {
                    $more = true;
                    break;
                }
                if ($isMatch) {
                    foreach (array_reverse($after) as $ctx) {
                        $out[] = $ctx;
                    }
                    $after = [];
                    $out[] = $this->shape($rec, $name, $lines, $redact, true);
                    $matches++;
                    $pendingBefore = $context;
                } elseif ($pendingBefore > 0) {
                    $out[] = $this->shape($rec, $name, $lines, $redact, false);
                    $pendingBefore--;
                } elseif ($context > 0) {
                    array_unshift($after, $this->shape($rec, $name, $lines, $redact, false));
                    if (count($after) > $context) {
                        array_pop($after);
                    }
                }
            }
            if ($more || ($matches >= $tail && $pendingBefore === 0)) {
                break;
            }
        }
        $out = array_reverse($out);

        Audit::record('logs', $request->attributes->get('covey.ability'), [
            'file' => $file ?: '*',
            'grep' => $grep !== '' ? $grep : null,
            'matches' => $matches,
            'scanned_bytes' => $reader->bytesScanned(),
        ], $t0);

        return response()->json([
            'entries' => $out,
            'count' => count($out),
            'matches' => $matches,
            'truncated' => $more,
            'tail' => $tail,
            'scanned_bytes' => $reader->bytesScanned(),
            'scan_complete' => ! $reader->budgetSpent(),
            'files' => array_map('basename', $paths),
        ]);
    }

    private function matches(array $rec, ?string $pattern, ?array $levels, ?int $minLevel): bool
    {
        if ($levels !== null && ! in_array($rec['level'], $levels, true)) {
            return false;
        }
        if ($minLevel !== null && ($rec['level'] === null || (LogReader::LEVELS[$rec['level']] ?? -1) < $minLevel)) {
            return false;
        }
        if ($pattern !== null) {
            foreach ($rec['lines'] as $line) {
                if (preg_match($pattern, $line)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function shape(array $rec, string $file, int $maxLines, array $redact, bool $match): array
    {
        $kept = array_slice($rec['lines'], 0, $maxLines);
        $kept = array_map(fn ($l) => $this->redact($l, $redact), $kept);

        return [
            'file' => $file,
            'at' => $rec['at'],
            'level' => $rec['level'],
            'message' => $rec['message'] !== null ? $this->redact($rec['message'], $redact) : null,
            'lines' => $kept,
            'truncated_lines' => max(0, count($rec['lines']) - $maxLines),
            'match' => $match,
        ];
    }

    private function redact(string $line, array $patterns): string
    {
        foreach ($patterns as $pattern => $replacement) {
            $line = preg_replace($pattern, $replacement, $line) ?? $line;
        }

        return $line;
    }

    private function levels(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($list as $l) {
            $l = strtolower(trim((string) $l));
            if ($l !== '') {
                $out[] = $l;
            }
        }

        return $out === [] ? null : $out;
    }

    private function time(mixed $raw, DateTimeZone $tz): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $raw, $tz);
        } catch (\Exception) {
            throw new \InvalidArgumentException("not a time: $raw (use ISO 8601, 'YYYY-MM-DD HH:MM:SS' or a relative form such as '-2 hours')");
        }
    }

    private function recordTime(string $at, DateTimeZone $tz): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($at, $tz);
        } catch (\Exception) {
            return null;
        }
    }

    // candidates lists the readable files, newest first. Only bare file
    // names ever leave or enter here; the directories are the application's
    // decision, not the agent's.
    private function candidates(): array
    {
        $dirs = (array) (config('covey.logs.paths') ?: [storage_path('logs')]);
        $glob = (string) (config('covey.logs.glob') ?: '*.log');
        $seen = [];
        $out = [];
        foreach ($dirs as $dir) {
            $real = realpath($dir);
            if ($real === false || ! is_dir($real)) {
                continue;
            }
            foreach (glob($real.DIRECTORY_SEPARATOR.$glob) ?: [] as $path) {
                if (! is_file($path) || ! is_readable($path) || isset($seen[basename($path)])) {
                    continue;
                }
                $seen[basename($path)] = true;
                $out[] = $path;
            }
        }
        usort($out, fn ($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));

        return $out;
    }

    private function safeName(string $name): bool
    {
        return $name !== '' && ! str_contains($name, '/') && ! str_contains($name, '\\') && ! str_contains($name, "\0") && $name !== '.' && $name !== '..';
    }

    private function switchedOff(): ?JsonResponse
    {
        if (config('covey.logs.enabled', true)) {
            return null;
        }

        return response()->json(['error' => 'logs are switched off in this application (COVEY_LOGS_ENABLED)'], 403);
    }
}
