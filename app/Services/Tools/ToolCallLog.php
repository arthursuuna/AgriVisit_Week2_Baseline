<?php

namespace App\Services\Tools;

/**
 * Appends one JSON line per tool invocation, refused or not.
 *
 * One file per day under storage/app/tool-logs. These lines are the raw
 * material for the Phase 4 failure evidence table.
 */
class ToolCallLog
{
    public function __construct(private readonly string $path)
    {
    }

    public function record(array $entry): void
    {
        if (! is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        $line = json_encode(
            array_merge(['recorded_at' => date('c')], $entry),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        file_put_contents($this->file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string, mixed>> today's entries, oldest first */
    public function entries(): array
    {
        if (! is_file($this->file())) {
            return [];
        }

        $lines = file($this->file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(fn (string $line) => json_decode($line, true), $lines);
    }

    private function file(): string
    {
        return sprintf('%s/%s.jsonl', rtrim($this->path, '/\\'), date('Y-m-d'));
    }
}
