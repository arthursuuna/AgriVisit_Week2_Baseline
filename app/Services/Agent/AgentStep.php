<?php

namespace App\Services\Agent;

/**
 * One iteration of the loop, recorded whatever its outcome.
 */
final class AgentStep
{
    public const OK = 'OK';

    public function __construct(
        public readonly int $iteration,
        public readonly string $plan,
        public readonly ?string $tool,
        public readonly array $arguments,
        public readonly string $outcome,
        public readonly float $durationMs,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $toolCallsProposed = 0,
        public readonly ?string $message = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'iteration'           => $this->iteration,
            'plan'                => $this->plan,
            'tool'                => $this->tool,
            'arguments'           => $this->arguments,
            'outcome'             => $this->outcome,
            'message'             => $this->message,
            'duration_ms'         => $this->durationMs,
            'input_tokens'        => $this->inputTokens,
            'output_tokens'       => $this->outputTokens,
            // More than 1 should not happen with parallel tool use disabled.
            'tool_calls_proposed' => $this->toolCallsProposed,
        ];
    }
}
