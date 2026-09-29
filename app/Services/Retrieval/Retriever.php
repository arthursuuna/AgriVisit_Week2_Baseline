<?php

namespace App\Services\Retrieval;

use App\Models\Chunk;

interface Retriever
{
    /**
     * The k chunks most relevant to the query, best first.
     *
     * @return list<array{chunk: Chunk, score: float}>
     */
    public function retrieve(string $query, ?int $k = null): array;

    /**
     * Up to k chunks at or above the relevance threshold, taken first from
     * documents covering the given crops and then topped up from the whole
     * corpus. Crop matches come first; each group is ordered by score.
     *
     * @param  list<string>  $crops
     * @return list<array{chunk: Chunk, score: float, in_crop: bool}>
     */
    public function retrieveForCrops(string $query, array $crops, ?int $k = null): array;
}
