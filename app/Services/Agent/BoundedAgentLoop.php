<?php

namespace App\Services\Agent;

use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Llm\FunctionCalling\ModelReply;
use App\Services\Llm\FunctionCalling\ToolCall;
use App\Services\Llm\LlmException;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolResult;
use Closure;
use LogicException;

/**
 * The bounded agent loop (Week 5).
 *
 * Design rules, each enforced here:
 *   1. Bounded means enumerable: every exit is a StopReason constant.
 *   2. The loop never approves: invoke() is always called without approval,
 *      and nothing in this class can pass it. An approved call is executed by
 *      the Week 4 approval flow, which hands the result to resumeApproved().
 *   3. One action per iteration: only the first tool_use is acted on.
 *   4. A repeat is not progress: a repeated call signature stops the run.
 *   5. Partial work survives a stop: every stop returns a complete AgentRun.
 *   6. Every iteration is traced.
 *   7. A refusal ends the run, whether the guard fired on the goal or on a
 *      query the agent composed (Phase 3).
 *   8. A resumed run inherits its budget: iterations, failures and tokens
 *      carry over; only the clock pauses while the officer decides.
 *
 * Declarations and the system prompt are passed in so the loop is testable
 * without the real catalogue; they are kept on the run so a resume sends the
 * same ones.
 */
