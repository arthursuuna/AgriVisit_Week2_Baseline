<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Corpus\Chunker;
use App\Services\Corpus\RestrictedSectionStripper;
use App\Services\Corpus\TextExtractor;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns the PDFs listed in sources.json into documents and chunks.
 *
 *   php artisan agrivisit:ingest
 *   php artisan agrivisit:ingest --fresh          # wipe and rebuild
 *   php artisan agrivisit:ingest --only=DOC-007   # one document
 *   php artisan agrivisit:ingest --validate       # check sources.json only
 *
 * Ingestion is idempotent: re-running replaces a document and its chunks rather
 * than duplicating them, so a chunking change can be applied and re-applied
 * without the corpus drifting.
 *
 * Every entry is validated before any is processed. A bad entry is reported and
 * skipped, the same way an unreadable PDF is, rather than aborting the run or,
 * for a duplicate doc_ref, silently overwriting an earlier document.
 */
class IngestCorpus extends Command
{
    private const REQUIRED_FIELDS = ['doc_ref', 'file_name', 'title', 'publisher', 'source_url', 'retrieved_on'];

    protected $signature = 'agrivisit:ingest
                            {--fresh : Delete all documents and chunks first}
                            {--only= : Ingest a single doc_ref}
                            {--validate : Check sources.json and exit without touching the database or any PDF}';

    protected $description = 'Ingest the corpus: extract, strip restricted sections, chunk and store';

    public function handle(
        TextExtractor $extractor,
        RestrictedSectionStripper $stripper,
        Chunker $chunker
    ): int {
        $sourcesFile = config('agrivisit.corpus.sources_file');

        if (! is_file($sourcesFile)) {
            $this->error("Sources file not found: {$sourcesFile}");

            return self::FAILURE;
        }

        $sources = json_decode(file_get_contents($sourcesFile), true);

        if (! is_array($sources)) {
            $this->error('sources.json is not valid JSON.');

            return self::FAILURE;
        }

        ['valid' => $valid, 'invalid' => $invalid] = $this->validateSources($sources);

        if ($this->option('validate')) {
            return $this->reportValidation($valid, $invalid);
        }

        if ($this->option('fresh')) {
            Chunk::query()->delete();
            Document::query()->delete();
            $this->warn('Existing corpus deleted.');
        }

        $only = $this->option('only');
        $rows = [];
        $failures = [];
        $fragmented = [];
        $target = (int) config('agrivisit.corpus.chunk_words');

        foreach ($valid as $source) {
            $ref = $source['doc_ref'];

            if ($only !== null && $ref !== $only) {
                continue;
            }

            $path = rtrim(config('agrivisit.corpus.pdf_path'), '/') . '/' . $source['file_name'];

            try {
                $extracted = $extractor->extract($path);
            } catch (Throwable $e) {
                $failures[] = [$ref, $source['title'] ?? '', $e->getMessage()];
                $this->error("  {$ref}: " . $e->getMessage());

                continue;
            }

            // Sentences go first: strip()'s line-level rate check would otherwise cut
            // single PDF lines out of a dose sentence and leave its halves behind.
            $sentences = $stripper->stripMixRatioSentences($extracted['text']);
            $stripped  = $stripper->strip($sentences['text']);
            $chunked   = $chunker->chunk($stripped['text']);
            $words     = $this->wordCount($stripped['text']);

            // Chunk-level backstop for dosing content that sits outside any
            // recognisable heading, such as a table or a worked example.
            $pieces  = array_values(array_filter(
                $chunked,
                fn ($piece) => ! $stripper->chunkIsRestricted($piece['text'])
            ));
            $dropped = count($chunked) - count($pieces);

            DB::transaction(function () use ($source, $ref, $extracted, $stripped, $sentences, $pieces, $words, $dropped) {
                Document::where('doc_ref', $ref)->delete();   // cascades to chunks

                $document = Document::create([
                    'doc_ref'           => $ref,
                    'title'             => $source['title'],
                    'publisher'         => $source['publisher'],
                    'source_url'        => $source['source_url'],
                    'licence'           => $source['licence'] ?? null,
                    'retrieved_on'      => $source['retrieved_on'],
                    'crops'             => $source['crops'] ?? [],
                    'file_name'         => $source['file_name'],
                    'page_count'        => $extracted['pages'],
                    'word_count'        => $words,
                    'sections_removed'  => $stripped['sections_removed'],
                    'removed_headings'  => $stripped['removed_headings'],
                    'sentences_removed' => $sentences['sentences_removed'],
                    'chunks_dropped'    => $dropped,
                    'notes'             => $source['notes'] ?? null,
                ]);

                foreach ($pieces as $position => $piece) {
                    Chunk::create([
                        'document_id' => $document->id,
                        'chunk_ref'   => sprintf('%s-C%03d', $ref, $position + 1),
                        'section'     => $piece['section'],
                        'position'    => $position,
                        'text'        => $piece['text'],
                        'word_count'  => $piece['word_count'],
                    ]);
                }
            });

            $sizes = array_column($pieces, 'word_count');
            $avg   = $sizes === [] ? null : (int) round($words / count($sizes));

            if ($avg !== null && $avg < $target / 2) {
                $fragmented[$ref] = $avg;
            }

            $rows[] = [
                $ref,
                mb_strimwidth($source['title'], 0, 38, '…'),
                $extracted['pages'],
                number_format($words),
                count($pieces),
                $avg ?? '—',
                $sizes === [] ? '—' : min($sizes) . '/' . max($sizes),
                $stripped['sections_removed'],
                $sentences['sentences_removed'],
                $dropped,
            ];

            $this->line("  {$ref}: " . count($pieces) . ' chunks');
        }

        $this->newLine();
        $this->table(
            ['Ref', 'Title', 'Pages', 'Words', 'Chunks', 'Avg words', 'Min/Max', 'Sections cut', 'Sentences cut', 'Chunks dropped'],
            $rows
        );

        foreach ($fragmented as $ref => $avg) {
            $this->warn(
                "{$ref}: average chunk is {$avg} words against a {$target} target. Heading detection may\n"
                . 'be splitting this document too finely. Inspect a sample before relying on it.'
            );
        }

        if ($invalid !== []) {
            $this->newLine();
            $this->error('Entries skipped (invalid metadata):');
            $this->table(['Ref', 'Title', 'Reason'], $invalid);
        }

        if ($failures !== []) {
            $this->newLine();
            $this->error('Documents that could not be ingested:');
            $this->table(['Ref', 'Title', 'Reason'], $failures);
            $this->comment('Record these in the Corpus Register. A document that will not extract is a finding, not a gap to hide.');
        }

        $this->newLine();
        $this->info(sprintf(
            'Corpus now holds %d documents and %d chunks.',
            Document::count(),
            Chunk::count()
        ));

        return self::SUCCESS;
    }

