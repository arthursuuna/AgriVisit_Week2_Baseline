<?php

namespace Tests\Feature;

use App\Services\Llm\FunctionCalling\GeminiFunctionCallingClient;
use App\Services\Llm\LlmException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Request shape and response parsing, against a faked HTTP layer.
 */
class GeminiFunctionCallingClientTest extends TestCase
{
    private function client(): GeminiFunctionCallingClient
    {
        return new GeminiFunctionCallingClient([
            'model'       => 'gemini-test',
            'temperature' => 0.2,
            'max_tokens'  => 4000,
            'timeout'     => 5,
            'google'      => ['key' => 'test-key', 'base' => 'https://example.test/v1beta/models', 'thinking_level' => 'low'],
        ]);
    }

    public function test_the_request_carries_declarations_config_and_the_key_header(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => 'farm_profile_lookup', 'args' => ['farm_id' => 'UG-KYA-012']], 'thoughtSignature' => 'abc'],
        ]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 310, 'candidatesTokenCount' => 22, 'thoughtsTokenCount' => 51]])]);

        $declarations = [['name' => 'farm_profile_lookup', 'description' => 'd', 'parameters' => ['type' => 'OBJECT', 'properties' => []]]];
        $contents     = [['role' => 'user', 'parts' => [['text' => 'hi']]]];

        $turn = $this->client()->generate('system text', $contents, $declarations);

        Http::assertSent(function (Request $request) use ($declarations, $contents) {
            return $request->url() === 'https://example.test/v1beta/models/gemini-test:generateContent'
                && $request->header('x-goog-api-key') === ['test-key']
                && ! str_contains($request->url(), 'key=')
                && $request['system_instruction'] === ['parts' => [['text' => 'system text']]]
                && $request['contents'] === $contents
                && $request['tools'] === [['function_declarations' => $declarations]]
                && $request['tool_config'] === ['function_calling_config' => ['mode' => 'AUTO']]
                && $request['generationConfig']['maxOutputTokens'] === 4000
                && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low'];
        });

        $this->assertTrue($turn->hasCalls());
        $this->assertSame([['name' => 'farm_profile_lookup', 'args' => ['farm_id' => 'UG-KYA-012'], 'id' => null]], $turn->calls);
        $this->assertSame('abc', $turn->content['parts'][0]['thoughtSignature']);
        $this->assertSame([310, 22, 51], [$turn->inputTokens, $turn->outputTokens, $turn->thinkingTokens]);
    }

    public function test_text_parts_are_joined_and_thought_parts_skipped(): void
    {
        $turn = $this->client()->parse(['candidates' => [['content' => ['parts' => [
            ['text' => 'thinking aloud', 'thought' => true],
            ['text' => 'I cannot provide a weather forecast.'],
        ]], 'finishReason' => 'STOP']]]);

        $this->assertTrue($turn->isText());
        $this->assertSame('I cannot provide a weather forecast.', $turn->text);
        $this->assertSame('model', $turn->content['role']);
    }

    public function test_truncation_and_empty_responses_are_exceptions(): void
    {
        foreach ([
            ['candidates' => [['content' => ['parts' => [['text' => 'partial']]], 'finishReason' => 'MAX_TOKENS']]],
            ['candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']]],
            ['promptFeedback' => ['blockReason' => 'SAFETY']],
            [],
        ] as $body) {
            try {
                $this->client()->parse($body);
                $this->fail('Expected LlmException for ' . json_encode($body));
            } catch (LlmException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function failure(): LlmException
    {
        try {
            $this->client()->generate('s', [['role' => 'user', 'parts' => [['text' => 'hi']]]], []);
        } catch (LlmException $e) {
            return $e;
        }

        $this->fail('Expected LlmException');
    }

    public function test_a_429_becomes_a_rate_limited_llm_exception_carrying_the_status(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $e = $this->failure();

        $this->assertSame(429, $e->status);
        $this->assertSame(LlmException::RATE_LIMITED, $e->kind);
        $this->assertTrue($e->isRateLimited());
        $this->assertStringContainsString('rate limited, try again later', $e->reason());
    }

    public function test_a_connection_timeout_becomes_a_timeout_llm_exception(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds')]);

        $e = $this->failure();

        $this->assertSame(LlmException::TIMEOUT, $e->kind);
        $this->assertNull($e->status);
        $this->assertStringContainsString('timed out after 5 s', $e->getMessage());
    }

    public function test_a_refused_connection_and_a_503_are_unavailable(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: Failed to connect')
            ->push('overloaded', 503)]);

        $this->assertSame(LlmException::UNAVAILABLE, $this->failure()->kind);

        $e = $this->failure();
        $this->assertSame(LlmException::UNAVAILABLE, $e->kind);
        $this->assertSame(503, $e->status);
    }
}
