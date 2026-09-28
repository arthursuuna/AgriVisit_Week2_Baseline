<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiClient implements LlmClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function complete(string $system, string $user): LlmResponse
    {
        $key = $this->config['openai']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('OPENAI_API_KEY is not set.');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withToken($key)
                ->post($this->config['openai']['base'], [
                    'model'       => $this->config['model'],
                    'temperature' => $this->config['temperature'],
                    'max_tokens'  => $this->config['max_tokens'],
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $user],
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
        $text = $body['choices'][0]['message']['content'] ?? '';

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty completion.');
        }

        return new LlmResponse(
            text: $text,
            provider: $this->provider(),
            model: $body['model'] ?? $this->config['model'],
            temperature: $this->config['temperature'],
            inputTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            latencyMs: $latencyMs,
            stopReason: $body['choices'][0]['finish_reason'] ?? null,
        );
    }
}
