<?php

namespace App\Services\Llm\FunctionCalling;

/**
 * One model response: text, proposed calls, or both.
 *
 * $calls is a list so the orchestrator can see when more than one call was
 * proposed. $content is the model's turn exactly as returned, so it can be
 * resent verbatim: Gemini 3 attaches a thoughtSignature to function-call parts
 * and rejects a follow-up turn that drops it.
 */
final class ModelTurn
{
    /**
     * @param list<array{name: string, args: array, id: ?string}> $calls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $calls,
        public readonly array $content,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $thinkingTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly ?string $finishReason = null,
    ) {
    }

    public function hasCalls(): bool
    {
        return $this->calls !== [];
    }

    public function isText(): bool
    {
        return ! $this->hasCalls();
    }

    public function usage(): array
    {
        return [
            'input_tokens'    => $this->inputTokens,
            'output_tokens'   => $this->outputTokens,
            'thinking_tokens' => $this->thinkingTokens,
            'latency_ms'      => $this->latencyMs,
            'finish_reason'   => $this->finishReason,
        ];
    }
}
