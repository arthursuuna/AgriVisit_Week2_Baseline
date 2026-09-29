# Phase 1 Hardening — Ingestion Robustness

Three problems surfaced on the first real ingestion run. Fix all three. None
change the chunking or stripping logic; they change how `IngestCorpus` handles
bad input in `sources.json`.

## Problem 1 — a bad date crashes the entire run

A `retrieved_on` value that Carbon cannot parse (for example the literal
placeholder `YYYY-MM-DD`) throws `Carbon\Exceptions\InvalidFormatException` and
aborts the whole command. Documents that would have ingested fine never get
processed.

This is inconsistent with how the command already treats extraction failures: a
PDF that cannot be read is reported and the run continues. Metadata problems
should behave the same way.

## Problem 2 — duplicate `doc_ref` values silently destroy data

`IngestCorpus` calls `Document::where('doc_ref', $ref)->delete()` before
inserting, and that cascades to chunks. If two entries in `sources.json` share a
`doc_ref`, the second silently deletes the first document and all its chunks. No
error is raised and the summary table looks normal.

A duplicate `doc_ref` must be refused, not processed.

## Problem 3 — chunk fragmentation is invisible

The first real run produced 141 chunks from 21,082 words: an average of about
150 words against a 400-word target. That is a signal worth seeing in the
summary, because it usually means heading detection is splitting the document
more finely than intended.

## What to implement

### A. Validate every entry before processing any of them

Add a private `validateSources(array $sources): array` to `IngestCorpus` that
returns `['valid' => [...], 'invalid' => [[ref, title, reason], ...]]`.

Check each entry for:

1. **Required fields present and non-empty:** `doc_ref`, `file_name`, `title`,
   `publisher`, `source_url`, `retrieved_on`.
2. **`retrieved_on` is a real date.** Parse it inside a try/catch, or validate
   against `Y-m-d` with `DateTime::createFromFormat` and check
   `getLastErrors()`. Reject the literal string `YYYY-MM-DD` with the message
   "retrieved_on is still the placeholder; set the date the file was downloaded".
3. **`doc_ref` is unique within the file.** On a repeat, reject the later entry
   with "duplicate doc_ref — the earlier entry would be overwritten".
4. **`doc_ref` matches `/^DOC-\d{3,}$/`.** Chunk refs are derived from it, so a
   malformed ref produces malformed citations.

Validation runs over the whole file first. Only valid entries proceed to
extraction. Invalid entries are collected and reported.

### B. Report invalid entries in their own table

Print them separately from extraction failures, since the causes differ:

```
Entries skipped (invalid metadata):
+---------+---------------------------+--------------------------------------------+
| Ref     | Title                     | Reason                                     |
+---------+---------------------------+--------------------------------------------+
```

Keep the existing "Documents that could not be ingested" table for extraction
failures. Two tables, two causes.

### C. Add a `--validate` option

`php artisan agrivisit:ingest --validate` runs validation and prints both the
valid and invalid entries, then exits without touching the database or reading
any PDF. This lets the register be checked before a long ingestion run.

### D. Show chunk statistics in the summary

Add two columns to the existing summary table: **Avg words** (document word
count divided by chunk count) and **Min/Max** chunk word count.

After the table, if any document's average chunk size is below half the
configured `chunk_words`, print a warning:

```
DOC-001: average chunk is 150 words against a 400 target. Heading detection may
be splitting this document too finely. Inspect a sample before relying on it.
```

This is a warning, not an error. The run still succeeds.

## Tests to add

Add to `tests/Feature/IngestCorpusTest.php`:

1. An entry with `retrieved_on` set to `YYYY-MM-DD` is reported as invalid and
   does not abort the run; a valid entry in the same file still ingests.
2. Two entries sharing a `doc_ref` produce one ingested document and one invalid
   entry, and the first document's chunks still exist afterwards.
3. An entry missing `publisher` is reported as invalid.
4. `--validate` makes no database changes: assert document and chunk counts are
   unchanged.

## Verification

```bash
php artisan test --filter=IngestCorpusTest
php artisan agrivisit:ingest --validate
php artisan agrivisit:ingest --fresh
```

The `--validate` run against the current `sources.json` should report the
duplicate `DOC-001` entry as invalid.

## Report back

State which files changed, what the new tests cover, and what `--validate`
reports against the current `sources.json`.
