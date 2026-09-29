# Phase 1 Bug Fix

Fix two bugs in the Week 3 Phase 1 corpus code. Both are in the code supplied in
the implementation brief, not in your implementation.

## Bug 1 — `chunk_ref` collisions on non-fresh ingestion

`IngestCorpus` builds `chunk_ref` from a running global counter
(`$chunkCounter`), so IDs only stay consistent when `--fresh` is used. A later
`--only=DOC-003` run reuses IDs another document already holds and hits the
unique constraint on `chunks.chunk_ref`.

Do not patch the counter. Make the ref derive from the document and the chunk's
position, so it is deterministic and cannot collide:

```php
'chunk_ref' => sprintf('%s-C%03d', $ref, $position + 1),
```

Remove `$chunkCounter` and its initialisation entirely.

This also makes citations self-describing: `DOC-003-C007` names its source
document, where `C-0014` does not.

## Bug 2 — word counts exclude numbers

`str_word_count` ignores numeric tokens, which matters in agronomy text full of
measurements, so a 400-word chunk records about 375.

Replace every use of `str_word_count($x)` with:

```php
count(preg_split('/\s+/u', trim($x), -1, PREG_SPLIT_NO_EMPTY))
```

Apply this in:

- `Chunker` — both the `min_words` comparison and the stored `word_count`
- `IngestCorpus` — the document `word_count`
- anywhere else it appears

Add a small private helper rather than repeating the expression inline.

Note: the chunks themselves were always the correct size — `windowed()` already
splits on `preg_split`. Only the recorded counts were wrong.

## After fixing

1. Update `ChunkerTest` to assert that a chunk containing numbers records the
   correct word count. Add a case where the text includes tokens such as `2.5`
   and `400kg`, and assert the count includes them.
2. Add a test that ingestion is idempotent without `--fresh`: ingest a document
   twice and assert the chunk count is unchanged and the `chunk_ref` values are
   identical both times.
3. Run the tests:

```bash
php artisan test --filter=ChunkerTest
php artisan test --filter=RestrictedSectionStripperTest
```

4. Run `php artisan agrivisit:ingest --fresh` once, since the `chunk_ref` format
   has changed.
5. Verify the original bug is gone: run `php artisan agrivisit:ingest --only=DOC-001`
   against a non-fresh database and confirm there is no unique-constraint error.

Step 5 is the important one — it is the exact command that reproduced the bug,
so it is the one that proves the fix.

## Report back

State which files changed, what the new tests cover, and confirm the `--only`
run succeeds. If either fix could not be applied as described, say which and why
rather than working around it silently.
