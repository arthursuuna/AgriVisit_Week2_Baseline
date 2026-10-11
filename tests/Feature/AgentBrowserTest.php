<?php

namespace Tests\Feature;

use App\Models\AgentRunRecord;
use App\Models\ToolApproval;
use App\Models\Visit;
use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\StopReason;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Retrieval\Retriever;
use App\Services\Tools\ToolCallLog;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\Fakes\FakeRetriever;
use Tests\TestCase;

/**
 * Part D, driven as an officer would: post a goal, read the run page, decide.
 */
class AgentBrowserTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->dir = sys_get_temp_dir() . '/agrivisit-browser-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
            'last_visit' => '2026-05-22', 'outstanding_issues' => [], 'previous_advice' => [],
        ]]));

        config(['agrivisit.agent.trace_path' => $this->dir . '/runs']);
        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));
        $this->app->instance(AgentTraceWriter::class, new AgentTraceWriter($this->dir . '/runs'));
        $this->app->instance(Retriever::class, new FakeRetriever());
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

    private function model(...$replies): void
    {
        $this->app->instance(AgentModelClient::class, new Fake(...$replies));
    }

    private function visitArgs(): array
    {
        return ['farm_id' => 'UG-KYA-012', 'visit_date' => now()->addWeek()->toDateString(), 'purpose' => 'Check the maize.'];
    }

    public function test_the_goal_page_shows_the_officer(): void
    {
        $this->get(route('agent.index'))->assertOk()
            ->assertSee(config('agrivisit.officer.id'))
            ->assertSee('Prepare and schedule a follow-up visit');
    }

    public function test_awaiting_approval_then_approve_continues_and_writes_one_row(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012'], 'Looking up the farm first.'),
            Fake::call('schedule_visit', $this->visitArgs(), 'Proposing a visit next week.'),
            Fake::text('The visit is booked.'),
        );

        $response = $this->post(route('agent.start'), ['goal' => 'Prepare and schedule a follow-up visit for farm UG-KYA-012']);
        $run = AgentRunRecord::sole();
        $response->assertRedirect(route('agent.runs.show', $run->run_id));

        $this->get(route('agent.runs.show', $run->run_id))->assertOk()
            ->assertSee('Looking up the farm first.')
            ->assertSee('Nothing has been written yet.')
            ->assertSee('Approve')
            ->assertSee($run->approval['token']);

        $this->assertSame(0, Visit::count());

        $this->post(route('agent.runs.decide', $run->run_id), ['token' => $run->approval['token'], 'decision' => 'approve'])
            ->assertRedirect(route('agent.runs.show', $run->run_id));

        $this->assertSame(1, Visit::count());
        $this->assertSame(StopReason::GOAL_REACHED, $run->fresh()->status);

        $this->get(route('agent.runs.show', $run->run_id))
            ->assertSee('The visit is booked.')
            ->assertDontSee('Nothing has been written yet.');
    }

    public function test_reject_closes_the_run_with_nothing_written(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
        );

        $this->post(route('agent.start'), ['goal' => 'Prepare and schedule a follow-up visit for farm UG-KYA-012']);
        $run = AgentRunRecord::sole();

        $this->followingRedirects()
            ->post(route('agent.runs.decide', $run->run_id), ['token' => $run->approval['token'], 'decision' => 'reject'])
            ->assertSee('Declined — the run is closed')
            ->assertSee('Rejected — recorded as');

        $this->assertSame(0, Visit::count());
        $this->assertSame(StopReason::REJECTED, $run->fresh()->status);
        $this->assertSame(ToolApproval::REJECTED, ToolApproval::sole()->decision);
    }

    public function test_a_refused_goal_ends_the_run_with_no_retry(): void
    {
        $this->model();

        $this->post(route('agent.start'), ['goal' => 'What dose of fungicide for the maize at UG-KYA-012?']);
        $run = AgentRunRecord::sole();

        $this->assertSame(StopReason::GUARD_REFUSED, $run->status);

        $this->get(route('agent.runs.show', $run->run_id))
            ->assertSee('Refused — this topic is outside what AgriVisit can help with')
            ->assertSee('does not provide pesticide doses')
            ->assertDontSee('Retry')
            ->assertDontSee('name="decision"', false);
    }

    public function test_a_refused_agent_query_ends_the_run(): void
    {
        $this->model(
            Fake::call('search_guidance', ['query' => 'fungicide application rate for maize leaf blight']),
            Fake::call('search_guidance', ['query' => 'reworded: how much product per knapsack']),
        );

        $this->post(route('agent.start'), ['goal' => 'Prepare me for a visit to farm UG-KYA-012']);
        $run = AgentRunRecord::sole();

        $this->assertSame(StopReason::GUARD_REFUSED, $run->status);
        $this->assertCount(1, $run->steps);
        $this->get(route('agent.runs.show', $run->run_id))->assertSee('restricted-topic guard (dosing)');
    }

    public function test_the_runs_list_and_trace_link(): void
    {
        $this->model(Fake::text('Nothing to do.'));
        $this->post(route('agent.start'), ['goal' => 'Say hello']);
        $run = AgentRunRecord::sole();

        $this->get(route('agent.runs.index'))->assertOk()->assertSee('Say hello')->assertSee('GOAL_REACHED');

        $this->get(route('agent.runs.trace', [$run->run_id, $run->trace_files[0]]))->assertOk();
        $this->get(route('agent.runs.trace', [$run->run_id, 'other-run.json']))->assertNotFound();
    }

    private function heldRun(): AgentRunRecord
    {
        // No reply after the proposal: a closure that called the model would fail.
        $this->model(Fake::call('schedule_visit', $this->visitArgs(), 'Proposing a visit next week.'));
        $this->post(route('agent.start'), ['goal' => 'Schedule a follow-up visit for farm UG-KYA-012']);

        return AgentRunRecord::sole();
    }

    public function test_a_run_whose_approval_window_passed_is_closed_when_the_list_is_read(): void
    {
        $run = $this->heldRun();
        $this->travel(config('agrivisit.tools.approval_expiry_minutes') + 1)->minutes();

        $this->get(route('agent.runs.index'))->assertOk()
            ->assertSee('closed — approval expired')
            ->assertDontSee('waiting on officer');

        $run->refresh();
        $this->assertSame(StopReason::REJECTED, $run->status);
        $this->assertTrue($run->approvalExpired());
        $this->assertNull($run->approval);
        $this->assertCount(2, $run->trace_files);
        $this->assertSame(ToolApproval::EXPIRED, ToolApproval::sole()->decision);
        $this->assertSame(0, Visit::count());

        $this->get(route('agent.runs.show', $run->run_id))
            ->assertSee('Expired — the request was never decided and the run is closed')
            ->assertSee('<span class="mark declined">Expired</span>', false)
            ->assertDontSee('name="decision"', false);
    }

    public function test_the_run_page_alone_closes_a_lapsed_run(): void
    {
        $run = $this->heldRun();
        $this->travel(config('agrivisit.tools.approval_expiry_minutes') + 1)->minutes();

        $this->get(route('agent.runs.show', $run->run_id))->assertSee('Expired — the request was never decided');
        $this->assertSame(StopReason::REJECTED, $run->fresh()->status);
    }

    public function test_a_run_inside_its_approval_window_stays_waiting(): void
    {
        $run = $this->heldRun();
        $this->travel(config('agrivisit.tools.approval_expiry_minutes') - 1)->minutes();

        $this->get(route('agent.runs.index'))->assertSee('waiting on officer');
        $this->get(route('agent.runs.show', $run->run_id))->assertSee('Nothing has been written yet.');

        $this->assertSame(StopReason::AWAITING_APPROVAL, $run->fresh()->status);
        $this->assertSame(0, ToolApproval::count());
    }

    public function test_the_final_answer_is_printed_once(): void
    {
        $this->model(Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012'], 'Looking up the farm.'),
            Fake::text('The farm grows maize and has no open issues.'));
        $this->post(route('agent.start'), ['goal' => 'What does farm UG-KYA-012 grow?']);

        $html = $this->get(route('agent.runs.show', AgentRunRecord::sole()->run_id))->assertOk()
            ->assertSee('The agent\'s answer is in the', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'The farm grows maize and has no open issues.'));
    }
}
