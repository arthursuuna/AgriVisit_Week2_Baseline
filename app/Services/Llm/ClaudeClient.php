<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Single-turn completion against the Claude Messages API.
 *
 * Differences from the Gemini client, all of which matter:
 *   - the model is in the request body, not the URL path
 *   - the system prompt is a top-level parameter, not a message role
 *   - max_tokens is required, not optional
 *   - the key travels in x-api-key, and anthropic-version is mandatory
 *   - the response body carries a content array of typed blocks
 *   - Sonnet 5.5 rejects a non-default temperature; effort is the setting
 *
 * The older AnthropicClient is left in place; this one replaces it in the
 * container because it shares its transport with the agent loop's client.
 */
class ClaudeClient implements LlmClient
{
    /** The API's default, recorded when temperature is not sent. */
    public const DEFAULT_TEMPERATURE = 1.0;

    public function __construct(private readonly array $config)
    {
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, string $user): LlmResponse
    {
        $body = $this->send($this->basePayload() + [
            'system'   => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ], $latencyMs);

        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw new LlmException('Model declined the request (refusal'
                . (isset($body['stop_details']['category']) ? ': ' . $body['stop_details']['category'] : '') . ').');
        }

        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty completion'
                . (($body['stop_reason'] ?? null) === 'max_tokens'
                    ? ': max_tokens (' . $this->config['max_tokens'] . ') was spent before an answer. Raise LLM_MAX_TOKENS or lower ANTHROPIC_EFFORT.'
                    : '.'));
        }

        return new LlmResponse(
            text: $text,
            provider: $this->provider(),
            model: $body['model'] ?? $this->config['model'],
            temperature: $this->temperature(),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            stopReason: $body['stop_reason'] ?? null,
        );
    }

    /** Model, token limit and sampling settings shared by every request. */
    public function basePayload(): array
    {
        $payload = [
            'model'      => $this->config['model'],
            'max_tokens' => $this->config['max_tokens'],
        ];

        if (! empty($this->config['anthropic']['send_temperature'])) {
            $payload['temperature'] = $this->config['temperature'];
        }

        if (! empty($this->config['anthropic']['effort'])) {
            $payload['output_config'] = ['effort' => $this->config['anthropic']['effort']];
        }

        if (! empty($this->config['anthropic']['fallbacks'])) {
            $payload['fallbacks'] = $this->config['anthropic']['fallbacks'];
        }

        return $payload;
    }

    /** The temperature actually in force, for traces. */
    public function temperature(): float
    {
        return ! empty($this->config['anthropic']['send_temperature'])
            ? (float) $this->config['temperature']
            : self::DEFAULT_TEMPERATURE;
    }

    /**
     * Shared transport. Converts every transport and HTTP failure into
     * LlmException so nothing escapes to an error page.
     */
    public function send(array $payload, ?int &$latencyMs = null): array
    {
        $key = $this->config['anthropic']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('ANTHROPIC_API_KEY is not set.');
        }

        $headers = [
            'x-api-key'         => $key,
            'anthropic-version' => $this->config['anthropic']['version'],
            'content-type'      => 'application/json',
        ];

        if (! empty($this->config['anthropic']['workspace_id'])) {
            // Only sent when configured; see the note on user-scoped keys.
            $headers['anthropic-workspace-id'] = $this->config['anthropic']['workspace_id'];
        }

        if (($betas = $this->betas($payload)) !== []) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders($headers)
                ->post($this->config['anthropic']['base'], $payload);
        } catch (Throwable $e) {
            // Timeouts and refused connections become handled failures, never fatals.
            throw LlmException::fromTransport($e, (int) $this->config['timeout']);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw LlmException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /** Beta headers implied by the payload, so they can never drift apart. */
    private function betas(array $payload): array
    {
        $betas = [];

        if (isset($payload['fallbacks'])) {
            $betas[] = 'server-side-fallback-2026-07-01';
        }

        if (($payload['thinking']['display'] ?? null) === 'updates') {
            $betas[] = 'thinking-display-updates-2026-08-18';
        }

        return $betas;
    }
}
