<?php

namespace Covey\Laravel;

// Reads a Laravel log the way a person on call does: from the end, in
// records rather than lines, stopping as soon as it has what was asked for.
//
// A Laravel log record is a header line — `[2026-10-05 12:34:56] production.ERROR: message {context}`
// — followed by the lines of its stack trace, none of which start with a
// bracketed timestamp. A grep that returned single lines would hand the agent
// the middle of a trace with no idea which request it belonged to; this
// reader returns the record, header first, and the first N lines of the
// trace, which is where the frame that matters usually is.
//
// The file is read backwards in chunks. The scan ends when `tail` matching
// records are in hand, when `since` is passed, or when a byte budget is
// spent — whichever comes first. A log that is two gigabytes long is a fact
// of production, and a request that reads all of it is an incident.
class LogReader
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ][0-9:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+(?:([\w-]+)\.)?([A-Za-z]+):\s?(.*)$/';

    public const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    private int $scanned = 0;

    private bool $budgetSpent = false;

    public function __construct(
        private readonly int $chunkSize = 65536,
        private readonly int $maxScanBytes = 32 * 1024 * 1024,
    ) {
    }

    // bytesScanned reports how far the reader got, across every file it was
    // handed — the response says so, because "no match" means something
    // different when the scan stopped at its budget.
    public function bytesScanned(): int
    {
        return $this->scanned;
    }

    public function budgetSpent(): bool
    {
        return $this->budgetSpent;
    }

    // records yields the records of one file, newest first, as
    // ['at' => ?string, 'level' => ?string, 'message' => ?string, 'lines' => string[]].
    // Records without a recognisable header (a custom formatter, or the tail
    // of a trace that was cut at the start of the file) carry nulls.
    public function records(string $path): \Generator
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        try {
            $pending = []; // continuation lines seen so far, newest first
            foreach ($this->linesBackwards($fh) as $line) {
                if (preg_match(self::HEADER, $line, $m)) {
                    yield [
                        'at' => $m[1],
                        'level' => strtolower($m[3]),
                        'message' => $m[4],
                        'lines' => array_merge([$line], array_reverse($pending)),
                    ];
                    $pending = [];
                } else {
                    $pending[] = $line;
                }
            }
            if ($pending !== []) {
                yield ['at' => null, 'level' => null, 'message' => null, 'lines' => array_reverse($pending)];
            }
        } finally {
            fclose($fh);
        }
    }

    // linesBackwards reads the file from its end in chunks and yields whole
    // lines, last line first, without ever holding more than a chunk and the
    // longest line in memory.
    private function linesBackwards($fh): \Generator
    {
        fseek($fh, 0, SEEK_END);
        $pos = ftell($fh);
        $carry = '';
        while ($pos > 0) {
            if ($this->scanned >= $this->maxScanBytes) {
                $this->budgetSpent = true;
                // What is in carry is the start of a line whose beginning we
                // did not read; give it up rather than hand out half a line.
                return;
            }
            $size = min($this->chunkSize, $pos, $this->maxScanBytes - $this->scanned);
            $pos -= $size;
            fseek($fh, $pos);
            $chunk = fread($fh, $size);
            if ($chunk === false) {
                return;
            }
            $this->scanned += strlen($chunk);
            $buf = $chunk.$carry;
            $parts = explode("\n", $buf);
            // The first part may be the tail of a line that continues in the
            // previous chunk — unless we are at the start of the file.
            $carry = array_shift($parts);
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $line = rtrim($parts[$i], "\r");
                if ($line !== '') {
                    yield $line;
                }
            }
        }
        $line = rtrim($carry, "\r");
        if ($line !== '') {
            yield $line;
        }
    }
}
