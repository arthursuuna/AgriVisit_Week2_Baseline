<?php

namespace Tests\Feature;

use App\Models\AgentRunRecord;
use App\Support\AgentStepView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The steps block on the run page: header line, counts, arguments, marks. */
class AgentRunStepsViewTest extends TestCase
{
    use RefreshDatabase;

    private function record(): AgentRunRecord
    {
        $use = fn (string $id, string $name, array $input) => ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]]];
        $result = fn (string $id, array $data) => ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => json_encode(['ok' => true, 'data' => $data, 'code' => null, 'message' => null])]]];

        $step = fn (int $i, ?string $tool, array $args, string $outcome, ?string $message = null, string $plan = 'A plan.') => [
            'iteration' => $i, 'plan' => $plan, 'tool' => $tool, 'arguments' => $args, 'outcome' => $outcome,
            'message' => $message, 'duration_ms' => 12.4, 'input_tokens' => 0, 'output_tokens' => 0, 'tool_calls_proposed' => 1];

        return AgentRunRecord::create([
            'run_id' => '20261011-000000-abcdef', 'goal' => 'g', 'officer_id' => 'officer-001',
            'status' => 'GOAL_REACHED', 'stop_reason' => 'GOAL_REACHED', 'prompt_version' => 'v1.0', 'model' => 'm',
            'iterations' => 5, 'limits' => ['max_iterations' => 6, 'max_consecutive_failures' => 3, 'max_runtime_seconds' => 120],
            'messages' => [
                ['role' => 'user', 'content' => 'g'],
                $use('t1', 'farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
                $result('t1', ['farm_id' => 'UG-KYA-012']),
                $use('t2', 'search_guidance', ['query' => 'maize leaf damage', 'max_results' => 3]),
                $result('t2', ['query' => 'maize leaf damage', 'count' => 3, 'results' => []]),
                $use('t3', 'search_guidance', ['query' => 'bean rust']),
                $result('t3', ['query' => 'bean rust', 'count' => 1, 'results' => []]),
            ],
            'steps' => [
                $step(1, 'farm_profile_lookup', ['farm_id' => 'UG-KYA-012'], 'OK'),
                // Arguments in a different order from the tool_use: still matched.
                $step(2, 'search_guidance', ['max_results' => 3, 'query' => 'maize leaf damage'], 'OK'),
                $step(3, 'search_guidance', ['query' => 'bean rust'], 'OK'),
                $step(4, 'schedule_visit', ['farm_id' => 'UG-KYA-012', 'visit_date' => '2026-01-01'], 'INVALID_ARGUMENTS', 'visit_date must not be in the past.'),
                $step(5, 'schedule_visit', ['farm_id' => 'UG-KYA-012', 'visit_date' => '2026-10-20'], 'APPROVAL_REQUIRED', 'Schedule a visit to UG-KYA-012.'),
                $step(5, 'schedule_visit', ['farm_id' => 'UG-KYA-012', 'visit_date' => '2026-10-20'], 'REJECTED', 'The officer declined.', 'Officer officer-001 did not approve the proposed action.'),
            ],
            'signatures' => [], 'system_prompt' => 's', 'declarations' => [], 'trace_files' => [],
            'started_at' => now(),
        ]);
    }

    public function test_counts_come_from_the_tool_result(): void
    {
        $labels = array_column(AgentStepView::forRun($this->record()), 'label');

        $this->assertSame(['OK', 'OK — 3 passages', 'OK — 1 passage', 'INVALID_ARGUMENTS', 'APPROVAL_REQUIRED', 'REJECTED'], $labels);
    }

    public function test_outcomes_are_marked_in_words_not_colour_alone(): void
    {
        $steps = AgentStepView::forRun($this->record());

        $this->assertSame([null, null, null, 'Failed', 'Held', 'Declined'], array_column($steps, 'mark'));
        $this->assertSame(['ok', 'ok', 'ok', 'failed', 'held', 'declined'], array_column($steps, 'kind'));
    }

    public function test_the_page_shows_header_lines_arguments_and_messages(): void
    {
        $this->get(route('agent.runs.show', $this->record()->run_id))
            ->assertOk()
            ->assertSeeInOrder(['Step 2', 'search_guidance', 'OK — 3 passages', '12 ms', 'A plan.'], false)
            ->assertSee('<dt>query</dt><dd>maize leaf damage</dd>', false)
            ->assertSee('<dt>max_results</dt><dd>3</dd>', false)
            ->assertSee('<summary>Raw arguments</summary>', false)
            ->assertSee('<span class="mark failed">Failed</span>', false)
            ->assertSee('<p class="message">visit_date must not be in the past.</p>', false)
            ->assertSee('Step 5 · officer decision')
            ->assertDontSee('<th style="width:12%">Iteration</th>', false);
    }
}
