<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Embedding\EmbeddingClient;
use App\Services\Retrieval\CosineRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CosineRetrieverTest extends TestCase
{
    use RefreshDatabase;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->document = Document::create([
            'doc_ref'      => 'DOC-001',
            'title'        => 'Test Guide',
            'publisher'    => 'Test',
            'source_url'   => 'https://example.org/test.pdf',
            'retrieved_on' => '2026-09-29',
            'file_name'    => 'test.pdf',
        ]);
    }

    /** A retriever whose query embedding is fixed, so no network is involved. */
    private function retriever(array $queryVector, int $k = 6, float $threshold = 0.0): CosineRetriever
    {
        $stub = new class($queryVector) implements EmbeddingClient {
            public function __construct(private readonly array $vector)
            {
            }

            public function embedDocument(string $text): array
            {
                return $this->vector;
            }

            public function embedQuery(string $text): array
            {
                return $this->vector;
            }

            public function model(): string
            {
                return 'stub';
            }

            public function dimensions(): int
            {
                return count($this->vector);
            }
        };

        return new CosineRetriever($stub, $k, $threshold);
    }

    private function chunk(string $ref, ?array $vector, ?Document $document = null): Chunk
    {
        return Chunk::create([
            'document_id' => ($document ?? $this->document)->id,
            'chunk_ref'   => $ref,
            'position'    => Chunk::count(),
            'text'        => "Text of {$ref}",
            'word_count'  => 3,
            'embedding'   => $vector === null ? null : json_encode($vector),
        ]);
    }

    private function scoreOf(array $results, string $ref): float
    {
        foreach ($results as $result) {
            if ($result['chunk']->chunk_ref === $ref) {
                return $result['score'];
            }
        }

        $this->fail("{$ref} was not returned.");
    }

    public function test_identical_unit_vectors_score_one(): void
    {
        $this->chunk('DOC-001-C001', [0.6, 0.8, 0.0]);

        $results = $this->retriever([0.6, 0.8, 0.0])->retrieve('query');

        $this->assertEqualsWithDelta(1.0, $this->scoreOf($results, 'DOC-001-C001'), 1e-9);
    }

    public function test_orthogonal_unit_vectors_score_zero(): void
    {
        $this->chunk('DOC-001-C001', [0.0, 1.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->retrieve('query');

        $this->assertEqualsWithDelta(0.0, $this->scoreOf($results, 'DOC-001-C001'), 1e-9);
    }

    public function test_opposite_unit_vectors_score_minus_one(): void
    {
        $this->chunk('DOC-001-C001', [-1.0, 0.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->retrieve('query');

        $this->assertEqualsWithDelta(-1.0, $this->scoreOf($results, 'DOC-001-C001'), 1e-9);
    }

    public function test_results_are_ordered_by_descending_score(): void
    {
        $this->chunk('DOC-001-C001', [0.0, 1.0, 0.0]);           // 0.0
        $this->chunk('DOC-001-C002', [1.0, 0.0, 0.0]);           // 1.0
        $this->chunk('DOC-001-C003', [0.6, 0.8, 0.0]);           // 0.6
        $this->chunk('DOC-001-C004', [-1.0, 0.0, 0.0]);          // -1.0

        $results = $this->retriever([1.0, 0.0, 0.0])->retrieve('query');

        $this->assertSame(
            ['DOC-001-C002', 'DOC-001-C003', 'DOC-001-C001', 'DOC-001-C004'],
            array_map(fn ($r) => $r['chunk']->chunk_ref, $results)
        );
    }

    public function test_k_limits_the_number_of_results(): void
    {
        foreach (range(1, 5) as $i) {
            $this->chunk(sprintf('DOC-001-C%03d', $i), [1.0, 0.0, 0.0]);
        }

        $this->assertCount(2, $this->retriever([1.0, 0.0, 0.0])->retrieve('query', 2));
        $this->assertCount(3, $this->retriever([1.0, 0.0, 0.0], 3)->retrieve('query'));
    }

    public function test_a_chunk_with_a_wrong_length_vector_is_skipped(): void
    {
        $this->chunk('DOC-001-C001', [1.0, 0.0, 0.0]);
        $this->chunk('DOC-001-C002', [1.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->retrieve('query');

        $this->assertSame(['DOC-001-C001'], array_map(fn ($r) => $r['chunk']->chunk_ref, $results));
    }

    public function test_a_chunk_with_a_null_embedding_is_excluded(): void
    {
        $this->chunk('DOC-001-C001', [1.0, 0.0, 0.0]);
        $this->chunk('DOC-001-C002', null);

        $results = $this->retriever([1.0, 0.0, 0.0])->retrieve('query');

        $this->assertSame(['DOC-001-C001'], array_map(fn ($r) => $r['chunk']->chunk_ref, $results));
    }

    /** Beans chunks in $this->document, maize chunks in a second document. */
    private function twoCrops(): void
    {
        $this->document->update(['crops' => ['beans']]);

        $maize = Document::create([
            'doc_ref'      => 'DOC-002',
            'title'        => 'Maize Guide',
            'publisher'    => 'Test',
            'source_url'   => 'https://example.org/maize.pdf',
            'retrieved_on' => '2026-09-29',
            'file_name'    => 'maize.pdf',
            'crops'        => ['maize'],
        ]);

        $this->chunk('DOC-001-C001', [0.8, 0.6, 0.0]);           // beans, 0.8
        $this->chunk('DOC-001-C002', [0.6, 0.8, 0.0]);           // beans, 0.6
        $this->chunk('DOC-002-C001', [1.0, 0.0, 0.0], $maize);   // maize, 1.0
        $this->chunk('DOC-002-C002', [0.0, 1.0, 0.0], $maize);   // maize, 0.0
    }

    private function refs(array $results): array
    {
        return array_map(fn ($r) => $r['chunk']->chunk_ref, $results);
    }

    public function test_crop_matches_come_before_higher_scoring_chunks_from_other_crops(): void
    {
        $this->twoCrops();

        $results = $this->retriever([1.0, 0.0, 0.0], 6, 0.5)->retrieveForCrops('query', ['Beans'], 3);

        $this->assertSame(['DOC-001-C001', 'DOC-001-C002', 'DOC-002-C001'], $this->refs($results));
        $this->assertSame([true, true, false], array_column($results, 'in_crop'));
    }

    public function test_the_crop_filter_alone_fills_k_when_it_can(): void
    {
        $this->twoCrops();

        $results = $this->retriever([1.0, 0.0, 0.0], 6, 0.5)->retrieveForCrops('query', ['beans'], 2);

        $this->assertSame(['DOC-001-C001', 'DOC-001-C002'], $this->refs($results));
    }

    public function test_an_unknown_crop_falls_back_to_the_whole_corpus(): void
    {
        $this->twoCrops();

        $results = $this->retriever([1.0, 0.0, 0.0], 6, 0.5)->retrieveForCrops('query', ['banana'], 6);

        $this->assertSame(['DOC-002-C001', 'DOC-001-C001', 'DOC-001-C002'], $this->refs($results));
    }

    public function test_nothing_below_the_threshold_is_returned_even_as_a_top_up(): void
    {
        $this->twoCrops();

        $results = $this->retriever([1.0, 0.0, 0.0], 6, 0.65)->retrieveForCrops('query', ['beans'], 6);

        $this->assertSame(['DOC-001-C001', 'DOC-002-C001'], $this->refs($results));
    }

    public function test_an_unanswerable_query_returns_nothing(): void
    {
        $this->twoCrops();

        $this->assertSame([], $this->retriever([0.0, 0.0, 1.0], 6, 0.65)->retrieveForCrops('query', ['beans'], 6));
    }
}