    /**
     * Checks every entry before any is processed. The first entry to claim a
     * doc_ref keeps it; a later repeat is rejected rather than allowed to delete
     * the earlier document and its chunks.
     *
     * @return array{valid: list<array>, invalid: list<array{0: string, 1: string, 2: string}>}
     */
    private function validateSources(array $sources): array
    {
        $valid   = [];
        $invalid = [];
        $seen    = [];

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                $invalid[] = ['#' . ($index + 1), '', 'entry is not a JSON object'];

                continue;
            }

            $ref   = is_string($source['doc_ref'] ?? null) ? $source['doc_ref'] : '#' . ($index + 1);
            $title = is_string($source['title'] ?? null) ? $source['title'] : '';
            $error = $this->entryError($source, $seen);

            if ($error !== null) {
                $invalid[] = [$ref, mb_strimwidth($title, 0, 38, '…'), $error];

                continue;
            }

            $seen[$ref] = true;
            $valid[]    = $source;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /** @param array<string, true> $seen doc_refs already accepted */
    private function entryError(array $source, array $seen): ?string
    {
        $missing = array_filter(
            self::REQUIRED_FIELDS,
            fn ($field) => ! is_string($source[$field] ?? null) || trim($source[$field]) === ''
        );

        if ($missing !== []) {
            return 'missing or empty: ' . implode(', ', $missing);
        }

        if (preg_match('/^DOC-\d{3,}$/', $source['doc_ref']) !== 1) {
            return 'doc_ref must look like DOC-001; chunk citations are derived from it';
        }

        if (isset($seen[$source['doc_ref']])) {
            return 'duplicate doc_ref — the earlier entry would be overwritten';
        }

        if ($source['retrieved_on'] === 'YYYY-MM-DD') {
            return 'retrieved_on is still the placeholder; set the date the file was downloaded';
        }

        $date = DateTime::createFromFormat('!Y-m-d', $source['retrieved_on']);

        if ($date === false || DateTime::getLastErrors() !== false || $date->format('Y-m-d') !== $source['retrieved_on']) {
            return "retrieved_on \"{$source['retrieved_on']}\" is not a real date in YYYY-MM-DD form";
        }

        return null;
    }

    /** Prints the outcome of --validate. Reads no PDF and writes nothing. */
    private function reportValidation(array $valid, array $invalid): int
    {
        $pdfPath = rtrim(config('agrivisit.corpus.pdf_path'), '/');

        $this->info('Valid entries:');
        $this->table(['Ref', 'Title', 'File', 'File present'], array_map(fn ($s) => [
            $s['doc_ref'],
            mb_strimwidth($s['title'], 0, 38, '…'),
            $s['file_name'],
            is_file($pdfPath . '/' . $s['file_name']) ? 'yes' : 'NO',
        ], $valid));

        if ($invalid !== []) {
            $this->newLine();
            $this->error('Entries skipped (invalid metadata):');
            $this->table(['Ref', 'Title', 'Reason'], $invalid);
        }

        $this->newLine();
        $this->line(sprintf('%d valid, %d invalid. Nothing was ingested.', count($valid), count($invalid)));

        return $invalid === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Whitespace-delimited count, so numeric tokens such as "2.5" are included. */
    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
