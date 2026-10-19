<?php

namespace App\Services\AgentTask;

use App\Models\AgentRunRecord;
use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentStep;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Saves and restores agent runs: the database row is working state, the trace
 * file is the audit record. Nothing here reads or writes a trace.
 *
 * A save is a move of the stored run, so it is checked against RunState: a
 * closed row is never written again, and a row waiting on a call takes exactly
 * one decision (the next segment), whoever calls save().
 */
class AgentRunStore
{
    public function save(AgentRun $run, ?array $approval = null): AgentRunRecord
    {
        if (($stored = $this->find($run->runId)) !== null) {
            $this->checkMove($stored, $run);
        }

        $tokens = $run->totalTokens();

        return AgentRunRecord::updateOrCreate(['run_id' => $run->runId], [
            'goal'                 => $run->goal,
            'officer_id'           => (string) config('agrivisit.officer.id'),
            'status'               => $run->status,
            'stop_reason'          => $run->isStopped() ? $run->status : null,
            'prompt_version'       => $run->promptVersion,
            'model'                => $run->model,
            'iterations'           => $run->iterations,
            'consecutive_failures' => $run->consecutiveFailures,
            'limits'               => $run->limits,
            'messages'             => $run->messages(),
            'steps'                => array_map(fn (AgentStep $s) => $s->toArray(), $run->steps),
            'signatures'           => $run->signatures(),
            'pending'              => $run->pending,
            'system_prompt'        => $run->systemPrompt,
            'declarations'         => $run->declarations,
            'summary'              => $run->summary,
            'input_tokens'         => $tokens['input'],
            'output_tokens'        => $tokens['output'],
            'active_seconds'       => round($run->elapsedSeconds(), 3),
            'segment'              => $run->segment,
            'resumed_from'         => $run->resumedFrom,
            'trace_files'          => $run->traceFiles,
            'approval'             => $approval,
            'started_at'           => Carbon::parse($run->startedAt),
            'ended_at'             => $run->endedAt ? Carbon::parse($run->endedAt) : null,
        ]);
    }

    /** @throws IllegalRunTransition */
    private function checkMove(AgentRunRecord $stored, AgentRun $run): void
    {
        $from = RunState::forStatus($stored->status);

        if ($from === RunState::CLOSED) {
            RunState::check($from, $run->status, $run->runId);
        }

        if ($from === RunState::AWAITING_APPROVAL && $run->segment !== $stored->segment + 1) {
            throw IllegalRunTransition::between($run->runId, $from, $run->status,
                "One decision per held call: the stored run waits at segment {$stored->segment}; this save is segment {$run->segment}.");
        }
    }

    public function find(string $runId): ?AgentRunRecord
    {
        return AgentRunRecord::where('run_id', $runId)->first();
    }

    /** @param Closure(): float|null $clock */
    public function restore(AgentRunRecord $record, ?Closure $clock = null): AgentRun
    {
        return AgentRun::restore([
            'run_id'               => $record->run_id,
            'goal'                 => $record->goal,
            'limits'               => $record->limits,
            'started_at'           => $record->started_at->toIso8601String(),
            'ended_at'             => $record->ended_at?->toIso8601String(),
            'active_seconds'       => $record->active_seconds,
            'iterations'           => $record->iterations,
            'consecutive_failures' => $record->consecutive_failures,
            'status'               => $record->status,
            'summary'              => $record->summary,
            'pending'              => $record->pending,
            'system_prompt'        => $record->system_prompt,
            'declarations'         => $record->declarations,
            'prompt_version'       => $record->prompt_version,
            'model'                => $record->model,
            'segment'              => $record->segment,
            'resumed_from'         => $record->resumed_from,
            'trace_files'          => $record->trace_files,
            'messages'             => $record->messages,
            'signatures'           => $record->signatures,
            'steps'                => $record->steps,
        ], $clock ?? fn (): float => microtime(true));
    }
}
