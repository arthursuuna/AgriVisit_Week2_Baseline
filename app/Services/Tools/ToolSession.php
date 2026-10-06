<?php

namespace App\Services\Tools;

use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Llm\FunctionCalling\ModelTurn;
use App\Services\Llm\FunctionCalling\SchemaTranslator;
use App\Services\Llm\LlmException;
use App\Services\Prompts\PromptRepository;
use Illuminate\Support\Str;

/**
 * One natural-language request, at most one tool call, one answer.
 *
 * The model proposes; ToolInvoker decides, exactly as for a call a person
 * makes. Only the call's name and arguments reach the invoker, and approval
 * comes only from $approvedCall, which the model has no way to supply.
 *
 * Single-shot by design: no loop. Re-planning arrives in Week 5.
 */
class ToolSession
{
    public function __construct(
        private readonly RestrictedTopicGuard $guard,
        private readonly SchemaTranslator $translator,
        private readonly ToolRegistry $registry,
        private readonly ToolPolicy $policy,
        private readonly ToolInvoker $invoker,
        private readonly FunctionCallingClient $client,
        private readonly PromptRepository $prompts,
        private readonly ToolCallLog $log,
        private readonly string $promptVersion,
    ) {
    }

    /**
     * @param array|null $approvedCall A SessionResult::$pending the officer approved.
     */
    public function handle(string $request, ?array $approvedCall = null): SessionResult
    {
        $trace = [
            'session_id'        => $approvedCall['session_id'] ?? (string) Str::uuid(),
            'request'           => $request,
            'prompt_version'    => $this->promptVersion,
            'declarations'      => [],
            'proposed_calls'    => [],
            'gate_outcome'      => null,
            'approval_required' => false,
            'approved_call'     => $approvedCall === null ? null
                : ['tool' => $approvedCall['tool'], 'arguments' => $approvedCall['arguments']],
            'turns'             => [],
        ];

        try {
            $result = $approvedCall === null
                ? $this->propose($request, $trace)
                : $this->resume($request, $approvedCall, $trace);
        } catch (LlmException $e) {
            $result = $this->result($trace, SessionResult::MODEL_ERROR,
                'The model could not be reached: ' . $e->getMessage());
        }

        $this->log->record($trace + [
            'status'      => $result->status,
            'tool'        => $result->toolName,
            'reply'       => $result->text,
            'totals'      => $result->totalTokens(),
        ]);

        return $result;
    }

    private function propose(string $request, array &$trace): SessionResult
    {
        // 1. Deterministic screen before any model call.
        if (($category = $this->guard->screenRequest($request)) !== null) {
            return $this->result($trace, SessionResult::REFUSED, $this->guard->refusalFor($category),
                refusalCategory: $category);
        }

        // 2-3. Declarations from the same schemas the gate validates against.
        $declarations = $this->declarations();
        $trace['declarations'] = array_column($declarations, 'name');

        $contents = [$this->userTurn($request)];
        $first    = $this->callModel($contents, $declarations, $trace);

        // 4. No tool needed.
        if ($first->isText()) {
            return $this->result($trace, SessionResult::ANSWERED, $this->screened($first->text));
        }

        // 5. One call per request in this phase.
        if (count($first->calls) > 1) {
            return $this->result($trace, SessionResult::TOO_MANY_CALLS, sprintf(
                'The model proposed %d tool calls (%s). Only one tool call per request is supported '
                . 'at present, so nothing was run. Ask for one thing at a time.',
                count($first->calls),
                implode(', ', array_column($first->calls, 'name'))
            ));
        }

        // 6. Through the gate, never approved here.
        $call   = $first->calls[0];
        $result = $this->invoker->invoke($call['name'], $call['args']);
        $trace['gate_outcome'] = $result->code ?? 'OK';

        if ($result->code === ToolResult::APPROVAL_REQUIRED && $result->pending !== null) {
            $trace['approval_required'] = true;

            return $this->result($trace, SessionResult::AWAITING_APPROVAL, $result->message,
                tool: $call['name'], args: $call['args'], toolResult: $result,
                pending: [
                    'tool'       => $result->pending['tool'],
                    'arguments'  => $result->pending['arguments'],
                    'summary'    => $result->message,
                    'call_id'    => $call['id'],
                    'model_turn' => $first->content,
                    'session_id' => $trace['session_id'],
                ]);
        }

        // 7. Read results and failures alike go back to the model to explain.
        return $this->explain($contents, $first->content, $call, $result, $declarations, $trace);
    }

