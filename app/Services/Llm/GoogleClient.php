<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google AI (Gemini) implementation of the provider-agnostic contract.
 *
 * Gemini differs from the other two providers in three ways that matter here:
 * the model id is part of the URL rather than the body, the system prompt is a
 * separate `systemInstruction` field rather than a message with a role, and a
 * blocked request returns HTTP 200 with no candidate rather than an error
 * status. Each is handled below so the caller still sees one LlmResponse or one
 * LlmException, exactly as it does for Anthropic and OpenAI.
 */
class GoogleClient implements LlmClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function provider(): string
    {
        return 'google';
    }

    public function complete(string $system, string $user): LlmResponse
    {
        $key = $this->config['google']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('GOOGLE_API_KEY is not set.');
        }

        // The model is addressed in the path, not the payload.
        $url = rtrim($this->config['google']['base'], '/')
            . '/' . $this->config['model'] . ':generateContent';

        $startedAt = microtime(true);

        try {
            // The key travels as a header, never as a query parameter, so it
            // does not end up in proxy or server logs.
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'x-goog-api-key' => $key,
                    'content-type'   => 'application/json',
                ])
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [['text' => $system]],
                    ],
                    'contents' => [
                        [
                            'role'  => 'user',
                            'parts' => [['text' => $user]],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature'     => $this->config['temperature'],
                        'maxOutputTokens' => $this->config['max_tokens'],
                        'thinkingConfig'  => [
                            'thinkingLevel' => $this->config['google']['thinking_level'],
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            throw new LlmException('Model request failed: ' . $e->getMessage(), 0, $e);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new LlmException(
                'Model returned HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $body = $response->json();

        // A safety block is a 200 with no candidate and a stated reason. Surface
        // it as a failed call rather than letting it read as an empty answer.
        $blockReason = $body['promptFeedback']['blockReason'] ?? null;

        if ($blockReason !== null) {
            throw new LlmException('Model blocked the request: ' . $blockReason);
        }

        $candidate = $body['candidates'][0] ?? null;

        if ($candidate === null) {
            throw new LlmException('Model returned no candidate.');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty completion.');
        }

        // Thinking tokens are drawn from maxOutputTokens before the answer is
        // written, so a budget that looks generous can still truncate mid-JSON.
        // Reported as truncation, not as a malformed-output problem.
        if (($candidate['finishReason'] ?? null) === 'MAX_TOKENS') {
            throw new LlmException(
                'Model output was truncated at the max_tokens limit ('
                . $this->config['max_tokens'] . '), after '
                . (int) ($body['usageMetadata']['thoughtsTokenCount'] ?? 0)
                . ' thinking tokens. Raise LLM_MAX_TOKENS or lower GOOGLE_THINKING_LEVEL.'
            );
        }

        return new LlmResponse(
            text: $text,
            provider: $this->provider(),
            model: $body['modelVersion'] ?? $this->config['model'],
            temperature: $this->config['temperature'],
            inputTokens: (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            latencyMs: $latencyMs,
            stopReason: $candidate['finishReason'] ?? null,
            thinkingTokens: (int) ($body['usageMetadata']['thoughtsTokenCount'] ?? 0),
        );
    }
}
