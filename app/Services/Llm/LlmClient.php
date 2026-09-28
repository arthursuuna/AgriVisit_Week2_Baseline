<?php

namespace App\Services\Llm;

/**
 * Provider-agnostic contract for a single-turn foundation-model call.
 *
 * Keeping the provider behind an interface is what makes the Week 2 model
 * comparison possible: the same prompt and the same evaluation set can be run
 * against a different implementation by changing configuration alone.
 */
interface LlmClient
{
    /**
     * @param string $system Stable application instructions (role, policy, format).
     * @param string $user   The immediate task and its data.
     *
     * @throws LlmException
     */
    public function complete(string $system, string $user): LlmResponse;

    public function provider(): string;
}