    private function resume(string $request, array $approvedCall, array &$trace): SessionResult
    {
        $call = [
            'name' => $approvedCall['tool'],
            'args' => $approvedCall['arguments'],
            'id'   => $approvedCall['call_id'] ?? null,
        ];

        $declarations = $this->declarations();
        $trace['declarations'] = array_column($declarations, 'name');

        // The officer's approval, replaying the arguments the gate returned.
        $result = $this->invoker->invoke($call['name'], $call['args'], true);
        $trace['gate_outcome'] = $result->code ?? 'OK';

        return $this->explain([$this->userTurn($request)], $approvedCall['model_turn'], $call, $result, $declarations, $trace, approved: true);
    }

    private function explain(array $contents, array $modelTurn, array $call, ToolResult $result, array $declarations, array &$trace, bool $approved = false): SessionResult
    {
        // Without this the model, told to say writes await approval, reports a
        // completed booking as still pending.
        $payload  = $approved ? $result->toArray() + ['approved_by_officer' => true] : $result->toArray();
        $response = ['name' => $call['name'], 'response' => $payload];

        if (! empty($call['id'])) {
            $response = ['id' => $call['id']] + $response;
        }

        $contents[] = $modelTurn;
        $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => $response]]];

        $second = $this->callModel($contents, $declarations, $trace);
        $text   = $second->text;

        // Single-shot: a second proposed call is recorded, never run.
        if ($second->hasCalls()) {
            $trace['ignored_second_calls'] = array_map(
                fn (array $c) => ['name' => $c['name'], 'args' => $c['args']], $second->calls);

            if ($text === '') {
                $text = 'The model asked for a further tool call, which is not supported yet. '
                    . 'The result of the first call is shown below.';
            }
        }

        return $this->result($trace, SessionResult::ANSWERED, $this->screened($text),
            tool: $call['name'], args: $call['args'], toolResult: $result);
    }

    private function callModel(array $contents, array $declarations, array &$trace): ModelTurn
    {
        $system = $this->prompts->render('tools', $this->promptVersion, 'system', [
            'TODAY' => now()->toDateString() . ' (' . now()->format('l') . ')',
        ]);

        $turn = $this->client->generate($system, $contents, $declarations);

        $trace['turns'][] = ['turn' => count($trace['turns']) + 1, 'model' => $turn->model] + $turn->usage();

        if (count($trace['turns']) === 1) {
            $trace['proposed_calls'] = array_map(
                fn (array $c) => ['name' => $c['name'], 'args' => $c['args']], $turn->calls);
        }

        return $turn;
    }

    /** Every enabled tool, translated. A disabled tool is not offered. */
    private function declarations(): array
    {
        $enabled = array_filter($this->registry->all(), fn (ToolContract $tool) => $this->policy->allows($tool));

        return array_values(array_map(fn (ToolContract $tool) => $this->translator->toFunctionDeclaration($tool), $enabled));
    }

    /** The same output check the drafter applies: a dose the model volunteered is withheld. */
    private function screened(string $text): string
    {
        $category = $this->guard->screenOutput($text);

        return $category === null ? $text : $this->guard->refusalFor($category);
    }

    private function userTurn(string $request): array
    {
        return ['role' => 'user', 'parts' => [['text' => $request]]];
    }

    private function result(
        array $trace,
        string $status,
        string $text,
        ?string $tool = null,
        ?array $args = null,
        ?ToolResult $toolResult = null,
        ?array $pending = null,
        ?string $refusalCategory = null,
    ): SessionResult {
        return new SessionResult(
            status: $status,
            text: $text,
            sessionId: $trace['session_id'],
            toolName: $tool,
            toolArguments: $args,
            toolResult: $toolResult,
            pending: $pending,
            tokens: array_map(fn (array $t) => [
                'turn'            => $t['turn'],
                'input_tokens'    => $t['input_tokens'],
                'output_tokens'   => $t['output_tokens'],
                'thinking_tokens' => $t['thinking_tokens'],
            ], $trace['turns']),
            refusalCategory: $refusalCategory,
        );
    }
}
