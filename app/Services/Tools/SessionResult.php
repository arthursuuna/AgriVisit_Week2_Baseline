<?php

namespace App\Services\Tools;

/**
 * Outcome of one ToolSession request.
 *
 * $pending is set only when a write tool is held for approval. It carries the
 * exact tool and arguments the invoker returned plus the model turn that
 * proposed them, so approval replays the same action and the conversation can
 * be resumed without asking the model again.
 */
final class SessionResult
{
    public const ANSWERED          = 'answered';
    public const REFUSED           = 'refused';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const TOO_MANY_CALLS    = 'too_many_calls';
    public const MODEL_ERROR       = 'model_error';

    /**
     * @param list<array{turn: int, input_tokens: int, output_tokens: int, thinking_tokens: int}> $tokens
     */
    public function __construct(
        public readonly string $status,
        public readonly string $text,
        public readonly string $sessionId,
        public readonly ?string $toolName = null,
        public readonly ?array $toolArguments = null,
        public readonly ?ToolResult $toolResult = null,
        public readonly ?array $pending = null,
        public readonly array $tokens = [],
        public readonly ?string $refusalCategory = null,
    ) {
    }

    public function totalTokens(): array
    {
        return [
            'input_tokens'    => array_sum(array_column($this->tokens, 'input_tokens')),
            'output_tokens'   => array_sum(array_column($this->tokens, 'output_tokens')),
            'thinking_tokens' => array_sum(array_column($this->tokens, 'thinking_tokens')),
        ];
    }

    public function modelCalls(): int
    {
        return count($this->tokens);
    }
}
