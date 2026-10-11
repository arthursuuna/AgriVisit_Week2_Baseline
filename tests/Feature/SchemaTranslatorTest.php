<?php

namespace Tests\Feature;

use App\Services\Llm\FunctionCalling\SchemaTranslator;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use Tests\TestCase;

class SchemaTranslatorTest extends TestCase
{
    private SchemaTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new SchemaTranslator();
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
        return $this->translator->toFunctionDeclaration($this->tools()[$name]);
    }

    /**
     * Every keyword used anywhere in a schema node, at any depth. Property
     * names are not keywords, so the walk steps into properties' values only.
     *
     * @return list<string>
     */
    private function keywords(array $schema): array
    {
        $found = array_keys($schema);

        foreach ($schema['properties'] ?? [] as $property) {
            $found = [...$found, ...$this->keywords($property)];
        }

        if (isset($schema['items'])) {
            $found = [...$found, ...$this->keywords($schema['items'])];
        }

        return $found;
    }

    public function test_every_tool_translates(): void
    {
        $this->assertCount(4, $this->tools());

        foreach ($this->tools() as $name => $tool) {
            $declaration = $this->translator->toFunctionDeclaration($tool);

            $this->assertSame($name, $declaration['name']);
            $this->assertSame($tool->description(), $declaration['description']);
            $this->assertSame('OBJECT', $declaration['parameters']['type']);
            $this->assertSame(array_keys($tool->inputSchema()['properties']), array_keys($declaration['parameters']['properties']));
        }
    }

    public function test_no_unsupported_keyword_appears_at_any_depth(): void
    {
        foreach ($this->tools() as $tool) {
            $unsupported = array_diff($this->keywords($this->translator->toFunctionDeclaration($tool)['parameters']), SchemaTranslator::SUPPORTED);
            $this->assertSame([], array_values(array_unique($unsupported)), "{$tool->name()} leaks unsupported keywords");
        }

        // A nested, nullable, bounded schema: the case the three tools do not exercise yet.
        $nested = new class implements ToolContract {
            public function name(): string { return 'nested'; }
            public function description(): string { return 'Nested.'; }
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => [
                    'plots' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'acres'   => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 50],
                        'planted' => ['type' => 'string', 'format' => 'date', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                    ], 'required' => ['acres']]],
                ], 'required' => ['plots']];
            }
            public function outputSchema(): array { return $this->inputSchema(); }
            public function isWrite(): bool { return false; }
            public function approvalSummary(array $args): string { return ''; }
            public function execute(array $args): ToolResult { return ToolResult::success([]); }
        };

        $parameters = $this->translator->toFunctionDeclaration($nested)['parameters'];
        $acres      = $parameters['properties']['plots']['items']['properties']['acres'];

        $this->assertSame([], array_values(array_diff($this->keywords($parameters), SchemaTranslator::SUPPORTED)));
        $this->assertSame('NUMBER', $acres['type']);
        $this->assertStringContainsString('Between 0 and 50.', $acres['description']);
        $this->assertStringContainsString('May be null.', $acres['description']);
    }

    public function test_a_dropped_pattern_is_described(): void
    {
        $farmId = $this->declaration('farm_profile_lookup')['parameters']['properties']['farm_id'];

        $this->assertArrayNotHasKey('pattern', $farmId);
        $this->assertStringContainsString('Format: UG-XXX-000 (X = capital letter, 0 = digit).', $farmId['description']);

        $visitId = $this->declaration('log_followup')['parameters']['properties']['visit_id'];
        $this->assertStringContainsString('Format: V-0000…', $visitId['description']);
    }

    public function test_a_dropped_max_length_and_date_format_are_described(): void
    {
        $properties = $this->declaration('schedule_visit')['parameters']['properties'];

        $this->assertStringContainsString('Maximum 200 characters.', $properties['purpose']['description']);
        $this->assertStringContainsString('Must not be empty.', $properties['purpose']['description']);
        $this->assertStringContainsString('Format: YYYY-MM-DD.', $properties['visit_date']['description']);
        $this->assertStringContainsString('Maximum 2000 characters.',
            $this->declaration('log_followup')['parameters']['properties']['notes']['description']);
    }

    public function test_enum_survives_unchanged(): void
    {
        $outcome = $this->declaration('log_followup')['parameters']['properties']['outcome'];

        $this->assertSame('STRING', $outcome['type']);
        $this->assertSame(['resolved', 'ongoing', 'escalated'], $outcome['enum']);
    }

    public function test_required_matches_the_tool_schema(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertSame(
                $tool->inputSchema()['required'],
                $this->translator->toFunctionDeclaration($tool)['parameters']['required']
            );
        }
    }
}
