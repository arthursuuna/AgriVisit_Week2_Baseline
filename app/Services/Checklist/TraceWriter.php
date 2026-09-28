<?php

namespace App\Services\Checklist;

/**
 * Writes one JSON trace per drafting attempt.
 *
 * These files are the evidence base for the prompt evaluation table and, from
 * Week 5, for the audit log the supervisor reviews. Written to
 * storage/app/traces and copied into evidence/traces/ for submission.
 */
class TraceWriter
{
    public function __construct(private readonly string $path)
    {
    }

    public function write(string $traceId, array $payload): void
    {
        if (! is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        $record = array_merge([
            'trace_id'   => $traceId,
            'recorded_at' => date('c'),
        ], $payload);

        file_put_contents(
            sprintf('%s/%s.json', rtrim($this->path, '/'), $traceId),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
