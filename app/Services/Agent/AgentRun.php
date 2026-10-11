<?php

namespace App\Services\Agent;

use Closure;
use Illuminate\Support\Str;
use stdClass;

/**
 * One run of the bounded loop: its conversation, its steps and how it ended.
 *
 * A run that stopped on a limit is still a complete run. Nothing about a stop
 * is an exception, so partial work is always readable.
 *
 * Since Week 5 Phase 3 a run can be suspended for approval and resumed later,
 * so it is persisted (AgentRunStore) and restored with restore(). A resumed
 * run is a new segment: it inherits iterations, failures, tokens and active
 * time, and its trace is a new file naming the previous one.
 */
final class AgentRun
{
    public const RUNNING = 'running';

    public readonly string $runId;

    public readonly string $startedAt;

    public ?string $endedAt = null;

    public int $iterations = 0;

    public int $consecutiveFailures = 0;

    /** 'running' while in flight, otherwise a StopReason constant. */
    public string $status = self::RUNNING;

    public string $summary = '';

    /** @var list<AgentStep> */
    public array $steps = [];

    /** Set only on AWAITING_APPROVAL: tool, arguments, tool_use_id, summary. */
    public ?array $pending = null;

    /** The exact system prompt and declarations, so a resume sends the same ones. */
    public string $systemPrompt = '';

    public array $declarations = [];

    public string $promptVersion = '';

    public string $model = '';

    /** 1 for the first part of a run, 2 after the first resume, and so on. */
    public int $segment = 1;

    /** The trace file of the previous segment, set on resume. */
    public ?string $resumedFrom = null;

    /** @var list<string> Trace file names written for this run, oldest first. */
    public array $traceFiles = [];

    /** @var array<string, true> */
    private array $signatures = [];

    /** @var list<array> */
    private array $messages = [];

    /** Active seconds from earlier segments. */
    private float $activeBefore;

    /** When this segment's clock started. */
    private float $segmentStartedAt;

    /** Frozen at a stop, so time spent waiting for an officer is never counted. */
    private ?float $activeAtStop = null;

    /** @param Closure(): float $clock seconds, injectable so the runtime budget is testable */
    public function __construct(
        public readonly string $goal,
        public readonly array $limits,
        private readonly Closure $clock,
        ?string $runId = null,
        ?string $startedAt = null,
        float $activeSeconds = 0.0,
    ) {
        $this->runId            = $runId ?? now()->format('Ymd-His') . '-' . Str::lower(Str::random(6));
        $this->startedAt        = $startedAt ?? now()->toIso8601String();
        $this->activeBefore     = $activeSeconds;
        $this->segmentStartedAt = ($this->clock)();
    }

    /**
     * Rebuilds a suspended run from its stored state (AgentRunStore::toState()).
     * The clock restarts at zero for the new segment; earlier active time carries.
     */
    public static function restore(array $state, Closure $clock): self
    {
        $run = new self($state['goal'], $state['limits'], $clock,
            $state['run_id'], $state['started_at'], (float) $state['active_seconds']);

        $run->endedAt             = $state['ended_at'];
        $run->iterations          = (int) $state['iterations'];
        $run->consecutiveFailures = (int) $state['consecutive_failures'];
        $run->status              = $state['status'];
        $run->summary             = (string) $state['summary'];
        $run->pending             = $state['pending'];
        $run->systemPrompt        = (string) $state['system_prompt'];
        $run->declarations        = $state['declarations'];
        $run->promptVersion       = (string) $state['prompt_version'];
        $run->model               = (string) $state['model'];
        $run->segment             = (int) $state['segment'];
        $run->resumedFrom         = $state['resumed_from'];
        $run->traceFiles          = $state['trace_files'];
        $run->messages            = $state['messages'];
        $run->signatures          = array_fill_keys($state['signatures'], true);
        $run->steps               = array_map(fn (array $s) => new AgentStep(
            $s['iteration'], $s['plan'], $s['tool'], $s['arguments'], $s['outcome'],
            (float) $s['duration_ms'], $s['input_tokens'], $s['output_tokens'],
            $s['tool_calls_proposed'] ?? 0, $s['message'] ?? null,
        ), $state['steps']);
        $run->activeAtStop        = $run->isStopped() ? (float) $state['active_seconds'] : null;

        return $run;
    }

    /**
     * Opens the next segment of a suspended run: running again, the clock
     * restarted, earlier active time carried, and the previous trace named.
     */
    public function beginSegment(): void
    {
        $this->activeBefore     = $this->elapsedSeconds();
        $this->activeAtStop     = null;
        $this->segmentStartedAt = ($this->clock)();
        $this->resumedFrom      = end($this->traceFiles) ?: null;
        $this->segment++;
        $this->status           = self::RUNNING;
        $this->endedAt          = null;
    }

