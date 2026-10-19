<?php

namespace App\Console\Commands;

use App\Services\AgentTask\AgentRunStore;
use App\Services\AgentTask\CitationCheck;
use Illuminate\Console\Command;

/**
 * Checks that every memory_ref and chunk_ref an agent run wrote was returned
 * to it by a tool in that run.
 *
 *   php artisan agrivisit:citations 20261019-101500-abc123
 *
 * Exits 1 when any reference is unsupported, so it can gate evidence.
 */
class CheckCitations extends Command
{
    protected $signature = 'agrivisit:citations {run_id : The run to check}';

    protected $description = 'Check that every memory_ref and chunk_ref in a run was returned by a tool in that run';

    public function handle(AgentRunStore $store, CitationCheck $check): int
    {
        $run = $store->find($this->argument('run_id'));

        if ($run === null) {
            $this->error("No run {$this->argument('run_id')}.");

            return self::FAILURE;
        }

        $report = $check->check($run);

        $this->table(['Reference', 'Cited', 'Returned by a tool', 'Unsupported'], array_map(fn (string $kind, array $r) => [
            $kind,
            implode(', ', $r['cited']) ?: '—',
            implode(', ', $r['returned']) ?: '—',
            implode(', ', $r['unsupported']) ?: 'none',
        ], array_keys($report), $report));

        if (! $check->passes($report)) {
            $this->error('Unsupported references: the agent cited something no tool returned in this run.');

            return self::FAILURE;
        }

        $this->info('Every cited reference was returned by a tool in this run.');

        return self::SUCCESS;
    }
}
