<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\Agent\StopReason;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Llm\FunctionCalling\ToolCall;
use App\Services\Llm\LlmException;
use App\Services\Tools\SchemaValidator;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolPolicy;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\Fakes\SpyToolInvoker;
use Tests\TestCase;

class BoundedAgentLoopTest extends TestCase
{
    use RefreshDatabase;

    private const GOAL = 'Prepare and schedule a follow-up visit for farm UG-KYA-012.';

    private string $dir;

    private SpyToolInvoker $spy;

    private float $now = 1000.0;

    private array $limits = [
        'max_iterations'           => 6,
        'max_consecutive_failures' => 3,
        'max_runtime_seconds'      => 120,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-agent-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        $farms = [[
            'farm_id'            => 'UG-KYA-012',
            'district'           => 'Kyankwanzi',
            'crops'              => [['crop' => 'maize'], ['crop' => 'beans']],
            'last_visit'         => '2026-05-22',
            'outstanding_issues' => ['Leaf damage on the maize plot; cause not confirmed.'],
            'previous_advice'    => [],
        ]];

        foreach (range(1, 5) as $n) {
            $farms[] = ['farm_id' => sprintf('UG-KYA-%03d', $n), 'district' => 'Kyankwanzi',
                'crops' => [['crop' => 'cassava']], 'last_visit' => '2026-04-01',
                'outstanding_issues' => [], 'previous_advice' => []];
        }

        file_put_contents($this->dir . '/farms.json', json_encode($farms));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));

        $this->spy = new SpyToolInvoker(
            $this->app->make(ToolRegistry::class),
            $this->app->make(SchemaValidator::class),
            $this->app->make(ToolPolicy::class),
            $this->app->make(ToolCallLog::class),
        );
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

    /**
     * Runs the loop, then checks the two properties every run must have:
     * no call was ever approved (rule 2), and a trace was written (rule 6).
     */
    private function runLoop(Fake $fake, string $goal = self::GOAL): AgentRun
    {
        $loop = new BoundedAgentLoop(
            $this->app->make(RestrictedTopicGuard::class),
            $fake,
            $this->spy,
            new AgentTraceWriter($this->dir . '/runs'),
            $this->limits,
            fn (): float => $this->now,
        );

        $run = $loop->run($goal, [['name' => 'farm_profile_lookup']], 'Test system prompt.');

        $this->assertNotSame(AgentRun::RUNNING, $run->status);
        $this->assertContains($run->status, StopReason::all());
        $this->assertNeverApproved();
        $this->assertTraceWritten($run);

        return $run;
    }

    private function assertNeverApproved(): void
    {
        foreach ($this->spy->calls as $call) {
            $this->assertFalse($call['approved'], "The loop invoked {$call['name']} with approval.");
        }
    }

    private function assertTraceWritten(AgentRun $run): void
    {
        $file = "{$this->dir}/runs/{$run->runId}.json";
        $this->assertFileExists($file);

        $trace = json_decode(file_get_contents($file), true);
        $this->assertSame($run->status, $trace['stop_reason']);
        $this->assertCount(count($run->steps), $trace['steps']);
        $this->assertSame($this->limits, $trace['limits']);
    }

    private static function lookup(string $farmId, string $plan = ''): \App\Services\Llm\FunctionCalling\ModelReply
    {
        return Fake::call('farm_profile_lookup', ['farm_id' => $farmId], $plan);
    }

    private function visitArgs(): array
    {
        return [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->addWeeks(2)->toDateString(),
            'purpose'    => 'Confirm the cause of the maize leaf damage.',
        ];
    }

    // 1
    public function test_two_tool_calls_then_text_reaches_the_goal(): void
    {
        $fake = new Fake(
            self::lookup('UG-KYA-012', 'I will look up the farm first.'),
            self::lookup('UG-KYA-001'),
            Fake::text('Preparation summary: maize leaf damage to confirm.'),
        );

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::GOAL_REACHED, $run->status);
        $this->assertCount(3, $run->steps);
        $this->assertSame('Preparation summary: maize leaf damage to confirm.', $run->summary);
        $this->assertSame('I will look up the farm first.', $run->steps[0]->plan);
        $this->assertNull($run->steps[2]->tool);

        // The assistant turn is resent verbatim and the result is keyed by tool_use_id.
        $second = $fake->requests[1]['messages'];
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame($run->history()[1]['content'], $second[1]['content']);
        $this->assertSame($second[1]['content'][1]['id'], $second[2]['content'][0]['tool_use_id']);
        $this->assertSame('tool_result', $second[2]['content'][0]['type']);
        $this->assertFalse($second[2]['content'][0]['is_error']);
    }

    // 2
    public function test_a_client_that_always_calls_a_tool_hits_the_iteration_cap(): void
    {
        $farms = ['UG-KYA-012', 'UG-KYA-001', 'UG-KYA-002', 'UG-KYA-003', 'UG-KYA-004', 'UG-KYA-005'];
        $fake  = new Fake(...array_map(fn ($f) => self::lookup($f), $farms));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::ITERATION_CAP, $run->status);
        $this->assertCount(6, $run->steps);
        $this->assertSame(6, $fake->callCount());
        $this->assertSame(['OK'], array_unique(array_map(fn ($s) => $s->outcome, $run->steps)));
        $this->assertNotSame('', $run->summary);
        $this->assertNotNull($run->endedAt);
    }

    // 3
    public function test_the_same_call_twice_stops_with_no_progress_and_is_traced(): void
    {
        $fake = new Fake(self::lookup('UG-KYA-012'), self::lookup('UG-KYA-012'));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::NO_PROGRESS, $run->status);
        $this->assertCount(2, $run->steps);
        $this->assertSame(StopReason::NO_PROGRESS, $run->steps[1]->outcome);
        $this->assertSame(['farm_id' => 'UG-KYA-012'], $run->steps[1]->arguments);
        $this->assertCount(1, $this->spy->calls, 'The repeat must not be invoked.');
    }

    // 4
    public function test_the_same_tool_with_different_arguments_does_not_stop(): void
    {
        $fake = new Fake(self::lookup('UG-KYA-012'), self::lookup('UG-KYA-001'), Fake::text('Done.'));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::GOAL_REACHED, $run->status);
        $this->assertCount(2, $this->spy->calls);
    }

    // 5
    public function test_arguments_differing_only_in_key_order_are_the_same_call(): void
    {
        $a = new ToolCall('1', 'schedule_visit', ['farm_id' => 'UG-KYA-012', 'purpose' => 'p', 'visit_date' => '2026-12-01']);
        $b = new ToolCall('2', 'schedule_visit', ['visit_date' => '2026-12-01', 'purpose' => 'p', 'farm_id' => 'UG-KYA-012']);
        $this->assertSame($a->signature(), $b->signature());

        $fake = new Fake(
            Fake::call('log_followup', ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-9999', 'notes' => 'n', 'outcome' => 'ongoing']),
            Fake::call('log_followup', ['outcome' => 'ongoing', 'notes' => 'n', 'visit_id' => 'V-9999', 'farm_id' => 'UG-KYA-012']),
        );

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::NO_PROGRESS, $run->status);
        $this->assertCount(1, $this->spy->calls);
    }

    // 6
    public function test_three_consecutive_failures_stop_with_repeated_failure(): void
    {
        $fake = new Fake(self::lookup('UG-AAA-001'), self::lookup('UG-AAA-002'), self::lookup('UG-AAA-003'));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::REPEATED_FAILURE, $run->status);
        $this->assertCount(3, $run->steps);
        $this->assertSame(3, $run->consecutiveFailures);
        $this->assertSame(ToolResult::RESOURCE_NOT_FOUND, $run->steps[2]->outcome);
    }

    // 7
    public function test_a_success_between_failures_resets_the_counter(): void
    {
        $fake = new Fake(
            self::lookup('UG-AAA-001'),
            self::lookup('UG-KYA-012'),
            self::lookup('UG-AAA-002'),
            Fake::text('Recovered.'),
        );

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::GOAL_REACHED, $run->status);
        $this->assertSame(1, $run->consecutiveFailures);
        $this->assertSame([ToolResult::RESOURCE_NOT_FOUND, 'OK', ToolResult::RESOURCE_NOT_FOUND, 'OK'],
            array_map(fn ($s) => $s->outcome, $run->steps));

        // A failed result goes back to the model flagged as an error.
        $this->assertTrue($fake->requests[1]['messages'][2]['content'][0]['is_error']);
    }

    // 8
    public function test_a_write_tool_suspends_for_approval_and_writes_nothing(): void
    {
        $before = Visit::count();

        $fake = new Fake(self::lookup('UG-KYA-012'), Fake::call('schedule_visit', $this->visitArgs()));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::AWAITING_APPROVAL, $run->status);
        $this->assertSame($before, Visit::count());
        $this->assertSame('schedule_visit', $run->pending['tool']);
        $this->assertSame($this->visitArgs(), $run->pending['arguments']);
        $this->assertStringStartsWith('toolu_', $run->pending['tool_use_id']);
        $this->assertSame(ToolResult::APPROVAL_REQUIRED, $run->steps[1]->outcome);
    }

    // 9
    public function test_a_restricted_goal_is_refused_before_the_model_is_called(): void
    {
        $fake = new Fake();

        $run = $this->runLoop($fake, 'What dose of pesticide should UG-KYA-012 spray on the maize?');

        $this->assertSame(StopReason::GUARD_REFUSED, $run->status);
        $this->assertSame(0, $fake->callCount());
        $this->assertSame([], $run->steps);
        $this->assertSame([], $this->spy->calls);
    }

    // 10
    public function test_a_model_error_mid_run_keeps_the_steps_taken(): void
    {
        $fake = new Fake(self::lookup('UG-KYA-012'), new LlmException('boom', 0, null, 503, LlmException::UNAVAILABLE));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::MODEL_ERROR, $run->status);
        $this->assertCount(1, $run->steps);
        $this->assertSame('farm_profile_lookup', $run->steps[0]->tool);
        $this->assertStringContainsString('503', $run->summary);
    }

    // 11
    public function test_the_runtime_budget_stops_the_run(): void
    {
        $advance = function (int $n) {
            $this->now += 100;

            return self::lookup($n === 1 ? 'UG-KYA-012' : 'UG-KYA-001');
        };

        $fake = new Fake($advance, $advance, fn () => Fake::text('never reached'));

        $run = $this->runLoop($fake);

        $this->assertSame(StopReason::RUNTIME_BUDGET, $run->status);
        $this->assertCount(2, $run->steps);
        $this->assertSame(2, $fake->callCount());
    }

    // 12
    public function test_the_loop_never_invokes_a_tool_with_approval(): void
    {
        $scenarios = [
            [self::lookup('UG-KYA-012'), Fake::call('schedule_visit', $this->visitArgs())],
            [self::lookup('UG-KYA-012'), Fake::call('log_followup', ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-0001', 'notes' => 'n', 'outcome' => 'resolved']), Fake::text('No such visit; stopping.')],
            [self::lookup('UG-AAA-001'), self::lookup('UG-AAA-002'), self::lookup('UG-AAA-003')],
            [self::lookup('UG-KYA-012'), self::lookup('UG-KYA-001'), Fake::text('Done.')],
        ];

        foreach ($scenarios as $replies) {
            $this->runLoop(new Fake(...$replies));
        }

        $this->assertNotEmpty($this->spy->calls);
        $this->assertSame([false], array_values(array_unique(array_column($this->spy->calls, 'approved'))));

        // Nothing was written by any scenario, including the two write tools.
        $this->assertSame(0, Visit::count());
    }

    // 13
    public function test_every_run_writes_a_trace_with_one_entry_per_step(): void
    {
        $run = $this->runLoop(new Fake(self::lookup('UG-KYA-012'), Fake::text('Summary.')));

        $trace = json_decode(file_get_contents("{$this->dir}/runs/{$run->runId}.json"), true);

        $this->assertSame(self::GOAL, $trace['goal']);
        $this->assertSame(StopReason::GOAL_REACHED, $trace['stop_reason']);
        $this->assertSame('Summary.', $trace['summary']);
        $this->assertCount(2, $trace['steps']);
        $this->assertSame(
            ['iteration', 'plan', 'tool', 'arguments', 'outcome', 'message', 'duration_ms', 'input_tokens', 'output_tokens', 'tool_calls_proposed'],
            array_keys($trace['steps'][0])
        );
        $this->assertSame(['input' => 220, 'output' => 35], $trace['total_tokens']);

        // The guard path writes one too, with no steps.
        $refused = $this->runLoop(new Fake(), 'Give me the dosage for spraying beans.');
        $this->assertFileExists("{$this->dir}/runs/{$refused->runId}.json");
    }
}
