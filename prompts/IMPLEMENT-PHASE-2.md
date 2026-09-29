# Implement Week 3 Phase 2 — Embeddings and Retrieval

Phase 1 is closed: 3 documents, 301 chunks, register generated. This phase makes
those chunks searchable. **No prompt changes and no UI changes** — that is Phase 3.

## 1. Decisions already made

| Decision | Value | Why |
|---|---|---|
| Embedding model | `gemini-embedding-001` | Same API key as the drafting model; no second provider |
| Output dimensions | 768 | 301 vectors of 768 floats is a few MB as JSON. 3072 is four times the storage for no benefit at this corpus size |
| Normalisation | Manual, L2 | `gemini-embedding-001` does **not** normalise when `outputDimensionality` is below 3072. Unnormalised vectors make cosine similarity wrong |
| Storage | JSON in the existing `chunks.embedding` column | The column already exists from Phase 1 |
| Search | Brute-force cosine in PHP | 301 vectors is a few milliseconds. A vector database solves a scale problem this project does not have, and saying so with a measured number is a stronger answer than adopting one |

## 2. The detail most easily missed

Gemini embeddings take a **`taskType`**. It must differ between indexing and
querying:

- embedding a chunk → `"taskType": "RETRIEVAL_DOCUMENT"`
- embedding a search query → `"taskType": "RETRIEVAL_QUERY"`

Using the same type for both, or omitting it, produces symmetric embeddings that
retrieve measurably worse. This is a single field and is the most common mistake
in a first RAG implementation.

## 3. Config

Add to the `agrivisit` config:

```php
'embedding' => [
    'model'      => env('EMBEDDING_MODEL', 'gemini-embedding-001'),
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 768),
    'endpoint'   => 'https://generativelanguage.googleapis.com/v1beta/models',
    'timeout'    => 30,
    'retrieve_k' => (int) env('RETRIEVE_K', 6),
],
```

## 4. What to build

### A. `app/Services/Embedding/EmbeddingClient.php` (interface)

```php
public function embedDocument(string $text): array;   // returns float[]
public function embedQuery(string $text): array;
public function model(): string;
public function dimensions(): int;
```

Two methods rather than one with a flag, so a caller cannot accidentally embed a
query as a document.

### B. `app/Services/Embedding/GeminiEmbeddingClient.php`

Calls, with the key in the `x-goog-api-key` header (never a query string):

```
POST {endpoint}/{model}:embedContent
{
  "model": "models/gemini-embedding-001",
  "content": { "parts": [ { "text": "..." } ] },
  "taskType": "RETRIEVAL_DOCUMENT",
  "outputDimensionality": 768
}
```

The vector is at `embedding.values`.

Then **L2-normalise before returning**:

```php
$norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $values)));
return $norm > 0 ? array_map(fn ($v) => $v / $norm, $values) : $values;
```

Throw an `EmbeddingException` on HTTP failure, an empty vector, or a vector whose
length is not the configured dimension. Do not return a partial or zero vector.

Chunks are at most 400 words, well inside the model's 2048-token input limit, so
no truncation handling is needed.

### C. Migration — incremental re-embedding

Add to `chunks`:

- `content_hash` (string, 64, nullable, indexed)

On indexing, store `sha256` of the chunk text. A chunk is re-embedded only when
`embedding` is null **or** `content_hash` does not match the current text. This
matters because re-ingesting during Phase 1 happened repeatedly, and embeddings
cost an API call each.

### D. `app/Console/Commands/IndexCorpus.php`

```bash
php artisan agrivisit:index          # embed chunks that need it
php artisan agrivisit:index --force  # re-embed everything
php artisan agrivisit:index --dry-run
```

- Iterate chunks needing embedding; call `embedDocument()`; store the vector as
  JSON, plus `embedding_model` and `content_hash`.
- Show a progress bar.
- Sleep briefly between calls and retry once on a 429.
- On completion print: embedded, skipped, failed, elapsed, and total chunks now
  carrying a vector.
- A failure on one chunk must not abort the run.

### E. `app/Services/Retrieval/Retriever.php` (interface)

```php
public function retrieve(string $query, int $k = null): array;  // list of ['chunk' => Chunk, 'score' => float]
```

### F. `app/Services/Retrieval/CosineRetriever.php`

Embed the query with `embedQuery()`, load all chunks with a non-null embedding,
score each, sort descending, return the top *k*.

Because both sides are L2-normalised, cosine similarity is the dot product —
no division needed:

```php
$score = 0.0;
foreach ($queryVector as $i => $q) {
    $score += $q * $chunkVector[$i];
}
```

Skip chunks whose stored vector length does not match the query vector's, and
log that as a warning rather than crashing.

