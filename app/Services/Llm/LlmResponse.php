<?php

namespace App\Services\Llm;

/**
 * Immutable result of a single foundation-model call.
 *
 * Carries the operational metadata the Model Selection Note and the prompt
 * evaluation table are built from: which model answered, under which sampling
 * settings, how long it took and what it cost in tokens.
 */
final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly float $temperature,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly ?string $stopReason = null,
        public readonly int $thinkingTokens = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider'      => $this->provider,
            'model'         => $this->model,
            'temperature'   => $this->temperature,
            'input_tokens'  => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'thinking_tokens' => $this->thinkingTokens,
            'latency_ms'    => $this->latencyMs,
            'stop_reason'   => $this->stopReason,
        ];
    }
}
