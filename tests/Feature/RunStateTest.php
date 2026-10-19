<?php

namespace Tests\Feature;

use App\Models\AgentRunRecord;
use App\Models\ToolApproval;
use App\Models\Visit;
use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentRunStore;
use App\Services\AgentTask\AgentRunner;
use App\Services\AgentTask\IllegalRunTransition;
use App\Services\AgentTask\RunState;
use App\Services\AgentTask\RunStateModel;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Tools\ApprovalOutcome;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\TestCase;

/**
 * Week 6 Phase 1: every illegal move of a run throws, and nothing changes when
 * it does: no model call, no write, no approval record, no new trace file, and
 * the stored row exactly as it was.
 */
class RunStateTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private Fake $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-state-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([
            ['farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
             'last_visit' => '2026-05-22', 'outstanding_issues' => ['Leaf damage on the maize.'], 'previous_advice' => []],
        ]));

        config(['agrivisit.agent.trace_path' => $this->dir . '/runs', 'agrivisit.officer.id' => 'officer-test-9']);
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

    private function model(...$replies): void
    {
        $this->fake = new Fake(...$replies);
        $this->app->instance(AgentModelClient::class, $this->fake);
    }

    /** lookup → schedule_visit (held), plus any replies after the decision */
    private function suspended(...$after): AgentRunRecord
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', [
                'farm_id' => 'UG-KYA-012', 'visit_date' => now()->addWeek()->toDateString(), 'purpose' => 'Check the maize.',
            ]),
            ...$after,
        );

        return app(AgentRunner::class)->start('Prepare and schedule a follow-up visit for farm UG-KYA-012');
    }

    private function rejected(): AgentRunRecord
    {
        $record = $this->suspended();

        return app(AgentRunner::class)->decide($record, $record->approval['token'], 'reject', null)[1];
    }

    private function goalReached(): AgentRunRecord
    {
        $this->model(Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']), Fake::text('Here is the farm.'));

        return app(AgentRunner::class)->start('Prepare me for a visit to farm UG-KYA-012');
    }

    private function snapshot(AgentRunRecord $record): array
    {
        return [
            'model_calls' => $this->fake->callCount(),
            'visits'      => Visit::count(),
            'approvals'   => ToolApproval::count(),
            'traces'      => array_map('basename', glob($this->dir . '/runs/*') ?: []),
            'row'         => array_diff_key($record->fresh()->getAttributes(), ['updated_at' => true]),
        ];
    }

    /** $move must throw IllegalRunTransition and leave everything as it was. */
    private function assertRefused(AgentRunRecord $record, Closure $move, string $message = ''): void
    {
        $before = $this->snapshot($record);

        try {
            $move();
            $this->fail('The move should have thrown IllegalRunTransition. ' . $message);
        } catch (IllegalRunTransition) {
        }

        $this->assertSame($before, $this->snapshot($record), 'Nothing may change when a move is refused. ' . $message);
    }

    private function decide(AgentRunRecord $record, string $decision, ?string $token = null): Closure
    {
        return fn () => app(AgentRunner::class)->decide($record, $token ?? 'any-token', $decision, null);
    }

    // 1
    public function test_a_closed_run_cannot_be_resumed(): void
    {
        $closed = $this->rejected();

        $this->assertRefused($closed, $this->decide($closed, 'approve'), 'through the runner');
        $this->assertRefused($closed, fn () => app(BoundedAgentLoop::class)->resumeApproved(
            app(AgentRunStore::class)->restore($closed), ToolResult::success([])), 'through the loop');

        // The hole found in this phase: the store used to write any status over a closed row.
        $this->assertRefused($closed, function () use ($closed) {
            $run = app(AgentRunStore::class)->restore($closed);
            $run->status = StopReason::AWAITING_APPROVAL;
            app(AgentRunStore::class)->save($run, ['token' => 'x']);
        }, 'through the store');
    }

    // 2
    public function test_a_closed_run_cannot_be_rejected_again(): void
    {
        $closed = $this->rejected();

        $this->assertRefused($closed, $this->decide($closed, 'reject'), 'through the runner');
        $this->assertRefused($closed, fn () => app(BoundedAgentLoop::class)->reject(
            app(AgentRunStore::class)->restore($closed), 'again'), 'through the loop');
    }

    // 3
    public function test_a_run_awaiting_approval_cannot_be_approved_twice(): void
    {
        $record = $this->suspended(Fake::text('Booked.'));
        $token  = $record->approval['token'];

        app(AgentRunner::class)->decide($record, $token, 'approve', null);
        $this->assertSame(1, Visit::count());

        // The same held call again, from the copy read before the first decision.
        $this->assertRefused($record, $this->decide($record, 'approve', $token), 'stale copy');
        $this->assertRefused($record, $this->decide($record->fresh(), 'approve', $token), 'fresh copy');
        $this->assertSame(1, ToolApproval::count());
    }

    // 4
    public function test_a_run_that_is_not_awaiting_approval_cannot_be_approved(): void
    {
        $done = $this->goalReached();

        $this->assertRefused($done, $this->decide($done, 'approve'));

        // In memory: a run already running cannot be resumed (RUNNING → RUNNING).
        $run = new AgentRun('goal', config('agrivisit.agent'), fn () => 0.0);
        $run->begin();
        $this->expectException(IllegalRunTransition::class);
        $run->beginSegment();
    }

    // 5
    public function test_a_run_that_is_not_awaiting_approval_cannot_be_rejected(): void
    {
        $done = $this->goalReached();

        $this->assertRefused($done, $this->decide($done, 'reject'));

        // In memory: REJECTED needs a held call; a running run has none.
        $run = new AgentRun('goal', config('agrivisit.agent'), fn () => 0.0);
        $run->begin();
        $this->expectException(IllegalRunTransition::class);
        $run->stop(StopReason::REJECTED, 'declined');
    }

    // 6
    public function test_a_guard_refused_run_cannot_be_restarted_or_continued(): void
    {
        $this->model();
        $refused = app(AgentRunner::class)->start('Is my cow sick? What medicine for it?');
        $this->assertSame(StopReason::GUARD_REFUSED, $refused->status);
        $this->assertSame(0, $this->fake->callCount());

        $this->assertRefused($refused, $this->decide($refused, 'approve'), 'approve');
        $this->assertRefused($refused, $this->decide($refused, 'reject'), 'reject');
        $this->assertRefused($refused, fn () => app(AgentRunStore::class)->restore($refused)->begin(), 'begin again');
        $this->assertRefused($refused, fn () => app(BoundedAgentLoop::class)->resumeApproved(
            app(AgentRunStore::class)->restore($refused), ToolResult::success([])), 'continue');

        // A fresh run under the same id, saved over the refused one.
        $this->assertRefused($refused, function () use ($refused) {
            $run = new AgentRun($refused->goal, $refused->limits, fn () => 0.0, $refused->run_id);
            $run->begin();
            $run->stop(StopReason::GOAL_REACHED, 'done');
            app(AgentRunStore::class)->save($run);
        }, 'restart under the same id');
    }

    public function test_a_waiting_run_takes_exactly_one_decision_at_the_store(): void
    {
        $record = $this->suspended();

        // Saving the same suspended segment again would be AWAITING → AWAITING.
        $this->assertRefused($record, fn () => app(AgentRunStore::class)->save(
            app(AgentRunStore::class)->restore($record), ['token' => 'x']));
    }

    /** The full legal path, with the state asserted at each step, in memory and in the table. */
    public function test_the_legal_path_submitted_suspended_approved_resumed_closed(): void
    {
        // In memory.
        $run = new AgentRun('goal', config('agrivisit.agent'), fn () => 0.0);
        $this->assertSame(RunState::NONE, RunState::of($run));
        $run->begin();
        $this->assertSame(RunState::RUNNING, RunState::of($run));
        $run->stop(StopReason::AWAITING_APPROVAL, 'held');
        $this->assertSame(RunState::AWAITING_APPROVAL, RunState::of($run));
        $run->beginSegment();
        $this->assertSame(RunState::RUNNING, RunState::of($run));
        $run->stop(StopReason::GOAL_REACHED, 'done');
        $this->assertSame(RunState::CLOSED, RunState::of($run));

        // Through the runner. While the first segment runs there is no row; while
        // the resumed one runs the row still says what it said before.
        $rows = [];
        $peek = function () use (&$rows) {
            $rows[] = AgentRunRecord::first()?->status;

            return null;
        };

        $this->model(
            fn () => $peek() ?? Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', [
                'farm_id' => 'UG-KYA-012', 'visit_date' => now()->addWeek()->toDateString(), 'purpose' => 'Check the maize.',
            ]),
            fn () => $peek() ?? Fake::text('The visit is booked.'),
        );

        $record = app(AgentRunner::class)->start('Prepare and schedule a follow-up visit for farm UG-KYA-012');
        $this->assertSame(RunState::AWAITING_APPROVAL, RunState::forStatus($record->status));
        $this->assertSame(1, $record->segment);

        [, $after] = app(AgentRunner::class)->decide($record, $record->approval['token'], 'approve', null);
        $this->assertSame(RunState::CLOSED, RunState::forStatus($after->status));
        $this->assertSame(StopReason::GOAL_REACHED, $after->status);
        $this->assertSame(2, $after->segment);
        $this->assertSame([null, StopReason::AWAITING_APPROVAL], $rows);
        $this->assertSame(1, Visit::count());
    }

    /** The race: a page load's lazy expiry against another request moving the same run. */
    public function test_a_lazy_expiry_that_loses_the_race_logs_it_and_changes_nothing(): void
    {
        $record = $this->suspended();
        $this->travel(config('agrivisit.tools.approval_expiry_minutes') + 1)->minutes();
        Log::spy();

        // Another request holds the run (an officer's decision in progress).
        $lock = Cache::lock("agent-run:{$record->run_id}", 60);
        $this->assertTrue($lock->get());

        $before = $this->snapshot($record);
        $shown  = app(AgentRunner::class)->closeIfLapsed($record);
        $this->assertSame($before, $this->snapshot($record));
        $this->assertSame(StopReason::AWAITING_APPROVAL, $shown->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Lazy expiry lost a race'))->once();

        // The officer's decision against a held run loses the same way, loudly.
        $this->assertRefused($record, $this->decide($record, 'approve', $record->approval['token']));
        $lock->release();

        // Released: the lazy expiry now closes it, once.
        $closed = app(AgentRunner::class)->closeIfLapsed($record);
        $this->assertSame(StopReason::REJECTED, $closed->status);
        $this->assertTrue($closed->approvalExpired());
        $this->assertSame(ToolApproval::EXPIRED, ToolApproval::sole()->decision);

        // A second page load working from the copy read before the close loses too.
        $this->travel(2)->days();
        $before = $this->snapshot($record);
        $this->assertSame(StopReason::REJECTED, app(AgentRunner::class)->closeIfLapsed($record)->status);
        $this->assertSame($before, $this->snapshot($record));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Lazy expiry lost a race'))->twice();
    }

    public function test_a_refused_decision_in_the_browser_is_shown_as_already_decided(): void
    {
        $record = $this->suspended(Fake::text('Booked.'));
        $token  = $record->approval['token'];

        $this->post(route('agent.runs.decide', $record->run_id), ['token' => $token, 'decision' => 'approve']);
        $this->post(route('agent.runs.decide', $record->run_id), ['token' => $token, 'decision' => 'approve'])
            ->assertRedirect(route('agent.runs.show', $record->run_id))
            ->assertSessionHas('decision.status', ApprovalOutcome::ALREADY_USED);

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, ToolApproval::count());
    }

    // Part C

    public function test_every_illegal_pair_has_a_reason_and_every_legal_one_a_trigger(): void
    {
        $this->assertCount(6, RunState::transitions());
        $this->assertCount(6, RunState::illegal());

        foreach (RunState::illegal() as $pair => $why) {
            $this->assertNotSame('(no reason documented)', $why, "{$pair} has no reason.");
        }

        foreach (RunState::transitions() as $t) {
            $this->assertNotSame('', $t['trigger']);
        }

        // Every stop reason closes the run or suspends it; none is unaccounted for.
        foreach (StopReason::all() as $reason) {
            $this->assertContains(RunState::forStatus($reason), [RunState::AWAITING_APPROVAL, RunState::CLOSED]);
        }
    }

    public function test_the_state_model_documents_every_agent_runs_column(): void
    {
        $model = (new RunStateModel(config('agrivisit.agent.trace_path')))->toArray();

        $this->assertSame(Schema::getColumnListing('agent_runs'), array_keys($model['state_lives_in']['columns']));
        $this->assertNotContains('(undocumented)', $model['state_lives_in']['columns']);
    }

    public function test_the_command_prints_every_section_and_json(): void
    {
        $this->assertSame(0, Artisan::call('agrivisit:state-model'));
        $output = Artisan::output();

        foreach (['## States', '## Transitions', '## Illegal moves', '## Where state lives', '## What is not state'] as $section) {
            $this->assertStringContainsString($section, $output);
        }

        $this->assertSame(0, Artisan::call('agrivisit:state-model', ['--json' => true]));
        $this->assertSame(RunState::illegal(), json_decode(Artisan::output(), true)['illegal']);
    }
}
