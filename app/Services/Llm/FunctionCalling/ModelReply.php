<?php

namespace App\Services\Llm\FunctionCalling;

/**
 * One Claude response: typed content blocks, kept exactly as received.
 *
 * rawContent() is what goes back into the conversation as the assistant turn.
 * Rebuilding that turn from the tool name and arguments would drop the other
 * blocks in it (text, thinking), the same lesson as the Week 4 Gemini
 * thoughtSignature finding by a different mechanism.
 */
final class ModelReply
{
    public function __construct(
        private readonly array $content,
        private readonly ?string $stopReason = null,
        private readonly int $inputTokens = 0,
        private readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly int $latencyMs = 0,
    ) {
    }

    public function rawContent(): array
    {
        return $this->content;
    }

    /** All text blocks, concatenated. */
    public function text(): string
    {
        return trim(implode('', array_map(
            fn (array $b) => $b['text'] ?? '',
            $this->blocks('text')
        )));
    }

    /**
     * The model's stated reasoning for this turn: its text, or failing that the
     * progress notes it wrote between tool calls (thinking display 'updates').
     */
    public function plan(): string
    {
        if (($text = $this->text()) !== '') {
            return $text;
        }

        return trim(implode("\n", array_filter(array_map(
            fn (array $b) => trim((string) ($b['thinking'] ?? '')),
            $this->blocks('thinking')
        ))));
    }

    public function hasToolCall(): bool
    {
        return $this->toolCallCount() > 0;
    }

    public function toolCall(): ?ToolCall
    {
        return $this->toolCalls()[0] ?? null;
    }

    /** @return list<ToolCall> */
    public function toolCalls(): array
    {
        return array_map(fn (array $b) => new ToolCall(
            (string) ($b['id'] ?? ''),
            (string) ($b['name'] ?? ''),
            is_array($b['input'] ?? null) ? $b['input'] : [],
        ), $this->blocks('tool_use'));
    }

    public function toolCallCount(): int
    {
        return count($this->blocks('tool_use'));
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    /** @return list<array> */
    private function blocks(string $type): array
    {
        return array_values(array_filter($this->content, fn ($b) => ($b['type'] ?? null) === $type));
    }
}
