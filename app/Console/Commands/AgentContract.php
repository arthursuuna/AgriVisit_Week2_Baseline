<?php

namespace App\Console\Commands;

use App\Services\AgentTask\AgentTaskContract;
use Illuminate\Console\Command;

/**
 * Prints the Agent Task Contract.
 *
 *   php artisan agrivisit:agent-contract
 *   php artisan agrivisit:agent-contract --json
 *
 * Generated from config, the tool registry and the agent prompt, like the
 * Week 4 tool catalogue, so it cannot drift from the running system.
 */
class AgentContract extends Command
{
    protected $signature = 'agrivisit:agent-contract
                            {--json : Print the contract as JSON}';

    protected $description = 'Print the Agent Task Contract, generated from config, the tool registry and the prompt';

    public function handle(AgentTaskContract $contract): int
    {
        $this->line($this->option('json')
            ? json_encode($contract->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $contract->toMarkdown());

        return self::SUCCESS;
    }
}
