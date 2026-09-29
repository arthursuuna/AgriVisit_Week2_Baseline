<?php

namespace App\Services\Retrieval;

use App\Models\Chunk;
use App\Services\Embedding\EmbeddingClient;
use Illuminate\Support\Facades\Log;

/**
 * Brute-force cosine similarity over every embedded chunk.
 *
 * At a few hundred chunks this takes milliseconds, so a vector database would
 * solve a scale problem the project does not have. Both the query and the
 * stored vectors are L2-normalised, so cosine similarity is the dot product.
 *
 * The decoded vectors are loaded once per instance: a grounded draft runs
 * several searches, and reloading 301 vectors cost ~190 ms each time (Phase 2).
 */
class CosineRetriever implements Retriever
{
    /** @var list<array{chunk: Chunk, vector: list<float>}>|null */
    private ?array $index = null;

    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly int $defaultK = 6,
        private readonly float $threshold = 0.0,
    ) {
    }

    public function retrieve(string $query, ?int $k = null): array
    {
        return $this->rank($this->embeddings->embedQuery($query), $k);
    }

    public function retrieveForCrops(string $query, array $crops, ?int $k = null): array
    {
        return $this->rankForCrops($this->embeddings->embedQuery($query), $crops, $k);
    }

    /**
     * Scores a query vector that has already been embedded. Public so the
     * search command can time the embedding call and the scoring separately.
     * No threshold is applied.
     *
     * @param  list<float>  $queryVector
     * @return list<array{chunk: Chunk, score: float}>
     */
    public function rank(array $queryVector, ?int $k = null): array
    {
        return array_slice($this->scoreAll($queryVector), 0, $k ?? $this->defaultK);
    }

    /**
     * A hard crop filter with a fallback, rather than a score boost, so the rule
     * is explainable in one sentence: the farm's crops first, then anything else
     * relevant. The fallback matters because crop-agnostic material (records,
     * soil, marketing) lives in whichever manual happened to cover it.
     *
     * @param  list<float>  $queryVector
     * @param  list<string>  $crops
     * @return list<array{chunk: Chunk, score: float, in_crop: bool}>
     */
    public function rankForCrops(array $queryVector, array $crops, ?int $k = null): array
    {
        $k      = $k ?? $this->defaultK;
        $wanted = array_map('mb_strtolower', $crops);

        $relevant = array_filter(
            $this->scoreAll($queryVector),
            fn ($result) => $result['score'] >= $this->threshold
        );

        $inCrop = [];
        $other  = [];

        foreach ($relevant as $result) {
            $documentCrops = array_map('mb_strtolower', $result['chunk']->document->crops ?? []);
            $matches       = array_intersect($wanted, $documentCrops) !== [];

            $result['in_crop'] = $matches;

            if ($matches) {
                $inCrop[] = $result;
            } else {
                $other[] = $result;
            }
        }

        return array_slice(array_merge($inCrop, $other), 0, $k);
    }

    /**
     * Every embedded chunk scored against the query, best first.
     *
     * @param  list<float>  $queryVector
     * @return list<array{chunk: Chunk, score: float}>
     */
    private function scoreAll(array $queryVector): array
    {
        $dimensions = count($queryVector);
        $results    = [];

        foreach ($this->index() as $entry) {
            if (count($entry['vector']) !== $dimensions) {
                Log::warning('Skipping chunk with a mismatched embedding length.', [
                    'chunk_ref' => $entry['chunk']->chunk_ref,
                    'expected'  => $dimensions,
                    'actual'    => count($entry['vector']),
                ]);

                continue;
            }

            $results[] = ['chunk' => $entry['chunk'], 'score' => self::dot($queryVector, $entry['vector'])];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /** @return list<array{chunk: Chunk, vector: list<float>}> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        foreach (Chunk::with('document')->whereNotNull('embedding')->get() as $chunk) {
            $vector = json_decode($chunk->embedding, true);

            if (! is_array($vector)) {
                Log::warning('Skipping chunk with an unreadable embedding.', ['chunk_ref' => $chunk->chunk_ref]);

                continue;
            }

            $this->index[] = ['chunk' => $chunk, 'vector' => $vector];
        }

        return $this->index;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $score = 0.0;

        foreach ($a as $i => $value) {
            $score += $value * $b[$i];
        }

        return $score;
    }
}
