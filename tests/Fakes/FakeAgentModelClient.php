<?php

namespace Tests\Fakes;

use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Llm\FunctionCalling\ModelReply;
use App\Services\Llm\LlmException;
use Closure;
use RuntimeException;

/**
 * Returns scripted Claude replies in order and records every request.
 * No key, no network, no quota.
 */
class FakeAgentModelClient implements AgentModelClient
{
    /** @var list<ModelReply|LlmException|Closure> */
    private array $queue;

    /** @var list<array{system: string, messages: array, declarations: array}> */
    public array $requests = [];

    private static int $ids = 0;

    public function __construct(ModelReply|LlmException|Closure ...$replies)
    {
        $this->queue = $replies;
    }

    public function reply(string $system, array $messages, array $declarations): ModelReply
    {
        $this->requests[] = compact('system', 'messages', 'declarations');

        $next = array_shift($this->queue) ?? throw new RuntimeException('The fake model was called more times than expected.');

        if ($next instanceof Closure) {
            $next = $next(count($this->requests));
        }

        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public static function text(string $text): ModelReply
    {
        return new ModelReply([['type' => 'text', 'text' => $text]], 'end_turn', 100, 20, 'fake-claude');
    }

    public static function call(string $name, array $input, string $plan = ''): ModelReply
    {
        $content = $plan !== '' ? [['type' => 'text', 'text' => $plan]] : [];
        $content[] = ['type' => 'tool_use', 'id' => 'toolu_' . (++self::$ids), 'name' => $name, 'input' => $input];

        return new ModelReply($content, 'tool_use', 120, 15, 'fake-claude');
    }
}
