# Week 3 Phase 2 — Embeddings and Retrieval: Results

**Implemented from:** `prompts/IMPLEMENT-PHASE-2.md`
**Date:** 2026-09-29
**Corpus:** 3 documents, 301 chunks (from `sentence-level-removal-results.md`)
**Status:** Implemented, and every check in section 5 of the brief has been run. Most meet their targets. Two fall short and are recorded below rather than adjusted:

- **Probe 4** returned the wrong document, by a margin of 0.0001.
- **Scoring latency** was 13.7 ms, above the brief's "low single-digit" expectation, with a further ~190 ms spent loading vectors.

No prompt, UI or `app/Services/Checklist/` files were changed. Nothing has been committed.

---

## Summary

| Check | Target | Result | Met |
|---|---|---|---|
| 5.1 Indexing integrity | No missing vectors or hashes, one model, 768 dimensions, norms 0.999–1.001 | All hold; norms exactly 1.000000 | Yes |
| 5.2 Idempotency | Second run: 0 embedded, 301 skipped | 0 embedded, 301 skipped | Yes |
| 5.3 Self-retrieval | 5 of 5 at rank 1 | 5 of 5 at rank 1 | Yes |
| 5.4 Known-answer probes | At least 6 of 8 | 7 of 8 | Yes |
| 5.5 Crop routing | Top 6 mostly from the right crop | 6/6, 5/6, 6/6 | Yes |
| 5.6 Negative control | Clearly below real queries | 0.580 vs 0.703–0.873 | Yes |
| 5.7 Search latency | Scoring in low single-digit ms | Scoring 13.7 ms; loading 194 ms | **No** |
| 5.8 Unit tests | 7 deterministic tests pass | 7 pass; 59 across the suite | Yes |

---

## What was built

| File | Purpose |
|---|---|
| `config/agrivisit.php` | New `embedding` block: model, 768 dimensions, endpoint, timeout, `retrieve_k` (default 6) |
| `app/Services/Embedding/EmbeddingClient.php` | Interface: `embedDocument()`, `embedQuery()`, `model()`, `dimensions()` |
| `app/Services/Embedding/EmbeddingException.php` | Carries the HTTP status, so a 429 can be told apart from other failures |
| `app/Services/Embedding/GeminiEmbeddingClient.php` | Calls `embedContent` with the key in the `x-goog-api-key` header. Uses `RETRIEVAL_DOCUMENT` for chunks and `RETRIEVAL_QUERY` for searches. L2-normalises every vector, and rejects empty, zero and wrong-length vectors. |
| `database/migrations/2026_01_01_000005_add_content_hash_to_chunks_table.php` | Adds `chunks.content_hash` (string 64, nullable, indexed) |
| `app/Services/Retrieval/Retriever.php` | Interface: `retrieve(string $query, ?int $k = null)` |
| `app/Services/Retrieval/CosineRetriever.php` | Brute-force dot product over all embedded chunks. Skips wrong-length vectors with a logged warning. |
| `app/Console/Commands/IndexCorpus.php` | `agrivisit:index` with `--force` and `--dry-run`. Progress bar, 150 ms pause between calls, one retry after a 429, and a failure on one chunk does not stop the run. |
| `app/Console/Commands/SearchCorpus.php` | `agrivisit:search "…" --k=N`. Prints results and times the embedding call and the search separately. |
| `app/Providers/AgriVisitServiceProvider.php` | Bindings for `EmbeddingClient`, `CosineRetriever` and `Retriever` |
| `tests/Feature/CosineRetrieverTest.php` | The 7 deterministic tests from section 5.8 |

Before building, a probe of the API confirmed the brief's normalisation warning: raw `gemini-embedding-001` vectors at 768 dimensions had a norm of about **0.59**, not 1.

---

## 5.1 Indexing integrity

| Check | Result |
|---|---|
| Chunks with no embedding | **0** |
| Chunks with no `content_hash` | **0** |
| Distinct `embedding_model` values | **1** (`gemini-embedding-001`) |
| Vectors not 768 long | **0** |
| Norm range | **1.000000 – 1.000000** |

The first index run embedded all 301 chunks with 0 failures in 386.1 s.

## 5.2 Idempotency

| Run | Embedded | Skipped | Failed | Elapsed |
|---|---|---|---|---|
| First | 301 | 0 | 0 | 386.1 s |
| Second, no changes | **0** | **301** | 0 | 0.1 s |

