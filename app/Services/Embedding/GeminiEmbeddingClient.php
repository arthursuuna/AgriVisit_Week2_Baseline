<?php

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * gemini-embedding-001 over the Generative Language API.
 *
 * Two details decide retrieval quality here. The task type differs between
 * indexing (RETRIEVAL_DOCUMENT) and querying (RETRIEVAL_QUERY); using one type
 * for both retrieves measurably worse. And below 3072 dimensions the API does
 * not normalise its output, so every vector is L2-normalised before it leaves
 * this class, which lets retrieval treat cosine similarity as a dot product.
 */
class GeminiEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly array $config,
        private readonly ?string $key,
    ) {
    }

    public function embedDocument(string $text): array
    {
        return $this->embed($text, 'RETRIEVAL_DOCUMENT');
    }

    public function embedQuery(string $text): array
    {
        return $this->embed($text, 'RETRIEVAL_QUERY');
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function dimensions(): int
    {
        return $this->config['dimensions'];
    }

    /** @return list<float> */
    private function embed(string $text, string $taskType): array
    {
        if (empty($this->key)) {
            throw new EmbeddingException('GOOGLE_API_KEY is not set.');
        }

        $url = rtrim($this->config['endpoint'], '/') . '/' . $this->model() . ':embedContent';

        try {
            // The key travels as a header, never as a query parameter.
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders([
                    'x-goog-api-key' => $this->key,
                    'content-type'   => 'application/json',
                ])
                ->post($url, [
                    'model'                => 'models/' . $this->model(),
                    'content'              => ['parts' => [['text' => $text]]],
                    'taskType'             => $taskType,
                    'outputDimensionality' => $this->dimensions(),
                ]);
        } catch (Throwable $e) {
            throw new EmbeddingException('Embedding request failed: ' . $e->getMessage(), null, $e);
        }

        if ($response->failed()) {
            throw new EmbeddingException(
                'Embedding returned HTTP ' . $response->status() . ': ' . $response->body(),
                $response->status()
            );
        }

        $values = $response->json('embedding.values');

        if (! is_array($values) || $values === []) {
            throw new EmbeddingException('Embedding response contained no vector.');
        }

        if (count($values) !== $this->dimensions()) {
            throw new EmbeddingException(sprintf(
                'Embedding has %d dimensions; %d are configured.',
                count($values),
                $this->dimensions()
            ));
        }

        if (array_filter($values, fn ($v) => $v != 0) === []) {
            throw new EmbeddingException('Embedding is a zero vector and cannot be normalised.');
        }

        return self::normalise($values);
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function normalise(array $values): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $values)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm, $values) : $values;
    }
}
