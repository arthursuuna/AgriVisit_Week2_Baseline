<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

class AnthropicClient implements LlmClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, string $user): LlmResponse
    {
        $key = $this->config['anthropic']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('ANTHROPIC_API_KEY is not set.');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'x-api-key'         => $key,
                    'anthropic-version' => $this->config['anthropic']['version'],
                    'content-type'      => 'application/json',
                ])
                ->post($this->config['anthropic']['base'], [
                    'model'       => $this->config['model'],
                    'max_tokens'  => $this->config['max_tokens'],
                    'temperature' => $this->config['temperature'],
                    'system'      => $system,
                    'messages'    => [
                        ['role' => 'user', 'content' => $user],
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

        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw new LlmException('Model returned an empty completion.');
        }

        return new LlmResponse(
            text: $text,
            provider: $this->provider(),
            model: $body['model'] ?? $this->config['model'],
            temperature: $this->config['temperature'],
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            stopReason: $body['stop_reason'] ?? null,
        );
    }
}
