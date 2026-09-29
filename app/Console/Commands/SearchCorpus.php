<?php

namespace App\Console\Commands;

use App\Services\Embedding\EmbeddingClient;
use App\Services\Embedding\EmbeddingException;
use App\Services\Retrieval\CosineRetriever;
use Illuminate\Console\Command;

/**
 * Searches the embedded corpus.
 *
 *   php artisan agrivisit:search "how deep should beans be planted"
 *   php artisan agrivisit:search "..." --k=10
 *
 * Reports the embedding call and the in-memory scoring separately: the scoring
 * time is the evidence for brute-force search instead of a vector database.
 */
class SearchCorpus extends Command
{
    protected $signature = 'agrivisit:search
                            {query : The search text}
                            {--k= : Number of results (default from config)}';

    protected $description = 'Search the embedded corpus by cosine similarity';

    public function handle(EmbeddingClient $embeddings, CosineRetriever $retriever): int
    {
        $k = $this->option('k') !== null ? (int) $this->option('k') : null;

        try {
            $t0     = microtime(true);
            $vector = $embeddings->embedQuery($this->argument('query'));
            $t1     = microtime(true);
        } catch (EmbeddingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $results = $retriever->rank($vector, $k);
        $t2      = microtime(true);

        $this->table(
            ['Rank', 'Score', 'Chunk', 'Document', 'Section', 'Text'],
            array_map(fn ($result, $i) => [
                $i + 1,
                sprintf('%.4f', $result['score']),
                $result['chunk']->chunk_ref,
                mb_strimwidth($result['chunk']->document->title, 0, 30, '…'),
                mb_strimwidth((string) $result['chunk']->section, 0, 30, '…'),
                mb_strimwidth(preg_replace('/\s+/u', ' ', $result['chunk']->text), 0, 120, '…'),
            ], $results, array_keys($results))
        );

        $this->line(sprintf(
            'Embedding call: %.0f ms · Search (load + score): %.1f ms',
            ($t1 - $t0) * 1000,
            ($t2 - $t1) * 1000
        ));

        return self::SUCCESS;
    }
}