    /**
     * The conversation, for the next model call. A tool_use with no arguments
     * decodes to [] in PHP and would be re-encoded as a JSON list, which the
     * API rejects; it is sent as an empty object.
     */
    public function history(): array
    {
        return array_map(function (array $message) {
            if ($message['role'] !== 'assistant' || ! is_array($message['content'])) {
                return $message;
            }

            $message['content'] = array_map(fn ($block) => ($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []
                ? ['input' => new stdClass()] + $block
                : $block, $message['content']);

            return $message;
        }, $this->messages);
    }

    /** The stored conversation, exactly as kept (for persistence and traces). */
    public function messages(): array
    {
        return $this->messages;
    }

    public function pushUserTurn(string $text): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $text];
    }

    /** The assistant's content array, exactly as received. Never rebuilt. */
    public function pushAssistantTurn(array $rawContent): void
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $rawContent];
    }

    /**
     * Tool results, keyed by tool_use_id. Claude matches a result to its call by
     * id, not by tool name, and every tool_use in the previous assistant turn
     * must have a result in this one.
     *
     * @param list<array{tool_use_id: string, content: string, is_error: bool}> $results
     */
    public function pushToolResultTurn(array $results): void
    {
        $this->messages[] = ['role' => 'user', 'content' => array_map(fn (array $r) => [
            'type'        => 'tool_result',
            'tool_use_id' => $r['tool_use_id'],
            'content'     => $r['content'],
            'is_error'    => $r['is_error'],
        ], $results)];
    }

    /** @return list<string> tool_use ids in the last assistant turn, in order. */
    public function lastToolUseIds(): array
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['role'] === 'assistant' && is_array($this->messages[$i]['content'])) {
                return array_values(array_map(fn ($b) => $b['id'], array_filter(
                    $this->messages[$i]['content'], fn ($b) => ($b['type'] ?? null) === 'tool_use'
                )));
            }
        }

        return [];
    }

    public function hasSeenSignature(string $signature): bool
    {
        return isset($this->signatures[$signature]);
    }

    public function recordSignature(string $signature): void
    {
        $this->signatures[$signature] = true;
    }

    /** @return list<string> */
    public function signatures(): array
    {
        return array_keys($this->signatures);
    }

    public function addStep(AgentStep $step): void
    {
        $this->steps[] = $step;
    }

    /** Active seconds: model and tool time across all segments, never time spent waiting for a decision. */
    public function elapsedSeconds(): float
    {
        return $this->activeAtStop ?? $this->activeBefore + (($this->clock)() - $this->segmentStartedAt);
    }

    public function stop(string $reason, string $summary): self
    {
        $this->activeAtStop = $this->elapsedSeconds();
        $this->status       = $reason;
        $this->summary      = $summary;
        $this->endedAt      = now()->toIso8601String();

        return $this;
    }

    public function isStopped(): bool
    {
        return $this->status !== self::RUNNING;
    }

    public function totalTokens(): array
    {
        return [
            'input'  => array_sum(array_map(fn (AgentStep $s) => $s->inputTokens, $this->steps)),
            'output' => array_sum(array_map(fn (AgentStep $s) => $s->outputTokens, $this->steps)),
        ];
    }

    /** The trace file name for the current segment. */
    public function traceFileName(): string
    {
        return $this->segment === 1 ? "{$this->runId}.json" : "{$this->runId}.part{$this->segment}.json";
    }

    public function toArray(): array
    {
        return [
            'run_id'               => $this->runId,
            'segment'              => $this->segment,
            'resumed_from'         => $this->resumedFrom,
            'prompt_version'       => $this->promptVersion,
            'model'                => $this->model,
            'goal'                 => $this->goal,
            'limits'               => $this->limits,
            'started_at'           => $this->startedAt,
            'ended_at'             => $this->endedAt,
            'active_seconds'       => round($this->elapsedSeconds(), 3),
            'iterations'           => $this->iterations,
            'consecutive_failures' => $this->consecutiveFailures,
            'status'               => $this->status,
            'stop_reason'          => $this->isStopped() ? $this->status : null,
            'summary'              => $this->summary,
            'total_tokens'         => $this->totalTokens(),
            'steps'                => array_map(fn (AgentStep $s) => $s->toArray(), $this->steps),
            'pending'              => $this->pending,
            'signatures'           => $this->signatures(),
            'system_prompt'        => $this->systemPrompt,
            'messages'             => $this->messages,
        ];
    }
}
