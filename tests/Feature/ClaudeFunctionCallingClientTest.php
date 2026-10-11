<?php

namespace Tests\Feature;

use App\Services\Llm\ClaudeClient;
use App\Services\Llm\FunctionCalling\ClaudeFunctionCallingClient;
use App\Services\Llm\LlmException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wire shape of the Claude requests, checked against Http::fake().
 * No key leaves the test and no network call is made.
 */
class ClaudeFunctionCallingClientTest extends TestCase
{
    private function config(array $anthropic = []): array
    {
        return [
            'model'       => 'claude-sonnet-5-5',
            'temperature' => 0.2,
            'max_tokens'  => 4000,
            'timeout'     => 25,
            'anthropic'   => $anthropic + [
                'key'              => 'test-key',
                'workspace_id'     => null,
                'base'             => 'https://api.anthropic.com/v1/messages',
                'version'          => '2023-06-01',
                'send_temperature' => false,
                'effort'           => 'medium',
                'thinking_display' => 'updates',
                'fallbacks'        => 'default',
            ],
        ];
    }

    private function client(array $anthropic = []): ClaudeFunctionCallingClient
    {
        $config = $this->config($anthropic);

        return new ClaudeFunctionCallingClient($config, new ClaudeClient($config));
    }

    private function body(array $content, string $stop = 'tool_use'): array
    {
        return [
            'model'       => 'claude-sonnet-5-5',
            'content'     => $content,
            'stop_reason' => $stop,
            'usage'       => ['input_tokens' => 912, 'output_tokens' => 84],
        ];
    }

    public function test_request_carries_tools_with_parallel_use_disabled_and_no_temperature(): void
    {
        Http::fake(['*' => Http::response($this->body([
            ['type' => 'text', 'text' => "I'll look up that farm first."],
            ['type' => 'tool_use', 'id' => 'toolu_01A', 'name' => 'farm_profile_lookup', 'input' => ['farm_id' => 'UG-KYA-012']],
        ]))]);

        $reply = $this->client()->reply('System.', [['role' => 'user', 'content' => 'Go']], [['name' => 'farm_profile_lookup']]);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && ! $request->hasHeader('anthropic-workspace-id')
                && str_contains($request->header('anthropic-beta')[0], 'server-side-fallback-2026-07-01')
                && $data['model'] === 'claude-sonnet-5-5'
                && $data['system'] === 'System.'
                && ! array_key_exists('temperature', $data)
                && $data['output_config'] === ['effort' => 'medium']
                && $data['tool_choice'] === ['type' => 'auto', 'disable_parallel_tool_use' => true];
        });

        $this->assertTrue($reply->hasToolCall());
        $this->assertSame(1, $reply->toolCallCount());
        $this->assertSame('toolu_01A', $reply->toolCall()->id);
        $this->assertSame(['farm_id' => 'UG-KYA-012'], $reply->toolCall()->arguments);
        $this->assertSame("I'll look up that farm first.", $reply->text());
        $this->assertSame(912, $reply->inputTokens());
        $this->assertCount(2, $reply->rawContent());
    }

    public function test_workspace_header_is_sent_only_when_configured(): void
    {
        Http::fake(['*' => Http::response($this->body([['type' => 'text', 'text' => 'ok']], 'end_turn'))]);

        $this->client(['workspace_id' => 'wrkspc_test'])->reply('S', [['role' => 'user', 'content' => 'x']], []);

        Http::assertSent(fn (Request $r) => $r->hasHeader('anthropic-workspace-id', 'wrkspc_test')
            && ! array_key_exists('tools', $r->data()));
    }

    public function test_plan_falls_back_to_progress_notes_when_there_is_no_text(): void
    {
        $reply = $this->client()->parse($this->body([
            ['type' => 'thinking', 'thinking' => 'Looking up the farm before searching guidance.', 'signature' => 'sig'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'farm_profile_lookup', 'input' => ['farm_id' => 'UG-KYA-012']],
        ]));

        $this->assertSame('', $reply->text());
        $this->assertSame('Looking up the farm before searching guidance.', $reply->plan());
    }

    public function test_truncated_refused_and_failed_replies_become_llm_exceptions(): void
    {
        foreach (['max_tokens', 'refusal'] as $stop) {
            try {
                $this->client()->parse($this->body([['type' => 'text', 'text' => 'partial']], $stop));
                $this->fail("{$stop} should throw");
            } catch (LlmException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        Http::fake(['*' => Http::response(['error' => ['message' => 'bad']], 429)]);

        try {
            $this->client()->reply('S', [['role' => 'user', 'content' => 'x']], []);
            $this->fail('429 should throw');
        } catch (LlmException $e) {
            $this->assertTrue($e->isRateLimited());
        }
    }

    public function test_missing_key_fails_before_any_request(): void
    {
        Http::fake();

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY is not set.');

        try {
            $this->client(['key' => null])->reply('S', [], []);
        } finally {
            Http::assertNothingSent();
        }
    }
}