## 5.3 Self-retrieval

5 chunks chosen at random (seed `20260929`). The query was the first 40 words of each chunk's own text.

| Chunk | Rank | Score |
|---|---|---|
| DOC-001-C050 | **1** | 0.8874 |
| DOC-001-C065 | **1** | 0.8694 |
| DOC-001-C135 | **1** | 0.8929 |
| DOC-003-C020 | **1** | 0.9126 |
| DOC-003-C103 | **1** | 0.9150 |

Consecutive chunks overlap by 80 words, so a chunk's first 40 words usually also appear at the end of the chunk before it. The previous chunk could in principle have outranked the chunk itself. That did not happen in any of the 5.

## 5.4 Known-answer probes

**7 of 8 pass** (the pass mark is 6).

| # | Query | Expected | Top result | Section | Score | Result |
|---|---|---|---|---|---|---|
| 1 | how deep should beans be planted | DOC-001 | DOC-001-C025 | 2.9: Methods of Planting and Related Equipment | 0.7331 | Pass |
| 2 | signs of nutrient deficiency in bean plants | DOC-001 | DOC-001-C035 | Role Deficiency signs | 0.7699 | Pass |
| 3 | how to store harvested maize grain | DOC-002 | DOC-002-C036 | `(MSV)` | 0.7613 | Pass |
| 4 | when to plant maize for the first rains | DOC-002 | **DOC-001-C021** | 2.6: Planting (beans) | 0.7032 | **Miss** |
| 5 | preparing rooting media for coffee cuttings | DOC-003 | DOC-003-C051 | 4.3.1 Preparation of rooting media | 0.8029 | Pass |
| 6 | hardening off coffee plantlets before transplanting | DOC-003 | DOC-003-C063 | 5.0 Introduction | 0.8729 | Pass |
| 7 | checking a coffee nursery for pests each week | DOC-003 | DOC-003-C089 | 6.8.3 Management and control | 0.7576 | Pass |
| 8 | how to keep farm records | DOC-001 or DOC-002 | DOC-001-C107 | `• V` | 0.7668 | Pass |

### Probe 4 — the miss

A near tie between crops:

| Rank | Chunk | Score | Content |
|---|---|---|---|
| 1 | DOC-001-C021 (beans) | 0.7032 | "Planting should be done on the onset of rains. For long rain seasons, farmers may delay planting by two to three weeks…" |
| 2 | DOC-002-C019 (maize) | 0.7031 | Temperature and rainfall requirements for maize, not planting dates |

The embeddings matched the topic (planting at the onset of rains) but did not separate beans from maize. Eleven DOC-002 chunks mention rains or onset, but none outranked the beans chunk.

**Phase 3 finding:** filter or boost results by crop using the `crops` field already stored on each document.

### Section labels on correct results

Probes 3, 6 and 8 found the right content, but under poor section labels:

| Probe | Label | Actual content |
|---|---|---|
| 3 | `(MSV)` | Testing grain moisture content and maize shelling |
| 6 | `5.0 Introduction` | "A key requirement for successful transplanting of clonal Coffee plantlets is the hardening off…" |
| 8 | `• V` | "This is the documentation of all the farming activities. Farm records facilitate…" |

`(MSV)` and `• V` are the heading-detection problem (C-01) accepted in Phase 1. They will show in citations once Phase 3 renders them.

Probe 6 passes on the chapter 5 introduction. The dedicated hardening-off section (DOC-003-C114) is not in the corpus: it was dropped by the chunk-level filter, and its recovery was declined in `sentence-level-removal-results.md`.

## 5.5 Crop routing

Distribution of the top 6 results:

| Query | DOC-001 Beans | DOC-002 Maize | DOC-003 Coffee |
|---|---|---|---|
| 1. how deep should beans be planted | **6** | 0 | 0 |
| 3. how to store harvested maize grain | 1 | **5** | 0 |
| 5. preparing rooting media for coffee cuttings | 0 | 0 | **6** |

Top 6 in full:

- **Query 1:** DOC-001-C025 0.733, C022 0.709, C023 0.689, C004 0.688, C009 0.687, C024 0.686
- **Query 3:** DOC-002-C036 0.761, DOC-002-C038 0.758, DOC-002-C037 0.757, DOC-002-C035 0.743, **DOC-001-C073 0.739**, DOC-002-C034 0.738
- **Query 5:** DOC-003-C051 0.803, C053 0.784, C057 0.784, C052 0.778, C060 0.770, C056 0.768

