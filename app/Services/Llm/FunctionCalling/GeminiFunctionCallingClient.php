<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Llm\LlmException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini generateContent with function declarations.
 *
 * Same endpoint, key header, sampling and thinking settings as GoogleClient,
 * which is left untouched. The response is not reduced to text: function-call
 * parts are returned as proposed calls, and the raw model turn is kept so the
 * conversation can be resent with the tool result appended.
 */
class GeminiFunctionCallingClient implements FunctionCallingClient
{
    public function __construct(private readonly array $config)
    {
    }

    public function generate(string $system, array $contents, array $declarations): ModelTurn
    {
        $key = $this->config['google']['key'] ?? null;

        if (empty($key)) {
            throw new LlmException('GOOGLE_API_KEY is not set.');
        }

        $url = rtrim($this->config['google']['base'], '/')
            . '/' . $this->config['model'] . ':generateContent';

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature'     => $this->config['temperature'],
                'maxOutputTokens' => $this->config['max_tokens'],
                'thinkingConfig'  => [
                    'thinkingLevel' => $this->config['google']['thinking_level'],
                ],
            ],
        ];

        if ($declarations !== []) {
            $payload['tools']       = [['function_declarations' => $declarations]];
            $payload['tool_config'] = ['function_calling_config' => ['mode' => 'AUTO']];
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'x-goog-api-key' => $key,
                    'content-type'   => 'application/json',
                ])
                ->post($url, $payload);
        } catch (Throwable $e) {
            // Timeouts and refused connections become handled failures, never fatals.
            throw LlmException::fromTransport($e, (int) $this->config['timeout']);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw LlmException::fromResponse($response);
        }

        return $this->parse($response->json() ?? [], $latencyMs);
    }

    /** Public for testing the response handling without a network call. */
    public function parse(array $body, int $latencyMs = 0): ModelTurn
    {
        if (($blockReason = $body['promptFeedback']['blockReason'] ?? null) !== null) {
            throw new LlmException('Model blocked the request: ' . $blockReason);
        }

        $candidate = $body['candidates'][0] ?? null;

        if ($candidate === null) {
            throw new LlmException('Model returned no candidate.');
        }

        $content = $candidate['content'] ?? ['parts' => []];
        $content['role'] ??= 'model';

        $text  = '';
        $calls = [];

        foreach ($content['parts'] ?? [] as $part) {
            // Thought summaries are not part of the answer.
            if (! empty($part['thought'])) {
                continue;
            }

            if (isset($part['functionCall'])) {
                $calls[] = [
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'args' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                    'id'   => $part['functionCall']['id'] ?? null,
                ];
            } elseif (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $finish = $candidate['finishReason'] ?? null;
        $usage  = $body['usageMetadata'] ?? [];

        // Thinking tokens come out of maxOutputTokens first (the Week 2 finding).
        if ($finish === 'MAX_TOKENS') {
            throw new LlmException(
                'Model output was truncated at the max_tokens limit (' . $this->config['max_tokens']
                . '), after ' . (int) ($usage['thoughtsTokenCount'] ?? 0)
                . ' thinking tokens. Raise LLM_MAX_TOKENS or lower GOOGLE_THINKING_LEVEL.'
            );
        }

        if ($calls === [] && trim($text) === '') {
            throw new LlmException('Model returned neither text nor a function call'
                . ($finish ? " (finishReason {$finish})." : '.'));
        }

        return new ModelTurn(
            text: trim($text),
            calls: $calls,
            content: $content,
            model: $body['modelVersion'] ?? $this->config['model'],
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            thinkingTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
            latencyMs: $latencyMs,
            finishReason: $finish,
        );
    }
}
