<?php

namespace App\Console\Commands;

use App\Services\AgentTask\RunStateModel;
use Illuminate\Console\Command;

/**
 * Prints the agent run state model.
 *
 *   php artisan agrivisit:state-model
 *   php artisan agrivisit:state-model --json
 *
 * Generated from RunState and the agent_runs table, like the Agent Task
 * Contract, so it cannot drift from the running system.
 */
class StateModel extends Command
{
    protected $signature = 'agrivisit:state-model
                            {--json : Print the state model as JSON}';

    protected $description = 'Print the agent run state model, generated from RunState and the agent_runs table';

    public function handle(): int
    {
        $model = new RunStateModel((string) config('agrivisit.agent.trace_path'));

        $this->line($this->option('json')
            ? json_encode($model->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $model->toMarkdown());

        return self::SUCCESS;
    }
}
