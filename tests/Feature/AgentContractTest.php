<?php

namespace Tests\Feature;

use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentTaskContract;
use ReflectionClass;
use Tests\TestCase;

class AgentContractTest extends TestCase
{
    private function contract(): array
    {
        return $this->app->make(AgentTaskContract::class)->toArray();
    }

    public function test_every_enabled_tool_appears_in_the_contract(): void
    {
        $listed = array_column($this->contract()['tools'], 'name');

        foreach (config('agrivisit.tools.enabled') as $name) {
            $this->assertContains($name, $listed, "Enabled tool {$name} is missing from the contract.");
        }
    }

    /** Read by reflection, so a stop reason added later fails this until the contract shows it. */
    public function test_every_stop_reason_constant_is_listed_with_a_condition(): void
    {
        $contract = $this->contract();
        $markdown = $this->app->make(AgentTaskContract::class)->toMarkdown();

        foreach ((new ReflectionClass(StopReason::class))->getConstants() as $name => $value) {
            $this->assertArrayHasKey($name, $contract['stop_reasons']);
            $this->assertNotSame('', $contract['stop_reasons'][$name], "{$name} has no condition.");
            $this->assertStringContainsString("`{$name}`", $markdown);
        }
    }

    /** Same rule for result codes: a new code fails this until it has a documented meaning. */
    public function test_every_tool_result_code_is_listed_with_a_meaning(): void
    {
        $codes = $this->contract()['tool_result_codes'];

        foreach ((new ReflectionClass(\App\Services\Tools\ToolResult::class))->getConstants() as $code) {
            $this->assertArrayHasKey($code, $codes);
            $this->assertNotSame('(no meaning documented)', $codes[$code], "{$code} has no documented meaning.");
        }

        $this->assertStringContainsString('of work, not', $this->contract()['bounds']['runtime_note']);
    }

    public function test_bounds_approval_and_purpose_come_from_config_registry_and_prompt(): void
    {
        config(['agrivisit.agent.max_iterations' => 4]);

        $contract = $this->contract();

        $this->assertSame(4, $contract['bounds']['max_iterations']);
        $this->assertStringContainsString('Limit: 4.', $contract['stop_reasons'][StopReason::ITERATION_CAP]);
        $this->assertSame(['schedule_visit', 'log_followup'], $contract['approval_boundary']['tools_requiring_approval']);
        $this->assertStringContainsString('extension officer', $contract['purpose']);
        $this->assertStringContainsString('One tool call per turn.', implode(' ', $contract['prohibited']['prompt_constraints']));
        $this->assertContains('steps', $contract['evidence']['run_fields']);
        $this->assertContains('prompt_version', $contract['evidence']['run_fields']);
    }

    public function test_the_command_prints_markdown_and_json(): void
    {
        $this->artisan('agrivisit:agent-contract')
            ->expectsOutputToContain('# AgriVisit Agent Task Contract')
            ->assertExitCode(0);

        $this->artisan('agrivisit:agent-contract --json')
            ->expectsOutputToContain('"stop_reasons"')
            ->assertExitCode(0);
    }
}
