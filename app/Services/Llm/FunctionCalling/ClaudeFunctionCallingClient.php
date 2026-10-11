<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Llm\ClaudeClient;
use App\Services\Llm\LlmException;

/**
 * Claude Messages API with tool declarations: the client the agent loop drives.
 *
 * Shares ClaudeClient's transport (key, version, workspace header, timeout,
 * error mapping). Parallel tool use is disabled deliberately: one action per
 * iteration makes the loop's iteration cap a true cap on actions taken.
 */
class ClaudeFunctionCallingClient implements AgentModelClient
{
    public function __construct(
        private readonly array $config,
        private readonly ClaudeClient $transport,
    ) {
    }

    public function reply(string $system, array $messages, array $declarations): ModelReply
    {
        $payload = $this->transport->basePayload() + [
            'system'   => $system,
            'messages' => $messages,
        ];

        if (! empty($this->config['anthropic']['thinking_display'])) {
            $payload['thinking'] = [
                'type'    => 'adaptive',
                'display' => $this->config['anthropic']['thinking_display'],
            ];
        }

        if ($declarations !== []) {
            $payload['tools']       = $declarations;
            $payload['tool_choice'] = [
                'type'                      => 'auto',
                'disable_parallel_tool_use' => true,
            ];
        }

        $body = $this->transport->send($payload, $latencyMs);

        return $this->parse($body, (int) $latencyMs);
    }

    /** Public for testing the response handling without a network call. */
    public function parse(array $body, int $latencyMs = 0): ModelReply
    {
        $stop = $body['stop_reason'] ?? null;

        if ($stop === 'refusal') {
            throw new LlmException('Model declined the request (refusal'
                . (isset($body['stop_details']['category']) ? ': ' . $body['stop_details']['category'] : '') . ').');
        }

        // A truncated reply may hold a half-written tool call; acting on it, or
        // treating the partial text as a finished answer, would both be wrong.
        if ($stop === 'max_tokens') {
            throw new LlmException('Model output was truncated at the max_tokens limit ('
                . $this->config['max_tokens'] . '). Raise LLM_MAX_TOKENS or lower ANTHROPIC_EFFORT.');
        }

        $reply = new ModelReply(
            content: is_array($body['content'] ?? null) ? $body['content'] : [],
            stopReason: $stop,
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            model: (string) ($body['model'] ?? $this->config['model']),
            latencyMs: $latencyMs,
        );

        if (! $reply->hasToolCall() && $reply->text() === '') {
            throw new LlmException('Model returned neither text nor a tool call'
                . ($stop ? " (stop_reason {$stop})." : '.'));
        }

        return $reply;
    }
}
