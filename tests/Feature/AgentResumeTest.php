<?php

namespace Tests\Feature;

use App\Models\AgentRunRecord;
use App\Models\ToolApproval;
use App\Models\Visit;
use App\Services\Agent\AgentRun;
use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentRunStore;
use App\Services\AgentTask\AgentRunner;
use App\Services\AgentTask\IllegalRunTransition;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Tools\ApprovalOutcome;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\TestCase;

/**
 * Part C: a run suspended for approval, carried across the officer's decision
 * by the Week 4 approval flow. Everything below goes through AgentRunner, the
 * real ApprovalService and the real gate; only the model is scripted.
 */
class AgentResumeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-resume-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([
            ['farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
             'last_visit' => '2026-05-22', 'outstanding_issues' => ['Leaf damage on the maize.'], 'previous_advice' => []],
            ['farm_id' => 'UG-KYA-001', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'beans']],
             'last_visit' => '2026-04-01', 'outstanding_issues' => [], 'previous_advice' => []],
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

    private function model(...$replies): Fake
    {
        $fake = new Fake(...$replies);
        $this->app->instance(AgentModelClient::class, $fake);

        return $fake;
    }

    private function visitArgs(?string $date = null): array
    {
        return [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => $date ?? now()->addWeek()->toDateString(),
            'purpose'    => 'Confirm the cause of the maize leaf damage.',
        ];
    }

    /** lookup → schedule_visit (held) */
    private function suspended(?int $cap = null, ?array $args = null): AgentRunRecord
    {
        $record = app(AgentRunner::class)->start('Prepare and schedule a follow-up visit for farm UG-KYA-012', $cap);

        $this->assertSame(StopReason::AWAITING_APPROVAL, $record->status);
        $this->assertNotNull($record->approval['token']);

        return $record;
    }

    private function decide(AgentRunRecord $record, string $decision, ?string $reason = null): array
    {
        return app(AgentRunner::class)->decide($record, $record->approval['token'], $decision, $reason);
    }

    // 1
    public function test_approve_continues_the_run_writes_one_record_and_carries_iterations(): void
    {
        $fake = $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
            Fake::text('The visit is booked for next week.'),
        );

        $record = $this->suspended();
        $this->assertSame(2, $record->iterations);
        $this->assertSame(0, Visit::count(), 'Nothing written while waiting.');
        $firstTrace = $this->dir . '/runs/' . $record->trace_files[0];
        $firstHash  = sha1_file($firstTrace);

        [$outcome, $after] = $this->decide($record, 'approve');

        $this->assertSame(ApprovalOutcome::APPROVED, $outcome->status);
        $this->assertSame(StopReason::GOAL_REACHED, $after->status);
        $this->assertSame(1, Visit::count(), 'Exactly one record written.');
        $this->assertSame(3, $after->iterations, 'Iterations carry over: 2 before, 1 after.');
        $this->assertSame(3, $fake->callCount());
        $this->assertNull($after->approval);

        // The Week 4 record: approved, by the configured officer, with the created ref, stamped on the visit.
        $approval = ToolApproval::sole();
        $this->assertSame(ToolApproval::APPROVED, $approval->decision);
        $this->assertSame('officer-test-9', $approval->decided_by);
        $this->assertSame($record->run_id, $approval->session_id);
        $this->assertSame(Visit::sole()->visit_ref, $approval->result_ref);
        $this->assertSame($approval->approval_ref, Visit::sole()->approval_ref);

        // The model received the real result under the same tool_use_id.
        $messages  = $fake->requests[2]['messages'];
        $toolUseId = $record->pending['tool_use_id'];
        $result    = end($messages)['content'][0];
        $this->assertSame($toolUseId, $result['tool_use_id']);
        $this->assertFalse($result['is_error']);
        $this->assertStringContainsString(Visit::sole()->visit_ref, $result['content']);

        // ...and is told the officer approved it, so it does not report the write as still pending.
        $content = json_decode($result['content'], true);
        $this->assertSame('approved', $content['officer_decision']);
        $this->assertSame($approval->approval_ref, $content['approval_ref']);
        $this->assertStringContainsString('has been executed', $content['note']);

        // Traces: the first is untouched; the continuation is a new file naming it.
        $this->assertSame($firstHash, sha1_file($firstTrace), 'The first trace was never rewritten.');
        $this->assertSame([$record->run_id . '.json', $record->run_id . '.part2.json'], $after->trace_files);
        $part2 = json_decode(file_get_contents($this->dir . '/runs/' . $after->trace_files[1]), true);
        $this->assertSame($record->run_id, $part2['run_id']);
        $this->assertSame($record->run_id . '.json', $part2['resumed_from']);
        $this->assertSame(2, $part2['segment']);
        $this->assertSame(StopReason::GOAL_REACHED, $part2['stop_reason']);
    }

    // 2
    public function test_approval_authorises_but_does_not_exempt_a_past_date_still_fails(): void
    {
        config(['agrivisit.tools.approval_expiry_minutes' => 60]);

        // Proposed for today at 23:50; the officer approves at 00:10 the next day,
        // inside the expiry, by which time the date is in the past.
        $this->travelTo(now()->setTime(23, 50));

        $fake = $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs(now()->toDateString())),
            Fake::text('The approved booking failed because the date had passed.'),
        );

        $record = $this->suspended();

        $this->travel(20)->minutes();

        [$outcome, $after] = $this->decide($record, 'approve');

        $this->assertSame(ApprovalOutcome::APPROVED, $outcome->status);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $outcome->toolResult->code);
        $this->assertStringContainsString('in the past', $outcome->toolResult->message);
        $this->assertSame(0, Visit::count(), 'Nothing written.');
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, ToolApproval::sole()->result_code);

        // The model is told, the failure counts, and the run carries on to its end.
        $this->assertTrue(end($fake->requests[2]['messages'])['content'][0]['is_error']);
        $this->assertSame(StopReason::GOAL_REACHED, $after->status);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $after->steps[2]['outcome']);
    }

    // 3
    public function test_reject_ends_the_run_writes_nothing_and_calls_the_model_no_more(): void
    {
        $fake = $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
            // The re-proposal a model would make. It must never be requested.
            Fake::call('schedule_visit', $this->visitArgs(now()->addWeeks(2)->toDateString())),
        );

        $record = $this->suspended();

        [$outcome, $after] = $this->decide($record, 'reject', 'The farmer is away that week');

        $this->assertSame(ApprovalOutcome::REJECTED, $outcome->status);
        $this->assertSame(StopReason::REJECTED, $after->status);
        $this->assertSame(0, Visit::count());
        $this->assertSame(2, $fake->callCount(), 'No further model call after a rejection.');
        $this->assertStringContainsString('The farmer is away that week', $after->summary);
        $this->assertSame(ToolApproval::REJECTED, ToolApproval::sole()->decision);

        // The conversation is complete: the held tool_use got an is_error result saying so.
        $messages = $after->messages;
        $last     = end($messages)['content'][0];
        $this->assertSame($record->pending['tool_use_id'], $last['tool_use_id']);
        $this->assertTrue($last['is_error']);
        $this->assertStringContainsString('declined', $last['content']);
        $this->assertFileExists($this->dir . '/runs/' . $record->run_id . '.part2.json');
    }

    // 4
    public function test_a_suspended_run_reloads_with_identical_messages_and_signatures(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
        );

        $runner = app(AgentRunner::class);
        $record = $runner->start('Prepare and schedule a follow-up visit for farm UG-KYA-012');

        // What the loop held in memory when it suspended, as written to its trace.
        $trace    = json_decode(file_get_contents($this->dir . '/runs/' . $record->trace_files[0]), true);
        $restored = app(AgentRunStore::class)->restore($record->fresh());

        $this->assertSame($trace['messages'], $restored->messages());
        $this->assertSame($trace['signatures'], $restored->signatures());
        $this->assertSame($trace['pending'], $restored->pending);
        $this->assertSame($trace['iterations'], $restored->iterations);
        $this->assertSame($trace['system_prompt'], $restored->systemPrompt);
        $this->assertCount(2, $restored->signatures());
    }

    // 5
    public function test_resume_with_one_iteration_left_stops_at_the_cap_and_keeps_the_work(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-001']),
        );

        $record = $this->suspended(cap: 3);
        $this->assertSame(3, $record->limits['max_iterations']);

        // The override is the run's, not the process's: a later request still uses 3.
        config(['agrivisit.agent.max_iterations' => 6]);

        [, $after] = $this->decide($record, 'approve');

        $this->assertSame(StopReason::ITERATION_CAP, $after->status);
        $this->assertSame(3, $after->iterations);
        $this->assertSame(1, Visit::count(), 'The approved write stands.');
        $this->assertSame(['farm_profile_lookup', 'schedule_visit', 'schedule_visit', 'farm_profile_lookup'],
            array_column($after->steps, 'tool'), 'Every step is kept, including the officer-approved execution.');
    }

    public function test_an_expired_approval_ends_the_run_as_rejected_with_its_own_message(): void
    {
        $fake = $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
        );

        $record = $this->suspended();
        $this->travel(config('agrivisit.tools.approval_expiry_minutes') + 1)->minutes();

        [$outcome, $after] = $this->decide($record, 'approve');

        $this->assertSame(ApprovalOutcome::EXPIRED, $outcome->status);
        $this->assertSame(StopReason::REJECTED, $after->status);
        $this->assertStringContainsString('expired', $after->summary);
        $this->assertStringNotContainsString('declined', $after->summary);
        $this->assertSame(0, Visit::count());
        $this->assertSame(2, $fake->callCount());
        $this->assertSame(ToolApproval::EXPIRED, ToolApproval::sole()->decision);
    }

    public function test_a_decision_after_the_store_forgot_the_token_closes_the_run_as_expired(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs(now()->addWeeks(2)->toDateString())),
        );

        $record = $this->suspended();
        $this->travel(2)->days();   // past the store's one-day retention

        [$outcome, $after] = $this->decide($record, 'approve');

        $this->assertSame(ApprovalOutcome::UNKNOWN, $outcome->status);
        $this->assertSame(StopReason::REJECTED, $after->status);
        $this->assertStringContainsString('no longer on file', $after->summary);
        $this->assertSame(0, Visit::count());
    }

    public function test_a_token_that_is_not_this_runs_changes_nothing(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
        );

        $record = $this->suspended();

        [$outcome, $after] = app(AgentRunner::class)->decide($record, 'not-the-token', 'approve', null);

        $this->assertSame(ApprovalOutcome::UNKNOWN, $outcome->status);
        $this->assertSame(StopReason::AWAITING_APPROVAL, $after->status);
        $this->assertSame(0, ToolApproval::count());
        $this->assertSame(0, Visit::count());
    }

    public function test_a_used_token_cannot_be_decided_twice(): void
    {
        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('schedule_visit', $this->visitArgs()),
            Fake::text('Booked.'),
        );

        $record = $this->suspended();
        $token  = $record->approval['token'];
        $this->decide($record, 'approve');

        // A second submission of the same token, against the stale record.
        // Since Week 6 the run refuses the move before the token is touched.
        try {
            app(AgentRunner::class)->decide($record, $token, 'approve', null);
            $this->fail('A second decision on the same held call must throw.');
        } catch (IllegalRunTransition) {
        }

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, ToolApproval::count());
    }

    /** The budget measures work, not waiting: the pause is never charged. */
    public function test_active_seconds_pause_while_waiting_and_carry_across_a_resume(): void
    {
        $now = 1000.0;
        $clock = function () use (&$now) { return $now; };

        $run = new AgentRun('goal', ['max_iterations' => 6, 'max_consecutive_failures' => 3, 'max_runtime_seconds' => 120], $clock);
        $run->begin();
        $now += 10;                                   // 10 s of work
        $run->stop(StopReason::AWAITING_APPROVAL, 'held');
        $now += 86400;                                // a day waiting for the officer
        $this->assertEqualsWithDelta(10.0, $run->elapsedSeconds(), 1e-9);

        $run->beginSegment();
        $now += 5;                                    // 5 s more after resuming
        $this->assertEqualsWithDelta(15.0, $run->elapsedSeconds(), 1e-9);
        $this->assertSame(2, $run->segment);
    }

    public function test_a_trace_is_never_overwritten(): void
    {
        $writer = new AgentTraceWriter($this->dir . '/runs');
        $run    = new AgentRun('goal', ['max_iterations' => 1, 'max_consecutive_failures' => 1, 'max_runtime_seconds' => 1], fn () => 0.0);
        $run->begin();
        $run->stop(StopReason::GOAL_REACHED, 'done');

        $writer->write($run);

        $this->expectException(RuntimeException::class);
        $writer->write($run);
    }
}
