<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Checklist\ChecklistDrafter;
use App\Services\Checklist\ChecklistParser;
use App\Services\Checklist\DraftResult;
use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Checklist\TraceWriter;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Prompts\PromptRepository;
use App\Services\Retrieval\FarmQueryBuilder;
use App\Services\Retrieval\Retriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistDrafterTest extends TestCase
{
    use RefreshDatabase;

    private string $traceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traceDir = sys_get_temp_dir() . '/agrivisit-drafter-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->traceDir . '/*.json') ?: []);
        @rmdir($this->traceDir);

        parent::tearDown();
    }

    private function farm(): array
    {
        return [
            'farm_id'            => 'UG-KYA-077',
            'crops'              => [['crop' => 'beans']],
            'outstanding_issues' => ['Farmer asked about improving bean yields.'],
        ];
    }

    /** @return list<array{chunk: Chunk, score: float, in_crop: bool}> */
    private function evidence(int $count): array
    {
        $document = Document::create([
            'doc_ref'      => 'DOC-001',
            'title'        => 'Beans Training Manual',
            'publisher'    => 'MAAIF',
            'source_url'   => 'https://example.org/beans.pdf',
            'retrieved_on' => '2026-09-29',
            'file_name'    => 'beans.pdf',
            'crops'        => ['beans'],
        ]);

        $results = [];

        for ($i = 1; $i <= $count; $i++) {
            $results[] = [
                'chunk'   => Chunk::create([
                    'document_id' => $document->id,
                    'chunk_ref'   => sprintf('DOC-001-C%03d', $i),
                    'section'     => "2.{$i} Planting",
                    'position'    => $i,
                    'text'        => "Passage {$i} about planting beans.",
                    'word_count'  => 5,
                ]),
                'score'   => 0.9 - $i / 100,
                'in_crop' => true,
            ];
        }

        return $results;
    }

    private function retriever(array $results): Retriever
    {
        return new class($results) implements Retriever {
            public int $calls = 0;

            public function __construct(private readonly array $results)
            {
            }

            public function retrieve(string $query, ?int $k = null): array
            {
                return $this->retrieveForCrops($query, [], $k);
            }

            public function retrieveForCrops(string $query, array $crops, ?int $k = null): array
            {
                $this->calls++;

                return $this->results;
            }
        };
    }

    private function llm(string $output): LlmClient
    {
        return new class($output) implements LlmClient {
            public int $calls = 0;

            public ?string $lastUser = null;

            public function __construct(private readonly string $output)
            {
            }

            public function complete(string $system, string $user): LlmResponse
            {
                $this->calls++;
                $this->lastUser = $user;

                return new LlmResponse($this->output, 'stub', 'stub-model', 0.2, 100, 50, 10);
            }

            public function provider(): string
            {
                return 'stub';
            }
        };
    }

    private function drafter(LlmClient $llm, Retriever $retriever, string $version = 'v2.0'): ChecklistDrafter
    {
        return new ChecklistDrafter(
            $llm,
            new PromptRepository(resource_path('prompts')),
            new RestrictedTopicGuard(config('agrivisit.restricted')),
            new ChecklistParser(5, 10),
            new TraceWriter($this->traceDir),
            new FarmQueryBuilder(),
            $retriever,
            $version
        );
    }

    private function modelOutput(array $labels): string
    {
        return json_encode(['summary' => 'Bean yields.', 'items' => array_map(fn ($label) => [
            'item'      => 'Check the seed bed is fine and even.',
            'category'  => 'husbandry',
            'rationale' => 'The farmer asked about improving bean yields.',
            'grounding' => $label,
        ], $labels)]);
    }

    private function trace(DraftResult $result): array
    {
        return json_decode(file_get_contents("{$this->traceDir}/{$result->traceId}.json"), true);
    }

    public function test_no_evidence_stops_before_the_model_is_called(): void
    {
        $llm    = $this->llm($this->modelOutput(['C-1', 'C-2', 'C-3']));
        $result = $this->drafter($llm, $this->retriever([]))->draft($this->farm());

        $this->assertSame(DraftResult::STATUS_NO_EVIDENCE, $result->status);
        $this->assertSame(0, $llm->calls);
        $this->assertSame('retrieval', $this->trace($result)['stage']);
    }

    public function test_a_restricted_request_is_refused_before_retrieval(): void
    {
        $llm       = $this->llm($this->modelOutput(['C-1', 'C-2', 'C-3']));
        $retriever = $this->retriever($this->evidence(3));

        $result = $this->drafter($llm, $retriever)->draft($this->farm(), 'What dosage of fungicide should they use?');

        $this->assertSame(DraftResult::STATUS_REFUSED, $result->status);
        $this->assertSame(0, $retriever->calls);
        $this->assertSame(0, $llm->calls);
    }

    public function test_items_carry_the_real_citation_and_the_trace_records_retrieval(): void
    {
        $llm    = $this->llm($this->modelOutput(['C-1', 'C-3', 'C-1']));
        $result = $this->drafter($llm, $this->retriever($this->evidence(4)))->draft($this->farm());

        $this->assertSame(DraftResult::STATUS_DRAFTED, $result->status);
        $this->assertSame(4, $result->evidenceCount);
        $this->assertSame('DOC-001-C003', $result->items[1]['citation']['chunk_ref']);
        $this->assertSame('Beans Training Manual', $result->items[1]['citation']['document']);
        $this->assertStringContainsString('[C-4] Beans Training Manual — 2.4 Planting', $llm->lastUser);

        $trace = $this->trace($result);
        $this->assertSame(['C-1', 'C-3'], $trace['cited_labels']);
        $this->assertSame(['C-2', 'C-4'], $trace['uncited_labels']);
        $this->assertCount(4, $trace['evidence']);
        $this->assertNotEmpty($trace['retrieval']['queries']);
    }

    public function test_a_fabricated_label_discards_the_draft(): void
    {
        $llm    = $this->llm($this->modelOutput(['C-1', 'C-2', 'C-7']));
        $result = $this->drafter($llm, $this->retriever($this->evidence(3)))->draft($this->farm());

        $this->assertSame(DraftResult::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('C-7', $result->message);
        $this->assertSame('output-validation', $this->trace($result)['stage']);
    }

    public function test_the_model_finding_no_support_is_a_no_evidence_outcome(): void
    {
        $llm    = $this->llm('{"summary": "", "items": []}');
        $result = $this->drafter($llm, $this->retriever($this->evidence(3)))->draft($this->farm());

        $this->assertSame(DraftResult::STATUS_NO_EVIDENCE, $result->status);
        $this->assertSame(1, $llm->calls);
        $this->assertSame('model', $this->trace($result)['stage']);
    }

    public function test_v1_prompts_still_draft_ungrounded_without_retrieval(): void
    {
        $retriever = $this->retriever($this->evidence(3));
        $llm       = $this->llm($this->modelOutput(array_fill(0, 5, 'ungrounded')));

        $result = $this->drafter($llm, $retriever, 'v1.1')->draft($this->farm());

        $this->assertSame(DraftResult::STATUS_DRAFTED, $result->status);
        $this->assertSame(0, $retriever->calls);
        $this->assertArrayNotHasKey('citation', $result->items[0]);
    }
}
