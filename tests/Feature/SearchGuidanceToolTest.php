<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Agent\AgentTraceWriter;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\Agent\StopReason;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Retrieval\Retriever;
use App\Services\Tools\Catalogue\SearchGuidanceTool;
use App\Services\Tools\SchemaValidator;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\Fakes\FakeRetriever;
use Tests\TestCase;

class SearchGuidanceToolTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private FakeRetriever $retriever;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-search-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        $document = Document::create([
            'doc_ref' => 'DOC-001', 'title' => 'Maize Production Guide', 'publisher' => 'Test',
            'source_url' => 'https://example.org/maize.pdf', 'retrieved_on' => '2026-09-29', 'file_name' => 'maize.pdf',
        ]);

        $chunk = fn (string $ref, ?string $section) => Chunk::create([
            'document_id' => $document->id, 'chunk_ref' => $ref, 'position' => Chunk::count(),
            'section' => $section, 'text' => "  Guidance text of {$ref}.  ", 'word_count' => 4,
        ]);

        // Scores either side of the 0.65 threshold.
        $this->retriever = new FakeRetriever([
            ['chunk' => $chunk('DOC-001-C001', '4.2 Fall armyworm'), 'score' => 0.87],
            ['chunk' => $chunk('DOC-001-C002', '4.3 Leaf blight'), 'score' => 0.74],
            ['chunk' => $chunk('DOC-001-C003', null), 'score' => 0.70],
            ['chunk' => $chunk('DOC-001-C004', '5.1 Storage'), 'score' => 0.66],
            ['chunk' => $chunk('DOC-001-C005', '6.0 Marketing'), 'score' => 0.651],
            ['chunk' => $chunk('DOC-001-C006', '1.0 Introduction'), 'score' => 0.58],
        ]);

        $this->app->instance(Retriever::class, $this->retriever);
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));
        config(['agrivisit.retrieval.threshold' => 0.65]);
    }

    protected function tearDown(): void
    {
        foreach (['tool-logs', 'runs'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function search(array $args): ToolResult
    {
        return $this->app->make(ToolInvoker::class)->invoke('search_guidance', $args);
    }

    // 1
    public function test_a_query_above_threshold_returns_results_with_chunk_refs(): void
    {
        $result = $this->search(['query' => 'maize leaf damage']);

        $this->assertTrue($result->ok);
        $this->assertSame('maize leaf damage', $result->data['query']);
        $this->assertSame(3, $result->data['count'], 'Default max_results is 3.');
        $this->assertSame(['DOC-001-C001', 'DOC-001-C002', 'DOC-001-C003'], array_column($result->data['results'], 'chunk_ref'));

        foreach ($result->data['results'] as $passage) {
            $this->assertNotSame('', $passage['chunk_ref']);
            $this->assertGreaterThanOrEqual(0.65, $passage['score']);
        }

        $this->assertSame('Maize Production Guide', $result->data['results'][0]['source_title']);
        $this->assertSame('', $result->data['results'][2]['section'], 'A chunk with no section reports an empty string.');
        $this->assertSame('Guidance text of DOC-001-C001.', $result->data['results'][0]['text']);
    }

    // 2
    public function test_a_query_below_threshold_is_a_successful_empty_result(): void
    {
        $this->app->instance(Retriever::class, new FakeRetriever([
            ['chunk' => Chunk::where('chunk_ref', 'DOC-001-C006')->first(), 'score' => 0.58],
        ]));

        $result = $this->search(['query' => 'banana weevil']);

        $this->assertTrue($result->ok);
        $this->assertNull($result->code);
        $this->assertSame(0, $result->data['count']);
        $this->assertSame([], $result->data['results']);
    }

    /** Rule 4: nothing below 0.65 is returned, even when fewer than max_results pass. */
    public function test_the_threshold_does_not_bend_to_fill_max_results(): void
    {
        config(['agrivisit.retrieval.threshold' => 0.8]);

        $result = $this->search(['query' => 'maize leaf damage', 'max_results' => 5]);

        $this->assertSame(1, $result->data['count']);
        $this->assertSame('DOC-001-C001', $result->data['results'][0]['chunk_ref']);
    }

    // 3
    public function test_a_restricted_query_fails_and_returns_no_passages(): void
    {
        $result = $this->search(['query' => 'fungicide application rate for maize leaf blight']);

        $this->assertFalse($result->ok);
        $this->assertSame(ToolResult::GUARD_REFUSED, $result->code, 'Well-formed arguments, refused topic: not INVALID_ARGUMENTS.');
        $this->assertStringContainsString('restricted-topic guard (dosing)', $result->message);
        $this->assertSame([], $result->data);
        $this->assertSame([], $this->retriever->queries, 'The corpus must not be searched at all.');
    }

    // 4
    public function test_max_results_is_honoured_and_six_is_invalid(): void
    {
        $this->assertSame(1, $this->search(['query' => 'maize leaf damage', 'max_results' => 1])->data['count']);
        $this->assertSame(5, $this->search(['query' => 'maize leaf damage', 'max_results' => 5])->data['count']);

        $six = $this->search(['query' => 'maize leaf damage', 'max_results' => 6]);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $six->code);

        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $this->search(['query' => 'ab'])->code);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $this->search(['query' => 'maize', 'crop' => 'maize'])->code,
            'The schema is closed.');
    }

    // 5
    public function test_output_validates_against_the_tool_output_schema(): void
    {
        $tool = $this->app->make(SearchGuidanceTool::class);

        foreach ([['query' => 'maize leaf damage', 'max_results' => 5], ['query' => 'anything']] as $args) {
            $result = $tool->execute($args);
            $this->assertSame([], (new SchemaValidator())->validate($tool->outputSchema(), $result->data));
        }

        $this->app->instance(Retriever::class, new FakeRetriever([]));
        $empty = $this->app->make(SearchGuidanceTool::class)->execute(['query' => 'nothing here']);
        $this->assertSame([], (new SchemaValidator())->validate($tool->outputSchema(), $empty->data));
    }

    /**
     * Rule 2: the guard runs on what the agent composes. The officer's goal is
     * innocuous; the model decides by itself to search for an application rate.
     * Phase 3: that refusal ends the run, so the model never gets a turn in
     * which to reword the query (the Phase 2 reroute).
     */
    public function test_a_restricted_query_composed_by_the_agent_ends_the_run_and_is_traced(): void
    {
        $goal = 'Prepare me for a visit to farm UG-KYA-012 about leaf damage on the maize.';

        $fake = new Fake(
            Fake::call('search_guidance', ['query' => 'fungicide dose per litre for maize leaf damage'], 'I will look up a spray rate.'),
            // The reworded retry the model would make next. It must never be requested.
            Fake::call('search_guidance', ['query' => 'how much product per knapsack for maize leaf damage']),
        );

        $loop = new BoundedAgentLoop(
            $this->app->make(RestrictedTopicGuard::class),
            $fake,
            $this->app->make(ToolInvoker::class),
            new AgentTraceWriter($this->dir . '/runs'),
            ['max_iterations' => 6, 'max_consecutive_failures' => 3, 'max_runtime_seconds' => 120],
        );

        $run = $loop->run($goal, [['name' => 'search_guidance']], 'system');

        $this->assertSame(StopReason::GUARD_REFUSED, $run->status);
        $this->assertCount(1, $run->steps);
        $this->assertSame(ToolResult::GUARD_REFUSED, $run->steps[0]->outcome);
        $this->assertSame(1, $fake->callCount(), 'No turn in which to reword the query.');
        $this->assertSame([], $this->retriever->queries, 'Nothing from the corpus was searched.');
        $this->assertStringContainsString('(dosing)', $run->summary, 'The officer sees the category.');

        // The result was appended before stopping: the tool_use has its tool_result.
        $last = $run->messages()[count($run->messages()) - 1];
        $this->assertSame('tool_result', $last['content'][0]['type']);
        $this->assertSame($run->messages()[1]['content'][1]['id'], $last['content'][0]['tool_use_id']);
        $this->assertTrue($last['content'][0]['is_error']);
        $this->assertStringNotContainsString('chunk_ref', $last['content'][0]['content']);

        $trace = json_decode(file_get_contents("{$this->dir}/runs/{$run->runId}.json"), true);
        $this->assertSame(StopReason::GUARD_REFUSED, $trace['stop_reason']);
        $this->assertSame(ToolResult::GUARD_REFUSED, $trace['steps'][0]['outcome']);
        $this->assertStringContainsString('restricted-topic guard', $trace['steps'][0]['message']);
    }

    /** The Phase 2 live query, which the narrowed clinical rule now lets through. */
    public function test_the_phase_2_crop_diagnosis_query_now_searches(): void
    {
        $result = $this->search(['query' => 'maize leaf damage causes and how to diagnose']);

        $this->assertTrue($result->ok);
        $this->assertSame(1, count($this->retriever->queries));
    }
}
