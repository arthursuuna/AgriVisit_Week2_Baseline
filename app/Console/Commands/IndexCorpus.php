<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Services\Embedding\EmbeddingClient;
use App\Services\Embedding\EmbeddingException;
use Illuminate\Console\Command;

/**
 * Embeds corpus chunks for retrieval.
 *
 *   php artisan agrivisit:index            # embed chunks that need it
 *   php artisan agrivisit:index --force    # re-embed everything
 *   php artisan agrivisit:index --dry-run  # report what would be embedded
 *
 * A chunk needs embedding when it has no vector, or when the sha256 of its text
 * no longer matches the hash stored with the vector. Re-running after a re-ingest
 * that left the text unchanged therefore costs no API calls.
 */
class IndexCorpus extends Command
{
    /** Pause between calls, to stay under the per-minute rate limit. */
    private const PAUSE_MS = 150;

    /** Wait before the single retry after a 429, when no Retry-After is given. */
    private const RATE_LIMIT_WAIT_S = 30;

    protected $signature = 'agrivisit:index
                            {--force : Re-embed every chunk}
                            {--dry-run : Report what would be embedded without calling the API}';

    protected $description = 'Embed corpus chunks for retrieval';

    public function handle(EmbeddingClient $embeddings): int
    {
        $startedAt = microtime(true);
        $force     = (bool) $this->option('force');

        $pending = Chunk::orderBy('id')->get()
            ->filter(fn (Chunk $chunk) => $force || $this->needsEmbedding($chunk, $embeddings->model()))
            ->values();

        $skipped = Chunk::count() - $pending->count();

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d chunks would be embedded, %d skipped.', $pending->count(), $skipped));

            return self::SUCCESS;
        }

        $embedded = 0;
        $failures = [];
        $bar      = $this->output->createProgressBar($pending->count());

        foreach ($pending as $chunk) {
            try {
                $vector = $this->embedWithRetry($embeddings, $chunk->text);

                $chunk->update([
                    'embedding'       => json_encode($vector),
                    'embedding_model' => $embeddings->model(),
                    'content_hash'    => hash('sha256', $chunk->text),
                ]);

                $embedded++;
            } catch (EmbeddingException $e) {
                // One failed chunk must not abort the run; the next run retries it.
                $failures[] = [$chunk->chunk_ref, mb_strimwidth($e->getMessage(), 0, 100, '…')];
            }

            $bar->advance();
            usleep(self::PAUSE_MS * 1000);
        }

        $bar->finish();
        $this->newLine(2);

        if ($failures !== []) {
            $this->error('Chunks that could not be embedded:');
            $this->table(['Chunk', 'Reason'], $failures);
        }

        $this->table(['Embedded', 'Skipped', 'Failed', 'Elapsed', 'Chunks with a vector'], [[
            $embedded,
            $skipped,
            count($failures),
            sprintf('%.1f s', microtime(true) - $startedAt),
            Chunk::whereNotNull('embedding')->count() . ' / ' . Chunk::count(),
        ]]);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function needsEmbedding(Chunk $chunk, string $model): bool
    {
        return $chunk->embedding === null
            || $chunk->embedding_model !== $model
            || $chunk->content_hash !== hash('sha256', $chunk->text);
    }

    /** @return list<float> */
    private function embedWithRetry(EmbeddingClient $embeddings, string $text): array
    {
        try {
            return $embeddings->embedDocument($text);
        } catch (EmbeddingException $e) {
            if (! $e->isRateLimited()) {
                throw $e;
            }

            sleep(self::RATE_LIMIT_WAIT_S);

            return $embeddings->embedDocument($text);
        }
    }
}