The one cross-crop result in query 3 is the beans storage section (DOC-001-C073), at 5th place.

## 5.6 Negative control

Query: `how do I treat a goat with a limp`

| Measure | Score |
|---|---|
| Top score | **0.5798** (DOC-003-C065, `PLANTLETS`) |
| Top 6 | 0.580, 0.558, 0.556, 0.555, 0.552, 0.551 |
| Real probes (5.4): lowest top score | 0.7032 |
| Real probes (5.4): highest top score | 0.8729 |
| Real probes (5.4): mean top score | 0.7709 |

The gap between the negative control and the weakest real probe is **0.12**. A Phase 3 relevance threshold would sit between 0.58 and 0.70.

## 5.7 Search latency

Mean over 10 queries:

| Step | Mean |
|---|---|
| Embedding API call | **1,071 ms** (min 734, max 1,327) |
| Load 301 chunks and decode their JSON vectors | **194 ms** |
| Dot products only | **13.7 ms** |
| `CosineRetriever::rank()` (load + score + sort) | **206 ms** |

**Not met as written.** Scoring 301 × 768 in plain PHP takes 13.7 ms, not low single digits. The larger cost is reloading and decoding every vector on each search.

The case against a vector database still holds: search is about a fifth of the embedding call, and the embedding call is paid either way.

**Phase 3 suggestion:** keep the decoded vectors in memory between searches instead of reloading them each time. This would remove most of the 194 ms.

## 5.8 Unit tests

`tests/Feature/CosineRetrieverTest.php`, with hand-made vectors and a stub embedding client (no API key, no network):

| # | Test | Result |
|---|---|---|
| 1 | Identical unit vectors score 1.0 (within 1e-9) | Pass |
| 2 | Orthogonal unit vectors score 0.0 | Pass |
| 3 | Opposite unit vectors score −1.0 | Pass |
| 4 | Results are ordered by descending score | Pass |
| 5 | `k` limits the number of results | Pass |
| 6 | A chunk with a wrong-length vector is skipped, not fatal | Pass |
| 7 | A chunk with a null embedding is excluded | Pass |

The full suite passes all 59 tests.

---

## API cost

| Use | Embedding calls |
|---|---|
| Confirming the key and model before building | 2 |
| Indexing all 301 chunks | 301 |
| Checks 5.3–5.7 | 14 |
| Investigating the probe 4 miss | 1 |
| **Total** | **318** |

No rate-limit errors occurred: the 386 s index run matches 301 calls at about 1.07 s each plus the 150 ms pause between calls.

---

## Where the brief proved wrong or ambiguous

1. **Latency expectation.** "Low single-digit milliseconds" for scoring does not hold in plain PHP (13.7 ms), and it leaves out the 194 ms spent loading vectors on every search. See 5.7.
2. **`int $k = null`.** Deprecated in PHP 8.4 (an implicitly nullable type); written as `?int $k = null`.
3. **Re-embedding on a model change.** The brief re-embeds only when the vector is missing or the text hash differs. The index command also re-embeds when `embedding_model` differs from the configured model; otherwise changing `EMBEDDING_MODEL` would leave old and new vectors mixed.
4. **`CosineRetriever::rank()`.** An extra public method taking an already-embedded vector, so `agrivisit:search` can time the embedding call and the search separately. The `Retriever` interface is exactly as specified.

---

## Findings for Phase 3

| Finding | Evidence | Suggestion |
|---|---|---|
| Crops are not always separated | Probe 4: beans beat maize by 0.0001 | Filter or boost by the document's `crops` field |
| Poor section labels in citations | `(MSV)`, `• V` on correct results | Expect these in rendered citations (C-01, accepted in Phase 1) |
| Relevance threshold | Negative control 0.58; real probes 0.70–0.87 | Set a threshold between 0.58 and 0.70 |
| Search reloads every vector | 194 ms per search | Keep decoded vectors in memory |
| Hardening-off section missing | Probe 6 passes on the chapter introduction only | Known cost of the declined Phase 1 item |

---

## Related files

- `prompts/IMPLEMENT-PHASE-2.md` — the brief implemented here
- `prompts/sentence-level-removal-results.md` — final Phase 1 state (301 chunks) and the declined items
- `prompts/corpus-findings-C01-C02.md` — heading fragmentation (C-01)
- `knowledge/corpus-register.md` — the generated Corpus Register
