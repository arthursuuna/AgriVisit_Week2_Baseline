<?php

namespace App\Services\AgentTask;

use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentStep;
use App\Services\Agent\StopReason;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use ReflectionClass;

/**
 * The Agent Task Contract, generated rather than written.
 *
 * Every section is read from configuration, the tool registry, the prompt file
 * or the code itself (StopReason's constants and their doc comments, the trace
 * shape of AgentRun and AgentStep). Same rule as the Week 4 tool catalogue:
 * one source, so the contract cannot describe a system that no longer exists.
 */
class AgentTaskContract
{
    public function __construct(
        private readonly AgentToolset $toolset,
        private readonly array $agent,
        private readonly array $officer,
        private readonly array $restricted,
        private readonly string $model,
    ) {
    }

    public function toArray(): array
    {
        $prompt = $this->toolset->promptSections();
        $tools  = $this->toolset->tools();

        return [
            'title'          => 'AgriVisit Agent Task Contract',
            'prompt_version' => $this->toolset->promptVersion(),
            'model'          => $this->model,
            'purpose'        => trim(($prompt['Role'] ?? '') . "\n\n" . ($prompt['Task'] ?? '')),
            'inputs'         => [
                'goal'    => 'One free-text goal from the officer. Screened by the restricted-topic guard before the model is called; a refused goal ends the run as GUARD_REFUSED.',
                'officer' => [
                    'id'   => $this->officer['id'] ?? null,
                    'name' => $this->officer['name'] ?? null,
                    'note' => 'Supplied by system configuration, not authenticated, and not sent to the model. Recorded on approval decisions.',
                ],
            ],
            'tools'             => array_map(fn (ToolContract $t) => [
                'name'              => $t->name(),
                'access'            => $t->isWrite() ? 'write' : 'read',
                'requires_approval' => $t->isWrite(),
                'description'       => $t->description(),
            ], $tools),
            'bounds'            => [
                'max_iterations'           => $this->agent['max_iterations'],
                'max_consecutive_failures' => $this->agent['max_consecutive_failures'],
                'max_runtime_seconds'      => $this->agent['max_runtime_seconds'],
                'runtime_note'             => "{$this->agent['max_runtime_seconds']} s of work, not {$this->agent['max_runtime_seconds']} s of elapsed time: active seconds count model and tool time only. The clock stops while a run waits for the officer and restarts on resume.",
                'resume_note'              => 'A resumed run inherits its budget: iterations, consecutive failures and tokens carry over; it does not get a fresh allowance.',
                'actions_per_iteration'    => 1,
                'note'                     => 'Parallel tool use is disabled in the request (disable_parallel_tool_use), so the iteration cap is a cap on actions taken. A repeated call (same tool, same arguments) is not invoked and ends the run.',
            ],
            'stop_reasons'      => $this->stopReasons(),
            'tool_result_codes' => $this->toolResultCodes(),
            'approval_boundary' => [
                'tools_requiring_approval' => array_values(array_map(
                    fn (ToolContract $t) => $t->name(),
                    array_filter($tools, fn (ToolContract $t) => $t->isWrite())
                )),
                'rule' => 'The loop never grants approval: every tool is invoked without it. A write tool returns APPROVAL_REQUIRED, the run stops as AWAITING_APPROVAL, and nothing is written. Approval is a human act in the Week 4 approval flow (single-use token, expiry, tool_approvals record), which executes the approved call through the gate, so schema and business rules still apply: approval authorises, it does not exempt. On approval the run resumes from where it paused; a rejection or expiry ends it as REJECTED with no re-proposal.',
            ],
            'prohibited' => [
                'prompt_constraints' => $this->bullets($prompt['Constraints'] ?? ''),
                'guarded_categories' => array_keys($this->restricted),
                'guard_applies_to'   => 'the officer\'s goal (before the model is called) and every search_guidance query the agent composes (inside the tool). Either refusal ends the run as GUARD_REFUSED; a refused topic is never reworded and retried.',
            ],
            'evidence' => [
                'trace_path'  => $this->relative($this->agent['trace_path']),
                'file'        => 'One JSON file per run segment, written on every stop and never rewritten: {run_id}.json, then {run_id}.part2.json after a resume (naming the previous file in resumed_from). Working state is kept separately in the agent_runs table.',
                'run_fields'  => array_keys($this->sampleRun()->toArray()),
                'step_fields' => array_keys((new AgentStep(1, '', null, [], AgentStep::OK, 0.0, 0, 0))->toArray()),
            ],
        ];
    }

