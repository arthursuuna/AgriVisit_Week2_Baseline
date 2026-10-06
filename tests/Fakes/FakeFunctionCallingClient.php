<?php

namespace Tests\Fakes;

use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Llm\FunctionCalling\ModelTurn;
use App\Services\Llm\LlmException;
use RuntimeException;

/**
 * Returns queued model turns in order and records every request it receives.
 * No key, no network, no quota.
 */
class FakeFunctionCallingClient implements FunctionCallingClient
{
    /** @var list<ModelTurn|LlmException> */
    private array $queue;

    /** @var list<array{system: string, contents: array, declarations: array}> */
    public array $requests = [];

    public function __construct(ModelTurn|LlmException ...$turns)
    {
        $this->queue = $turns;
    }

    public function generate(string $system, array $contents, array $declarations): ModelTurn
    {
        $this->requests[] = compact('system', 'contents', 'declarations');

        $next = array_shift($this->queue) ?? throw new RuntimeException('The fake model was called more times than expected.');

        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public static function text(string $text): ModelTurn
    {
        return new ModelTurn($text, [], ['role' => 'model', 'parts' => [['text' => $text]]], 'fake-model', 100, 20, 30);
    }

    /** One or more proposed calls, each [name, args]. */
    public static function calls(array ...$calls): ModelTurn
    {
        $parts = array_map(fn (array $c) => [
            'functionCall'     => ['name' => $c[0], 'args' => $c[1]],
            'thoughtSignature' => 'sig-' . $c[0],
        ], $calls);

        return new ModelTurn(
            '',
            array_map(fn (array $c) => ['name' => $c[0], 'args' => $c[1], 'id' => null], $calls),
            ['role' => 'model', 'parts' => $parts],
            'fake-model', 120, 15, 40
        );
    }
}
