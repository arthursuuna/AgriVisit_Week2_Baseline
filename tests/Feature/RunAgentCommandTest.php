<?php

namespace Tests\Feature;

use App\Services\Agent\AgentTraceWriter;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Support\FarmProfileRepository;
use App\Services\Tools\ToolCallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\TestCase;

class RunAgentCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-command-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
            'last_visit' => '2026-05-22', 'outstanding_issues' => [], 'previous_advice' => [],
        ]]));

        config(['agrivisit.agent.trace_path' => $this->dir . '/runs']);
        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));
        $this->app->instance(AgentTraceWriter::class, new AgentTraceWriter($this->dir . '/runs'));
    }

    protected function tearDown(): void
    {
        foreach (['tool-logs', 'runs'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_run_exits_zero_and_prints_its_stop_reason(): void
    {
        $fake = new Fake(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012'], 'Looking up the farm first.'),
            Fake::text('The farm grows maize; nothing outstanding.'),
        );
        $this->app->instance(AgentModelClient::class, $fake);

        $this->artisan('agrivisit:agent', ['goal' => 'Prepare me for a visit to farm UG-KYA-012'])
            ->expectsOutputToContain('GOAL_REACHED')
            ->assertExitCode(0);

        // The system prompt was the versioned file, and the trace records its version.
        $this->assertStringContainsString('## Failure behaviour', $fake->requests[0]['system']);
        $this->assertStringNotContainsString('{{today}}', $fake->requests[0]['system']);

        $trace = json_decode(file_get_contents(glob($this->dir . '/runs/*.json')[0]), true);
        $this->assertSame('v1.0', $trace['prompt_version']);
        $this->assertSame(config('agrivisit.llm.anthropic.model'), $trace['model']);
    }

    public function test_dry_run_does_not_call_the_model(): void
    {
        $fake = new Fake();
        $this->app->instance(AgentModelClient::class, $fake);

        $this->artisan('agrivisit:agent', ['goal' => 'anything', '--dry-run' => true])
            ->expectsOutputToContain('search_guidance')
            ->assertExitCode(0);

        $this->assertSame(0, $fake->callCount());
    }
}