    public function toMarkdown(): string
    {
        $c = $this->toArray();
        $b = $c['bounds'];

        $lines = [
            "# {$c['title']}",
            '',
            "Generated by `php artisan agrivisit:agent-contract` from config, the tool registry and prompt `agent/{$c['prompt_version']}`. Do not edit by hand. Model: `{$c['model']}`.",
            '',
            '## Purpose', '', $c['purpose'], '',
            '## Inputs', '',
            "- **Goal:** {$c['inputs']['goal']}",
            "- **Officer:** `{$c['inputs']['officer']['id']}` ({$c['inputs']['officer']['name']}). {$c['inputs']['officer']['note']}",
            '',
            '## Tools available', '',
            '| Tool | Access | Approval required | Description |',
            '|---|---|---|---|',
        ];

        foreach ($c['tools'] as $t) {
            $lines[] = "| `{$t['name']}` | {$t['access']} | " . ($t['requires_approval'] ? 'yes' : 'no') . " | {$t['description']} |";
        }

        array_push($lines, '',
            '## Bounds', '',
            "- Max iterations: **{$b['max_iterations']}**",
            "- Max consecutive tool failures: **{$b['max_consecutive_failures']}** (a success resets the count)",
            "- Runtime budget: {$b['runtime_note']}",
            "- {$b['resume_note']}",
            "- Actions per iteration: **{$b['actions_per_iteration']}**. {$b['note']}",
            '',
            '## Stop reasons', '',
            '| Stop reason | Produced when |',
            '|---|---|',
        );

        foreach ($c['stop_reasons'] as $name => $condition) {
            $lines[] = "| `{$name}` | {$condition} |";
        }

        array_push($lines, '', '## Tool result codes', '', '| Code | Meaning |', '|---|---|');

        foreach ($c['tool_result_codes'] as $code => $meaning) {
            $lines[] = "| `{$code}` | {$meaning} |";
        }

        array_push($lines, '',
            '## Approval boundary', '',
            '- Tools requiring approval: ' . implode(', ', array_map(fn ($n) => "`{$n}`", $c['approval_boundary']['tools_requiring_approval'])),
            "- {$c['approval_boundary']['rule']}",
            '',
            '## Prohibited', '',
        );

        foreach ($c['prohibited']['prompt_constraints'] as $rule) {
            $lines[] = "- {$rule}";
        }

        array_push($lines,
            '- Restricted-topic guard categories: ' . implode(', ', $c['prohibited']['guarded_categories']) . '. Applied to ' . $c['prohibited']['guard_applies_to'],
            '',
            '## Evidence', '',
            "- Location: `{$c['evidence']['trace_path']}`",
            "- {$c['evidence']['file']}",
            '- Run fields: ' . implode(', ', array_map(fn ($f) => "`{$f}`", $c['evidence']['run_fields'])),
            '- Step fields: ' . implode(', ', array_map(fn ($f) => "`{$f}`", $c['evidence']['step_fields'])),
            '',
        );

        return implode("\n", $lines);
    }

    /**
     * Every StopReason constant with its doc comment, plus the configured value
     * where the condition depends on one. Read by reflection, so a stop reason
     * added later appears here without anyone remembering to add it.
     *
     * @return array<string, string>
     */
    private function stopReasons(): array
    {
        $values = [
            StopReason::ITERATION_CAP    => " Limit: {$this->agent['max_iterations']}.",
            StopReason::REPEATED_FAILURE => " Limit: {$this->agent['max_consecutive_failures']}.",
            StopReason::RUNTIME_BUDGET   => " Limit: {$this->agent['max_runtime_seconds']} s.",
        ];

        $reasons = [];

        foreach ((new ReflectionClass(StopReason::class))->getReflectionConstants() as $constant) {
            $doc = trim(preg_replace('#^\s*/\*\*|\*/\s*$|^\s*\*\s?#m', '', (string) $constant->getDocComment()));
            $reasons[$constant->getName()] = preg_replace('/\s+/', ' ', $doc) . ($values[$constant->getValue()] ?? '');
        }

        return $reasons;
    }

    /**
     * Every ToolResult code, read by reflection like the stop reasons, with a
     * meaning for each. A code without a meaning here is listed as such, so a
     * new code cannot silently go undocumented.
     *
     * @return array<string, string>
     */
    private function toolResultCodes(): array
    {
        $meanings = [
            'TOOL_NOT_FOUND'      => 'The named tool is not registered (not on the allow-list).',
            'INVALID_ARGUMENTS'   => 'Arguments failed the schema or a business rule (e.g. a past date). Also on approved calls.',
            'UNAUTHORIZED'        => 'The tool is registered but not enabled.',
            'APPROVAL_REQUIRED'   => 'A write tool held for the officer; nothing written. Ends the run as AWAITING_APPROVAL.',
            'RESOURCE_NOT_FOUND'  => 'The subject (farm, visit) does not exist.',
            'SERVICE_UNAVAILABLE' => 'The tool or its dependency failed.',
            'UNEXPECTED_RESPONSE' => 'The tool returned data that does not match its output schema; data discarded.',
            'GUARD_REFUSED'       => 'Well-formed arguments, refused topic (restricted-topic guard on an agent-composed query). Ends the run as GUARD_REFUSED.',
        ];

        $codes = [];

        foreach ((new ReflectionClass(ToolResult::class))->getConstants() as $code) {
            $codes[$code] = $meanings[$code] ?? '(no meaning documented)';
        }

        return $codes;
    }

    /** @return list<string> */
    private function bullets(string $markdown): array
    {
        preg_match_all('/^- +(.+)$/m', $markdown, $m);

        return $m[1];
    }

    /** Project-relative, so the contract reads the same on every machine. */
    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function sampleRun(): AgentRun
    {
        return new AgentRun('', [], fn (): float => 0.0);
    }
}
