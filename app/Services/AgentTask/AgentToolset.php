<?php

namespace App\Services\AgentTask;

use App\Services\Llm\FunctionCalling\ClaudeSchemaTranslator;
use App\Services\Prompts\PromptRepository;
use App\Services\Tools\DiagnosticTool;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolPolicy;
use App\Services\Tools\ToolRegistry;

/**
 * What the agent is given for a run: the tools it may call and its system
 * prompt. One place, so the console command and the Agent Task Contract can
 * never describe different agents.
 */
class AgentToolset
{
    public const PROMPT_FAMILY = 'agent';

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolPolicy $policy,
        private readonly ClaudeSchemaTranslator $translator,
        private readonly PromptRepository $prompts,
        private readonly string $promptVersion,
    ) {
    }

    /**
     * Enabled tools, in registry order. Probes are never offered, even when
     * switched on: the agent must not be given a tool that exists to break.
     *
     * @return list<ToolContract>
     */
    public function tools(): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (ToolContract $tool) => ! $tool instanceof DiagnosticTool && $this->policy->allows($tool)
        ));
    }

    /** @return list<array> Claude tool declarations. */
    public function declarations(): array
    {
        return $this->translator->toToolDeclarations($this->tools());
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->render(self::PROMPT_FAMILY, $this->promptVersion, 'system', [
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * The prompt's sections by heading ('Role', 'Task', ...), unrendered.
     *
     * @return array<string, string>
     */
    public function promptSections(): array
    {
        $sections = [];

        foreach (preg_split('/^## +/m', $this->systemPrompt(), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            [$heading, $body] = array_pad(explode("\n", $part, 2), 2, '');
            $sections[trim($heading)] = trim($body);
        }

        return $sections;
    }
}
