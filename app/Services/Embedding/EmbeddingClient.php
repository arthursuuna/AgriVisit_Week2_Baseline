<?php

namespace App\Services\Embedding;

/**
 * Turns text into a unit-length vector.
 *
 * Documents and queries are embedded with different task types, so they are
 * two methods rather than one with a flag: a caller cannot accidentally embed a
 * query as a document.
 */
interface EmbeddingClient
{
    /** @return list<float> L2-normalised vector for a corpus chunk. */
    public function embedDocument(string $text): array;

    /** @return list<float> L2-normalised vector for a search query. */
    public function embedQuery(string $text): array;

    public function model(): string;

    public function dimensions(): int;
}