### G. `app/Console/Commands/SearchCorpus.php`

```bash
php artisan agrivisit:search "how deep should beans be planted"
php artisan agrivisit:search "..." --k=10
```

Prints a table: rank, score, chunk ref, document title, section, and the first
120 characters of the text. Also prints the search time in milliseconds,
separated from the embedding call time.

This command is the verification tool for section 5 and stays useful in Phase 3.

## 5. How Phase 2 is confirmed

Do not report Phase 2 complete until every check below has been run and its
result reported.

### 5.1 Indexing integrity — all must hold

In `php artisan tinker`:

```php
App\Models\Chunk::whereNull('embedding')->count()            // must be 0
App\Models\Chunk::whereNull('content_hash')->count()         // must be 0
App\Models\Chunk::distinct()->pluck('embedding_model')       // exactly one value
```

Then confirm every vector is the right length and properly normalised:

```php
$bad = 0; $norms = [];
foreach (App\Models\Chunk::cursor() as $c) {
    $v = json_decode($c->embedding, true);
    if (count($v) !== 768) { $bad++; continue; }
    $norms[] = sqrt(array_sum(array_map(fn($x) => $x*$x, $v)));
}
echo $bad;                                    // must be 0
echo min($norms) . ' - ' . max($norms);       // both must be within 0.999-1.001
```

A norm far from 1 means normalisation was skipped and every similarity score is
unreliable.

### 5.2 Idempotency

Run `php artisan agrivisit:index` a second time with no changes. It must report
**0 embedded, 301 skipped**. If it re-embeds everything, the hash check is wrong
and every future re-ingest will cost a full re-embedding.

### 5.3 Self-retrieval — the strongest single signal

Take 5 chunks at random. Use the **first 40 words of each chunk's own text** as
the search query. Each chunk must come back at rank 1.

If a chunk cannot retrieve itself, something is broken — wrong vectors stored,
mismatched `taskType`, or a scoring bug. Report the rank achieved for each of the
5 and the score.

### 5.4 Known-answer probes

Run these eight queries and record, for each: the top result's chunk ref,
document, section and score.

| # | Query | Expected |
|---|---|---|
| 1 | how deep should beans be planted | DOC-001, a planting or spacing section |
| 2 | signs of nutrient deficiency in bean plants | DOC-001, soil fertility or deficiency |
| 3 | how to store harvested maize grain | DOC-002, storage |
| 4 | when to plant maize for the first rains | DOC-002, planting or land preparation |
| 5 | preparing rooting media for coffee cuttings | DOC-003, rooting media |
| 6 | hardening off coffee plantlets before transplanting | DOC-003, hardening off |
| 7 | checking a coffee nursery for pests each week | DOC-003, management of cuttings |
| 8 | how to keep farm records | DOC-001 or DOC-002, record keeping |

**Pass condition:** at least 6 of 8 return a top result from the expected
document. Report all 8 regardless, including the misses — a miss is a Phase 3
finding, not a failure to hide.

### 5.5 Crop routing

For each of queries 1, 3 and 5 above, report how the top 6 results are
distributed across the three documents. A coffee-nursery query returning mostly
beans chunks means the embeddings are not discriminating between crops.

### 5.6 Negative control

Run: `php artisan agrivisit:search "how do I treat a goat with a limp"`

Nothing in the corpus answers this. Report the top score. It should be clearly
below the top scores from section 5.4 — that gap is what a relevance threshold
would be set from in Phase 3. If it scores as highly as a real query, similarity
is not discriminating and that must be reported.

### 5.7 Search latency

Report the mean search time over 10 queries, separating the embedding API call
from the in-memory scoring. The scoring portion should be in low single-digit
milliseconds. This number is the evidence for not using a vector database, so
record it.

### 5.8 Deterministic unit tests (no API key, no network)

`tests/Feature/CosineRetrieverTest.php`, using hand-made vectors and a stubbed
embedding client:

1. Identical unit vectors score 1.0 (within 1e-9).
2. Orthogonal unit vectors score 0.0.
3. Opposite unit vectors score -1.0.
4. Results are ordered by descending score.
5. `k` limits the number of results.
6. A chunk with a wrong-length vector is skipped, not fatal.
7. A chunk with a null embedding is excluded.

## 6. Out of scope

Prompt v2.0, citation rendering, changes to `ChecklistDrafter`, the 15 RAG test
questions, and any change to chunking or the dosing filters. If you find yourself
editing `ChecklistDrafter` or anything under `app/Services/Checklist/`, stop.

## 7. Report back

Report each numbered check in section 5 with its actual result, the API cost in
calls made, and anything in this brief that proved wrong or ambiguous. If a check
fails, report the failure rather than adjusting the check.
