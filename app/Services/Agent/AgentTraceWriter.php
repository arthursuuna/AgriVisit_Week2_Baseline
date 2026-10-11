<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * One JSON file per run segment, written on every stop, including a guard
 * refusal and a model error, so every run leaves a record.
 *
 * Traces are evidence, so they are write-once: a file that already exists is
 * never overwritten. A resumed run writes a new segment file
 * ({run_id}.part2.json) naming the previous one in resumed_from. Working
 * state lives in the database (AgentRunStore), never in these files.
 */
class AgentTraceWriter
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return string the full path written */
    public function write(AgentRun $run): string
    {
        if (! is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        $name = $run->traceFileName();
        $file = $this->path . DIRECTORY_SEPARATOR . $name;

        // 'x' fails if the file exists: an existing trace is never rewritten.
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            throw new RuntimeException("Trace {$name} already exists; traces are never overwritten.");
        }

        $run->traceFiles[] = $name;

        fwrite($handle, json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fclose($handle);

        return $file;
    }
}
