<?php

namespace Tests\Fakes;

use App\Models\Chunk;
use App\Services\Retrieval\Retriever;

/**
 * Returns fixed chunks with fixed scores, best first, and records every query.
 * No embedding call, no key, no network. Applies no threshold, like the real
 * retrieve(): the tool under test must apply it.
 */
class FakeRetriever implements Retriever
{
    /** @var list<array{query: string, k: ?int}> */
    public array $queries = [];

    /** @param list<array{chunk: Chunk, score: float}> $results */
    public function __construct(private readonly array $results = [])
    {
    }

    public function retrieve(string $query, ?int $k = null): array
    {
        $this->queries[] = compact('query', 'k');

        $results = $this->results;
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $k ?? 6);
    }

    public function retrieveForCrops(string $query, array $crops, ?int $k = null): array
    {
        return array_map(fn ($r) => $r + ['in_crop' => false], $this->retrieve($query, $k));
    }
}