class BoundedAgentLoop
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RestrictedTopicGuard $guard,
        private readonly AgentModelClient $client,
        private readonly ToolInvoker $invoker,
        private readonly AgentTraceWriter $traces,
        private readonly array $limits,
        ?Closure $clock = null,
        private readonly string $model = '',
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function run(string $goal, array $declarations, string $systemPrompt, string $promptVersion = ''): AgentRun
    {
        $run = new AgentRun($goal, $this->limits, $this->clock);
        $run->declarations  = $declarations;
        $run->systemPrompt  = $systemPrompt;
        $run->promptVersion = $promptVersion;
        $run->model         = $this->model;

        if (($category = $this->guard->screenRequest($goal)) !== null) {
            return $this->finish($run, StopReason::GUARD_REFUSED, $this->guard->refusalFor($category));
        }

        $run->pushUserTurn($goal);

        return $this->iterate($run);
    }

    /**
     * Continues a suspended run after the officer approved the pending call.
     *
     * $result is what the approved invocation returned. The Week 4 approval
     * flow ran it (with approval, through ToolInvoker), so validation and
     * business rules still applied: approval authorises, it does not exempt.
     * The result goes back to the model under the same tool_use_id.
     */
    public function resumeApproved(AgentRun $run, ToolResult $result, ?string $approvalRef = null, float $durationMs = 0.0): AgentRun
    {
        $pending = $this->openSegment($run);

        $run->addStep(new AgentStep(
            iteration: $run->iterations,
            plan: 'Officer ' . config('agrivisit.officer.id') . ' approved'
                . ($approvalRef ? " ({$approvalRef})" : '') . '; the call was executed with approval.',
            tool: $pending['tool'],
            arguments: $pending['arguments'],
            outcome: $result->code ?? AgentStep::OK,
            durationMs: $durationMs,
            inputTokens: 0,
            outputTokens: 0,
            message: $result->message,
        ));

        $run->consecutiveFailures = $result->ok ? 0 : $run->consecutiveFailures + 1;

        // The decision travels with the result. Without it the model, told by
        // its prompt that writes need approval outside the conversation, read
        // a completed booking as still pending and told the officer so (Phase 3
        // live run 1).
        $content = json_encode([
            'officer_decision' => 'approved',
            'approval_ref'     => $approvalRef,
            'note'             => $result->ok
                ? 'The officer approved this call and it has been executed; the result below is final.'
                : 'The officer approved this call, but it failed when executed; nothing was written.',
        ] + $result->toArray());

        $run->pushToolResultTurn($this->resultsFor($run, $pending['tool_use_id'], $content, ! $result->ok));

        if ($run->consecutiveFailures >= $run->limits['max_consecutive_failures']) {
            return $this->finish($run, StopReason::REPEATED_FAILURE,
                "Stopped after {$run->consecutiveFailures} tool failures in a row. Last: {$result->code}: {$result->message}");
        }

        return $this->iterate($run);
    }

    /**
     * Ends a suspended run because the officer declined (or the approval
     * expired). The model is told, so the conversation is complete, but it is
     * not called again: a rejected proposal re-planned into a slightly
     * different one would put the officer in a loop of refusing, and the
     * signature check cannot catch that (the 20th is not the 19th).
     */
    public function reject(AgentRun $run, string $message): AgentRun
    {
        $pending = $this->openSegment($run);

        $run->addStep(new AgentStep(
            iteration: $run->iterations,
            plan: 'Officer ' . config('agrivisit.officer.id') . ' did not approve the proposed action.',
            tool: $pending['tool'],
            arguments: $pending['arguments'],
            outcome: StopReason::REJECTED,
            durationMs: 0.0,
            inputTokens: 0,
            outputTokens: 0,
            message: $message,
        ));

        $run->pushToolResultTurn($this->resultsFor($run, $pending['tool_use_id'], $message, true));

        return $this->finish($run, StopReason::REJECTED, $message);
    }

    private function iterate(AgentRun $run): AgentRun
    {
        $limits = $run->limits;

        while (true) {
            if ($run->iterations >= $limits['max_iterations']) {
                return $this->finish($run, StopReason::ITERATION_CAP,
                    "Stopped after {$run->iterations} iterations, the limit. The steps so far are below for the officer to complete.");
            }

            if ($run->elapsedSeconds() >= $limits['max_runtime_seconds']) {
                return $this->finish($run, StopReason::RUNTIME_BUDGET,
                    "Stopped after the {$limits['max_runtime_seconds']} s runtime budget. The steps so far are below.");
            }

            try {
                $reply = $this->client->reply($run->systemPrompt, $run->history(), $run->declarations);
            } catch (LlmException $e) {
                return $this->finish($run, StopReason::MODEL_ERROR, $e->reason());
            }

            $run->iterations++;
            $run->pushAssistantTurn($reply->rawContent());

            $call = $reply->toolCall();

            if ($call === null) {
                $run->addStep($this->step($run, $reply, null, AgentStep::OK, 0.0));

                return $this->finish($run, StopReason::GOAL_REACHED, $reply->text());
            }

            $signature = $call->signature();

            if ($run->hasSeenSignature($signature)) {
                $run->addStep($this->step($run, $reply, $call, StopReason::NO_PROGRESS, 0.0,
                    'Not invoked: the same call was already made in this run.'));

                return $this->finish($run, StopReason::NO_PROGRESS,
                    "Stopped: '{$call->name}' was proposed again with the same arguments, which cannot return anything new.");
            }

            $run->recordSignature($signature);

            // Rule 2: no approval argument, ever.
            $started = hrtime(true);
            $result  = $this->invoker->invoke($call->name, $call->arguments);
            $ms      = round((hrtime(true) - $started) / 1e6, 2);

            $run->addStep($this->step($run, $reply, $call, $result->code ?? AgentStep::OK, $ms, $result->message));

            if ($result->code === ToolResult::APPROVAL_REQUIRED) {
                $run->pending = [
                    'tool'        => $call->name,
                    'arguments'   => $call->arguments,
                    'tool_use_id' => $call->id,
                    'summary'     => $result->message,
                ];

                return $this->finish($run, StopReason::AWAITING_APPROVAL,
                    'Waiting for officer approval: ' . $result->message);
            }

            $run->pushToolResultTurn($this->results($reply, $call, $result));

            // Rule 7. Appended first (the API rejects a tool_use without a
            // result, and the trace must show what the agent was told), then
            // stopped. The no-progress check cannot do this job: signatures are
            // sha1(name + sorted arguments), so two differently worded queries on
            // the same refused topic have different signatures. That check is
            // syntactic; the Phase 2 reroute was semantic.
            if ($result->code === ToolResult::GUARD_REFUSED) {
                return $this->finish($run, StopReason::GUARD_REFUSED, (string) $result->message);
            }

            $run->consecutiveFailures = $result->ok ? 0 : $run->consecutiveFailures + 1;

            if ($run->consecutiveFailures >= $limits['max_consecutive_failures']) {
                return $this->finish($run, StopReason::REPEATED_FAILURE,
                    "Stopped after {$run->consecutiveFailures} tool failures in a row. Last: {$result->code}: {$result->message}");
            }
        }
    }

    /** @return array the pending call, after checking the run is suspended on one */
    private function openSegment(AgentRun $run): array
    {
        if ($run->status !== StopReason::AWAITING_APPROVAL || $run->pending === null) {
            throw new LogicException("Run {$run->runId} is not awaiting approval (status {$run->status}).");
        }

        $pending      = $run->pending;
        $run->pending = null;
        $run->beginSegment();

        return $pending;
    }

    /**
     * A result for every tool_use in the suspended assistant turn: the real
     * one for the pending call, a refusal for any extra.
     */
    private function resultsFor(AgentRun $run, string $toolUseId, string $content, bool $isError): array
    {
        $results = [];

        foreach ($run->lastToolUseIds() as $id) {
            $results[] = $id === $toolUseId
                ? ['tool_use_id' => $id, 'content' => $content, 'is_error' => $isError]
                : ['tool_use_id' => $id, 'content' => 'Not executed: one action per iteration.', 'is_error' => true];
        }

        return $results;
    }

    /**
     * The result for the call that ran, plus a refusal for any further tool_use
     * blocks: the API rejects a turn that leaves a tool_use without a result.
     */
    private function results(ModelReply $reply, ToolCall $ran, ToolResult $result): array
    {
        $results = [[
            'tool_use_id' => $ran->id,
            'content'     => json_encode($result->toArray()),
            'is_error'    => ! $result->ok,
        ]];

        foreach (array_slice($reply->toolCalls(), 1) as $extra) {
            $results[] = [
                'tool_use_id' => $extra->id,
                'content'     => 'Not executed: one action per iteration. Propose it again if it is still needed.',
                'is_error'    => true,
            ];
        }

        return $results;
    }

    private function step(AgentRun $run, ModelReply $reply, ?ToolCall $call, string $outcome, float $ms, ?string $message = null): AgentStep
    {
        return new AgentStep(
            iteration: $run->iterations,
            plan: $reply->plan(),
            tool: $call?->name,
            arguments: $call?->arguments ?? [],
            outcome: $outcome,
            durationMs: $ms,
            inputTokens: $reply->inputTokens(),
            outputTokens: $reply->outputTokens(),
            toolCallsProposed: $reply->toolCallCount(),
            message: $message,
        );
    }

    private function finish(AgentRun $run, string $reason, string $summary): AgentRun
    {
        $run->stop($reason, $summary);
        $this->traces->write($run);

        return $run;
    }
}
