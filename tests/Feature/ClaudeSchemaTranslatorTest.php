<?php

namespace Tests\Feature;

use App\Services\Llm\FunctionCalling\ClaudeSchemaTranslator;
use App\Services\Tools\Catalogue\FarmProfileLookupTool;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolRegistry;
use Tests\TestCase;

class ClaudeSchemaTranslatorTest extends TestCase
{
    private ClaudeSchemaTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new ClaudeSchemaTranslator();
    }

    /** @return array<string, ToolContract> */
    private function tools(): array
    {
        $tools = [];

        foreach ($this->app->make(ToolRegistry::class)->all() as $tool) {
            $tools[$tool->name()] = $tool;
        }

        return $tools;
    }

    private function declaration(string $name): array
    {
        return $this->translator->toToolDeclaration($this->tools()[$name]);
    }

    public function test_all_five_tools_translate(): void
    {
        $declarations = $this->translator->toToolDeclarations($this->tools());

        $this->assertSame(
            ['farm_profile_lookup', 'schedule_visit', 'log_followup', 'search_guidance', 'recall_farm_history'],
            array_column($declarations, 'name')
        );

        foreach ($this->tools() as $name => $tool) {
            $this->assertSame($tool->inputSchema(), $this->declaration($name)['input_schema']);
        }
    }

    /** The opposite of the Gemini assertion: the rule reaches the model as a rule. */
    public function test_farm_id_pattern_survives(): void
    {
        foreach (['farm_profile_lookup', 'schedule_visit', 'log_followup'] as $name) {
            $this->assertSame(
                FarmProfileLookupTool::FARM_ID_PATTERN,
                $this->declaration($name)['input_schema']['properties']['farm_id']['pattern']
            );
        }
    }

    public function test_enum_max_length_and_format_survive(): void
    {
        $followup = $this->declaration('log_followup')['input_schema']['properties'];
        $visit    = $this->declaration('schedule_visit')['input_schema']['properties'];

        $this->assertSame(['resolved', 'ongoing', 'escalated'], $followup['outcome']['enum']);
        $this->assertSame(2000, $followup['notes']['maxLength']);
        $this->assertSame(200, $visit['purpose']['maxLength']);
        $this->assertSame('date', $visit['visit_date']['format']);
    }

    public function test_declaration_has_exactly_name_description_and_input_schema(): void
    {
        foreach (array_keys($this->tools()) as $name) {
            $this->assertSame(['name', 'description', 'input_schema'], array_keys($this->declaration($name)));
        }
    }
}
