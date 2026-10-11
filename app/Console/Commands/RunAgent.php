<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentStep;
use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentRunStore;
use App\Services\AgentTask\AgentRunner;
use App\Services\AgentTask\AgentToolset;
use Illuminate\Console\Command;

/**
 * Runs the bounded agent once from the terminal.
 *
 *   php artisan agrivisit:agent "Prepare me for a visit to farm UG-KYA-012"
 *   php artisan agrivisit:agent "..." --max-iterations=2
 *   php artisan agrivisit:agent "..." --dry-run
 *
 * Prints each step's plan above its action, because a list of calls with no
 * reasoning is not evidence of goal-directed behaviour. Never approves
 * anything: a write stops the run, and the officer decides on the run page
 * (/agent/runs/{run_id}) through the Week 4 approval flow. Runs are saved, so
 * a terminal run appears in the browser's run list too.
 */
class RunAgent extends Command
{
    protected $signature = 'agrivisit:agent
                            {goal : What the officer wants done}
                            {--max-iterations= : Override the iteration cap for this run only}
                            {--dry-run : Print the declarations and system prompt without calling the model}';

    protected $description = 'Run the bounded agent on one goal and print its steps and trace';

    public function handle(AgentToolset $toolset, AgentRunner $runner, AgentRunStore $store): int
    {
        $declarations = $toolset->declarations();
        $system       = $toolset->systemPrompt();

        if ($this->option('dry-run')) {
            $this->line('Prompt version: agent/' . $toolset->promptVersion());
            $this->newLine();
            $this->line('--- Tool declarations ---');
            $this->line(json_encode($declarations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->line('--- System prompt ---');
            $this->line($system);
            $this->newLine();
            $this->info('Dry run: the model was not called.');

            return self::SUCCESS;
        }

        if (($cap = $this->option('max-iterations')) !== null && (! ctype_digit((string) $cap) || (int) $cap < 1)) {
            $this->error('--max-iterations must be a whole number of at least 1.');

            return self::INVALID;
        }

        // The override lives in the run's limits and its trace, for this run only.
        $record = $runner->start($this->argument('goal'), $cap === null ? null : (int) $cap);

        $this->printRun($store->restore($record));
        $this->line('Run page: ' . route('agent.runs.show', $record->run_id));

        return self::SUCCESS;
    }

    private function printRun(AgentRun $run): void
    {
        $this->line("Goal: {$run->goal}");
        $this->newLine();

        foreach ($run->steps as $step) {
            $this->printStep($step);
        }

        if ($run->status === StopReason::AWAITING_APPROVAL && $run->pending) {
            $this->warn('Waiting for officer approval. NOTHING HAS BEEN WRITTEN.');
            $this->line("  Tool:      {$run->pending['tool']}");
            $this->line('  Arguments: ' . json_encode($run->pending['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('  The officer approves or rejects on the run page, through the Week 4 approval flow. This command does not approve.');
            $this->newLine();
        }

        $this->line('Summary:');
        $this->line($this->wrap($run->summary !== '' ? $run->summary : '(none)'));
        $this->newLine();

        $tokens = $run->totalTokens();
        $trace  = rtrim(config('agrivisit.agent.trace_path'), '/\\') . DIRECTORY_SEPARATOR . end($run->traceFiles);

        $this->table(['Stop reason', 'Iterations', 'Tokens (in / out)', 'Active seconds', 'Trace'], [[
            $run->status,
            "{$run->iterations} / {$run->limits['max_iterations']}",
            "{$tokens['input']} / {$tokens['output']}",
            number_format($run->elapsedSeconds(), 1),
            $trace,
        ]]);
    }

    private function printStep(AgentStep $step): void
    {
        $this->line($this->wrap($step->plan !== '' ? $step->plan : '(no plan stated)'));

        $this->table(['Iteration', 'Tool', 'Outcome', 'Duration (ms)'], [[
            $step->iteration,
            $step->tool ?? '— (answer)',
            $step->outcome,
            $step->durationMs,
        ]]);

        if ($step->tool !== null) {
            $this->line('  Arguments: ' . json_encode($step->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($step->message !== null && $step->outcome !== AgentStep::OK) {
            $this->line("  {$step->message}");
        }

        $this->newLine();
    }

    private function wrap(string $text): string
    {
        return wordwrap($text, 100);
    }
}
