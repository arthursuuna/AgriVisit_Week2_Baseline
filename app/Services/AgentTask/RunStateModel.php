<?php

namespace App\Services\AgentTask;

use Illuminate\Support\Facades\Schema;

/**
 * The run state model, generated rather than written.
 *
 * States, transitions and illegal moves come from RunState; the column list
 * comes from the agent_runs table itself. Same rule as the Task Contract: one
 * source, so the document cannot describe a lifecycle the system no longer has.
 */
class RunStateModel
{
    /** What each agent_runs column means. A column missing here is listed as undocumented. */
    private const COLUMNS = [
        'id'                   => 'Row key. Not used to identify a run.',
        'run_id'               => 'The run\'s identity (unique). Names its trace files and appears in tool_approvals.session_id.',
        'goal'                 => 'The officer\'s goal, as submitted.',
        'officer_id'           => 'The configured officer the run was started for.',
        'status'               => 'The state, as \'running\' or a stop reason. AWAITING_APPROVAL means waiting; every other stop reason means closed. Never stored as \'running\'.',
        'stop_reason'          => 'The stop reason, or null while running. Equal to status once stopped.',
        'prompt_version'       => 'The agent prompt version the run was started with; a resume keeps it.',
        'model'                => 'The model the run was started with; a resume keeps it.',
        'iterations'           => 'Model calls so far, across segments. Counted against max_iterations.',
        'consecutive_failures' => 'Tool failures in a row; a success resets it.',
        'limits'               => 'The run\'s own bounds, fixed at start, so a resume uses them and not today\'s config.',
        'messages'             => 'The conversation exactly as sent and received. A waiting run ends with the assistant turn carrying the held tool_use.',
        'steps'                => 'One entry per iteration, plus the officer\'s decision step on a resume or rejection.',
        'signatures'           => 'Call signatures already made, for the no-progress check.',
        'pending'              => 'The held call (tool, arguments, tool_use_id, summary) while waiting; null otherwise.',
        'system_prompt'        => 'The exact system prompt, so a resume sends the same one.',
        'declarations'         => 'The exact tool declarations, so a resume sends the same ones.',
        'summary'              => 'How the run stopped, in words. An expiry starts with "The approval ".',
        'input_tokens'         => 'Input tokens across all steps.',
        'output_tokens'        => 'Output tokens across all steps.',
        'active_seconds'       => 'Model and tool time across segments. Time waiting for the officer is not counted.',
        'segment'              => 'Which part of the run was saved last: 1, then 2 after the first decision. Each decision on a held call moves it on by exactly one.',
        'resumed_from'         => 'The previous segment\'s trace file, from segment 2 on.',
        'trace_files'          => 'Names of the trace files written for this run, oldest first. Pointers to the record, not state.',
        'approval'             => 'The Week 4 approval panel data (token, summary, preview, expiry) while waiting; null once decided.',
        'started_at'           => 'When the run started.',
        'ended_at'             => 'When the last segment stopped.',
        'created_at'           => 'Laravel bookkeeping.',
        'updated_at'           => 'Laravel bookkeeping.',
    ];

    public function __construct(private readonly string $tracePath)
    {
    }

    public function toArray(): array
    {
        $columns = [];

        foreach (Schema::getColumnListing('agent_runs') as $column) {
            $columns[$column] = self::COLUMNS[$column] ?? '(undocumented)';
        }

        return [
            'title'       => 'AgriVisit Agent Run State Model',
            'states'      => RunState::states(),
            'transitions' => array_map(fn (array $t) => array_diff_key($t, ['reasons' => true]), RunState::transitions()),
            'illegal'     => RunState::illegal(),
            'enforcement' => [
                'AgentRun::begin(), stop() and beginSegment() check every move in memory, before anything on the run changes.',
                'AgentRunStore::save() checks every move of the stored row: a closed row is never written again, and a waiting row takes exactly one decision (the next segment).',
                'AgentRunner::decide() and closeIfLapsed() take a lock on the run and re-read it before moving it, so of two requests moving the same run one wins and the other throws IllegalRunTransition before it consumes a token, calls the model or writes a trace. The lazy expiry logs its loss as a warning; a refused officer decision is logged and shown as already decided.',
            ],
            'state_lives_in' => [
                'table'   => 'agent_runs',
                'columns' => $columns,
            ],
            'not_state' => [
                'traces' => $this->relative($this->tracePath),
                'rule'   => 'The agent_runs table is the state. The trace files are the record: one JSON file per segment ({run_id}.json, then {run_id}.part2.json), written once when a segment stops and never rewritten. They hold much of the same data, but nothing reads a trace back to decide what a run does next; a resume restores from the table. A deleted trace changes no run, and an edited one changes nothing but the evidence.',
            ],
        ];
    }

    public function toMarkdown(): string
    {
        $m = $this->toArray();

        $lines = [
            "# {$m['title']}",
            '',
            'Generated by `php artisan agrivisit:state-model` from `RunState` and the `agent_runs` table. Do not edit by hand.',
            '',
            '## States', '',
            '| State | What is true while it holds |',
            '|---|---|',
        ];

        foreach ($m['states'] as $state => $truth) {
            $lines[] = "| `{$state}` | {$truth} |";
        }

        array_push($lines, '', '`' . RunState::NONE . '` means no state: the run has not started, or has no row yet.', '',
            '## Transitions', '', '| From | To | Trigger | Condition |', '|---|---|---|---|');

        foreach ($m['transitions'] as $t) {
            $lines[] = "| `{$t['from']}` | `{$t['to']}` | {$t['trigger']} | {$t['condition']} |";
        }

        array_push($lines, '', '## Illegal moves', '',
            'Every pair not in the table above. Each throws `IllegalRunTransition`; nothing about the run changes.', '',
            '| Move | Why it is forbidden |', '|---|---|');

        foreach ($m['illegal'] as $pair => $why) {
            $lines[] = "| `{$pair}` | {$why} |";
        }

        array_push($lines, '', '## Enforcement', '');

        foreach ($m['enforcement'] as $rule) {
            $lines[] = "- {$rule}";
        }

        array_push($lines, '', '## Where state lives', '',
            "Table `{$m['state_lives_in']['table']}`, one row per run.", '', '| Column | Meaning |', '|---|---|');

        foreach ($m['state_lives_in']['columns'] as $column => $meaning) {
            $lines[] = "| `{$column}` | {$meaning} |";
        }

        array_push($lines, '', '## What is not state', '',
            "- Trace files, under `{$m['not_state']['traces']}`.",
            "- {$m['not_state']['rule']}",
            '');

        return implode("\n", $lines);
    }

    /** Project-relative, so the document reads the same on every machine. */
    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
