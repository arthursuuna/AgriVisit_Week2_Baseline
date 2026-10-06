<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Llm\LlmException;

/**
 * One model call that may answer in text or propose function calls.
 *
 * Kept separate from LlmClient: that contract is single-turn text, and the
 * Week 2 comparison depends on it staying that way. Tests inject a fake.
 */
interface FunctionCallingClient
{
    /**
     * @param list<array> $contents     The whole conversation so far; the API is stateless.
     * @param list<array> $declarations Function declarations from SchemaTranslator.
     *
     * @throws LlmException
     */
    public function generate(string $system, array $contents, array $declarations): ModelTurn;
}
