# Phase 1 — Sentence-Level Dose Removal

Implement the follow-up from `final-dosing-fix-results.md`. Doing it now rather
than later matters: once Phase 2 embeds the corpus, any change to chunks means
re-embedding everything.

## Why

The chunk-level filter is correct but blunt. It removed 15 chunks, and most
carried a single dose sentence inside guidance a field-visit checklist would
actually use:

- "Check for disease/pest infestation (once every week)"
- "Check the moisture/water in the system (once every week)"
- Open the cages for 2 hours in the early morning; avoid overwatering; keep 50% shade
- Hand-pick and crush aphids; get rid of the ants that protect them
- Watering schedule by polypot size; keep the polythene cover airtight

Coffee lost 13 of 113 chunks, concentrated in nursery management — the most
checklist-ready material in the corpus.

The rule does not need to change. Only its blast radius does: remove the
**sentence** carrying the ratio, not the passage around it.

## The change

Add to `RestrictedSectionStripper`:

```php
public function stripMixRatioSentences(string $text): array
```

Returns `['text' => string, 'sentences_removed' => int]`.

1. Split the text into sentences. Split on `/(?<=[.!?])\s+/u` **and** on the
   bullet characters this corpus uses as separators — `➢`, `•`, `▪` — since the
   extractor runs bulleted lists together into one line.
2. Drop any sentence matching the existing `MIX_RATIO_PATTERN`.
3. Rejoin the survivors and return the count.

Apply it in `IngestCorpus` **after** `strip()` and **before** `Chunker::chunk()`.

## Keep the chunk-level filter as a backstop

Do not remove `chunkIsRestricted()`. The Roundup calculation in DOC-002 states
its amounts across several sentences, so no single sentence carries a ratio and
sentence-level removal alone would miss it. The two-signal rule (chemical
indicator plus volume quantity) still catches that whole passage.

Order of defence, from narrowest to widest:

1. Section exclusion by heading
2. Sentence-level mix-ratio removal (new)
3. Chunk-level two-signal filter (backstop)

## Recording it

Add a migration adding `sentences_removed` (unsigned integer, default 0) to
`documents`. Populate it during ingestion, add it to the ingest summary table and
to the Corpus Register alongside the existing counts.

## Tests

Add to `RestrictedSectionStripperTest`:

1. Given `"Check for pests weekly. Apply Mancozeb at 50gms in 20 litres of water. Keep the cover airtight."`, the result keeps both outer sentences and drops only the middle one, with `sentences_removed` of 1.
2. Given a bulleted run `"➢ Water twice daily ➢ Apply Cypermethrin 70ml in 20 litres ➢ Open cages for two hours"`, only the middle item is removed.
3. A passage with no ratio is returned unchanged with a count of 0.
4. The Roundup passage from DOC-002 still survives sentence-level removal but is
   still caught by `chunkIsRestricted()` — confirming the backstop works.

## Verification

```bash
php artisan migrate
php artisan test --filter=RestrictedSectionStripperTest
php artisan agrivisit:ingest --fresh
php artisan agrivisit:register
```

In `php artisan tinker`, all three safety checks must still return 0:

```php
App\Models\Chunk::where('text','like','%Roundup%')->orWhere('text','like','%150mls%')->count()
App\Models\Chunk::where('text','like','%Cypermethrin%')->orWhere('text','like','%Mancozeb%')->orWhere('text','like','%Carbend%')->count()
App\Models\Chunk::where('text','like','%per litre of water%')->orWhere('text','like','%in 20 litres%')->count()
```

Then confirm the recovery — this must now return at least 1:

```php
App\Models\Chunk::where('text','like','%disease/pest infestation%')->count()
```

Total chunks should rise from 290 toward roughly 300, and DOC-003's dropped count
should fall well below 13.

## Report back

State chunks dropped and sentences removed per document, confirm all three safety
checks return 0 and the recovery check returns at least 1, and list any chunk
still dropped that you judge genuinely useful.
