<?php

namespace App\Services\Llm\FunctionCalling;

use App\Services\Llm\LlmException;

/**
 * One model call in a multi-turn tool conversation, in Claude's message shape.
 *
 * Separate from FunctionCallingClient: that contract carries Gemini-shaped
 * contents (role 'model', parts, functionResponse), and ToolSession builds
 * those contents itself, so a Claude implementation behind it would receive a
 * conversation it cannot read. The agent loop depends on this contract; tests
 * inject a fake.
 */
interface AgentModelClient
{
    /**
     * @param list<array> $messages     The whole conversation so far; the API is stateless.
     * @param list<array> $declarations Tool declarations from ClaudeSchemaTranslator.
     *
     * @throws LlmException
     */
    public function reply(string $system, array $messages, array $declarations): ModelReply;
}
