# Implement Week 3 Phase 1 — Corpus Ingestion

You are implementing Phase 1 of Week 3 for **AgriVisit**, an existing Laravel
application. Work through this brief top to bottom. Do not skip the verification
step at the end.

## 1. Context

AgriVisit drafts field-visit checklists for Ugandan agricultural extension
officers. Week 2 built a baseline: a Gemini-backed drafting service behind an
`LlmClient` interface, a deterministic `RestrictedTopicGuard`, a strict
`ChecklistParser`, and versioned prompt templates. All of that already exists and
**must not be modified**.

Week 3 adds retrieval. This phase does **only** the corpus pipeline: read source
PDFs, remove restricted content, split into passages, store them with provenance,
and generate a register. There is no embedding, no similarity search and no
prompt change in this phase — those come later and must not be built now.

### Existing files you may add to, but not rewrite

- `config/agrivisit.php` — add a new `corpus` block; leave existing keys alone.
- `app/Providers/AgriVisitServiceProvider.php` — add bindings inside the existing
  `register()` method; leave existing bindings alone.

### Existing files you must not touch

`app/Services/Llm/*`, `app/Services/Checklist/*`, `app/Services/Prompts/*`,
`app/Http/Controllers/ChecklistController.php`, `resources/prompts/*`,
`resources/views/checklist/*`, `routes/web.php`.

## 2. Design rules that must hold

These are not preferences. Implement them exactly.

1. **Chunks never cross a heading.** A passage about banana spacing and one about
   coffee pruning must never share a chunk, or retrieval returns both when only
   one is relevant.
2. **Consecutive chunks overlap by a configured number of words**, so a sentence
   falling on a boundary survives whole in at least one chunk.
3. **Dosing and veterinary content is removed at ingestion, not filtered at query
   time.** Retrieval cannot surface a passage that is not in the corpus. The
   existing `RestrictedTopicGuard` still runs separately; this closes the third
   route, where restricted content reaches the model as retrieved evidence.
4. **Every removal is counted and its heading recorded.** A corpus that silently
   drops content is not a documented corpus.
5. **Ingestion is idempotent.** Re-running replaces a document and its chunks
   rather than duplicating them.
6. **A PDF with no text layer fails loudly.** Under 20 extracted words per page
   means a scan; reject it with a message saying so rather than storing an empty
   document.
7. **Sizing is in words, not tokens.** PHP has no tokeniser. Do not add one, and
   do not pull in a tokenisation package.

## 3. Setup steps

```bash
composer require smalot/pdfparser
```

1. Merge the `corpus` config block into `config/agrivisit.php`.
2. Merge the provider bindings into `AgriVisitServiceProvider::register()`, adding
   the four `use` statements listed in that snippet.
3. Create the two migrations, then run `php artisan migrate`.
4. Create the models, services, commands and tests.
5. Create `storage/app/corpus/pdfs/` and add it to `.gitignore`.
6. Create `storage/app/corpus/sources.json` from the template.

---

## 4. File contents

Create each file exactly as given. Do not paraphrase the code.

### `config snippet — merge into config/agrivisit.php`

```php
<?php

// Add this 'corpus' block to config/agrivisit.php, alongside the existing keys.

return [

    'corpus' => [
        /*
        | Where the source PDFs live. Kept out of version control: the register
        | records where each document came from, so the files themselves need not
        | be committed and publisher licences stay respected.
        */
        'pdf_path'     => storage_path('app/corpus/pdfs'),
        'sources_file' => storage_path('app/corpus/sources.json'),

        /*
        | Chunk sizing, in words.
        |
        | PHP has no tokeniser, so chunks are measured in words. English runs at
        | roughly 1.3 tokens per word, so 400 words is about 520 tokens: large
        | enough to hold a complete instruction, small enough that retrieving one
        | does not flood the prompt.
        |
        | The overlap exists so a sentence falling on a boundary survives whole in
        | at least one chunk.
        */
        'chunk_words'   => 400,
        'overlap_words' => 80,
        'min_words'     => 40,   // discard fragments smaller than this

        /*
        | Headings whose sections are dropped during ingestion.
        |
        | The AI Boundary Matrix forbids dosing advice. Excluding it at ingestion
        | rather than filtering at query time means retrieval cannot surface it at
        | all: the guardrail has less to catch because the content is not there.
        | Every removal is counted and recorded in the Corpus Register.
        */
        'excluded_headings' => [
            'dosage', 'dose rate', 'application rate', 'rates of application',
            'spray schedule', 'spray programme', 'spray program', 'chemical control',
            'recommended pesticides', 'pesticide application', 'mixing instructions',
            'dilution', 'veterinary', 'treatment schedule',
        ],
    ],
];
```

### `database/migrations/2026_01_01_000001_create_documents_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per source document in the controlled corpus.
 *
 * Every column below exists to answer a provenance question: where did this
 * come from, who published it, may we use it, and what did ingestion change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_ref')->unique();      // DOC-001, cited in every chunk
            $table->string('title');
            $table->string('publisher');
            $table->text('source_url');
            $table->string('licence')->nullable();
            $table->date('retrieved_on');
            $table->json('crops')->nullable();        // ["maize","beans"]
            $table->string('file_name');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('sections_removed')->default(0);
            $table->json('removed_headings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

### `database/migrations/2026_01_01_000002_create_chunks_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per retrievable passage.
 *
 * The embedding column stays null through Phase 1. It is filled in Phase 2 by
 * the indexing command, which is why the schema carries it from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('chunk_ref')->unique();    // C-0001, the ID the model cites
            $table->string('section')->nullable();    // heading the chunk sits under
            $table->unsignedInteger('position');      // order within the document
            $table->longText('text');
            $table->unsignedInteger('word_count');
            $table->longText('embedding')->nullable();        // JSON array, Phase 2
            $table->string('embedding_model')->nullable();    // Phase 2
            $table->timestamps();

            $table->index(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
```

### `app/Models/Document.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $guarded = [];

    protected $casts = [
        'crops'            => 'array',
        'removed_headings' => 'array',
        'retrieved_on'     => 'date',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
```

### `app/Models/Chunk.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Human-readable citation, shown beneath a checklist item. */
    public function citation(): string
    {
        $section = $this->section ? ", {$this->section}" : '';

        return "{$this->document->title}{$section} [{$this->chunk_ref}]";
    }
}
```

### `app/Services/Corpus/TextExtractor.php`

```php
<?php

namespace App\Services\Corpus;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Pulls plain text out of a source PDF.
 *
 * Requires: composer require smalot/pdfparser
 *
 * Extraction quality varies a great deal between publishers. A born-digital
 * report extracts cleanly; a scanned leaflet yields nothing, because there is no
 * text layer to extract. This class reports what it got rather than pretending,
 * so a document that fails is caught at ingestion and recorded in the register
 * instead of silently becoming an empty document in the corpus.
 */
class TextExtractor
{
    public function __construct(private readonly Parser $parser)
    {
    }

    /**
     * @return array{text: string, pages: int, words: int}
     */
    public function extract(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("PDF not found: {$path}");
        }

        try {
            $pdf = $this->parser->parseFile($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not parse PDF: ' . $e->getMessage(), 0, $e);
        }

        $pages = count($pdf->getPages());
        $text  = $this->normalise($pdf->getText());
        $words = str_word_count($text);

        // A text layer of almost nothing means a scan. Say so plainly.
        if ($pages > 0 && $words / max($pages, 1) < 20) {
            throw new RuntimeException(
                sprintf(
                    'Extracted only %d words across %d pages. This is probably a scanned '
                    . 'document with no text layer; it needs OCR or should be replaced.',
                    $words,
                    $pages
                )
            );
        }

        return ['text' => $text, 'pages' => $pages, 'words' => $words];
    }

    /**
     * Tidies the usual PDF extraction artefacts: hyphenated line breaks, hard
     * wrapping mid-sentence, repeated blank lines and non-breaking spaces.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $text);

        // Re-join words split across a line break: "germina-\ntion" -> "germination"
        $text = preg_replace('/(\w)-\n(\w)/u', '$1$2', $text);

        // Re-join a sentence hard-wrapped mid-clause, but keep real paragraph breaks.
        $text = preg_replace('/([a-z,;])\n([a-z])/u', '$1 $2', $text);

        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
```

### `app/Services/Corpus/RestrictedSectionStripper.php`

```php
<?php

namespace App\Services\Corpus;

/**
 * Removes dosing and veterinary material before it ever reaches the index.
 *
 * The AI Boundary Matrix says the system never produces pesticide doses or
 * clinical advice. RestrictedTopicGuard enforces that at request and response
 * time. This class closes the third route: content that could be retrieved and
 * fed to the model as evidence.
 *
 * Removing it here is stronger than filtering later. Retrieval cannot surface a
 * passage that is not in the corpus, so this is prevention rather than defence.
 *
 * Every removal is counted and the headings recorded, because a corpus that
 * silently drops content is not a documented corpus.
 */
class RestrictedSectionStripper
{
    /** Numeric application rates: "200 ml/l", "2.5 kg per hectare", "50 ml per acre". */
    private const RATE_PATTERN =
        '/\b\d+(?:[.,]\d+)?\s*(?:ml|l|litres?|liters?|kg|g|grams?|oz|lbs?)\s*'
        . '(?:\/|per\s+)\s*(?:l|litre|liter|ha|hectare|acre|plant|tree|knapsack|tank|'
        . 'animal|head|bird)\b/iu';

    /** @param list<string> $excludedHeadings */
    public function __construct(private readonly array $excludedHeadings)
    {
    }

    /**
     * @return array{text: string, sections_removed: int, removed_headings: list<string>, lines_removed: int}
     */
    public function strip(string $text): array
    {
        $lines   = explode("\n", $text);
        $kept    = [];
        $removed = [];
        $linesRemoved = 0;
        $skipping = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($this->looksLikeHeading($trimmed)) {
                // A new heading always ends any skip already in progress.
                $skipping = $this->isExcludedHeading($trimmed);

                if ($skipping) {
                    $removed[] = $trimmed;
                    $linesRemoved++;

                    continue;
                }
            }

            if ($skipping) {
                $linesRemoved++;

                continue;
            }

            // Catch a stray rate sitting outside any excluded section.
            if (preg_match(self::RATE_PATTERN, $trimmed) === 1) {
                $linesRemoved++;

                continue;
            }

            $kept[] = $line;
        }

        return [
            'text'             => trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $kept))),
            'sections_removed' => count($removed),
            'removed_headings' => array_values(array_unique($removed)),
            'lines_removed'    => $linesRemoved,
        ];
    }

    /**
     * Headings in extension material are short lines that do not end in a full
     * stop: numbered ("3.2 Land preparation"), capitalised, or title case.
     */
    public function looksLikeHeading(string $line): bool
    {
        if ($line === '' || mb_strlen($line) > 80) {
            return false;
        }

        if (str_ends_with($line, '.') || str_ends_with($line, ',')) {
            return false;
        }

        if (preg_match('/^\d+(\.\d+)*[\s.)-]+\S/u', $line) === 1) {
            return true;
        }

        $letters = preg_replace('/[^a-z]/iu', '', $line);

        if ($letters === '') {
            return false;
        }

        // Mostly upper case, or Title Case Across Several Words.
        $upperRatio = mb_strlen(preg_replace('/[^A-Z]/u', '', $line)) / mb_strlen($letters);

        if ($upperRatio > 0.7) {
            return true;
        }

        $words = preg_split('/\s+/u', $line);

        if (count($words) >= 2 && count($words) <= 8) {
            $capitalised = 0;

            foreach ($words as $word) {
                if (preg_match('/^[A-Z]/u', $word) === 1) {
                    $capitalised++;
                }
            }

            return $capitalised / count($words) > 0.6;
        }

        return false;
    }

    private function isExcludedHeading(string $heading): bool
    {
        $needle = mb_strtolower($heading);

        foreach ($this->excludedHeadings as $term) {
            if (str_contains($needle, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }
}
```

### `app/Services/Corpus/Chunker.php`

```php
<?php

namespace App\Services\Corpus;

/**
 * Splits a document into retrievable passages.
 *
 * Two rules drive the design.
 *
 * First, chunks never cross a heading. A passage about banana spacing and a
 * passage about coffee pruning must not end up in the same chunk, because
 * retrieval would then return both when only one is relevant, and the model
 * would have to guess which half applies.
 *
 * Second, consecutive chunks overlap. A sentence that lands on a boundary
 * survives whole in at least one chunk, so retrieval cannot lose an instruction
 * by cutting it in half.
 *
 * Sizing is in words rather than tokens because PHP has no tokeniser. At roughly
 * 1.3 tokens per word, the configured 400 words is about 520 tokens.
 */
class Chunker
{
    public function __construct(
        private readonly RestrictedSectionStripper $headings,
        private readonly int $chunkWords = 400,
        private readonly int $overlapWords = 80,
        private readonly int $minWords = 40,
    ) {
    }

    /**
     * @return list<array{section: string|null, text: string, word_count: int}>
     */
    public function chunk(string $text): array
    {
        $chunks = [];

        foreach ($this->splitBySection($text) as $section) {
            foreach ($this->windowed($section['text']) as $piece) {
                $count = str_word_count($piece);

                if ($count < $this->minWords) {
                    continue;
                }

                $chunks[] = [
                    'section'    => $section['heading'],
                    'text'       => $piece,
                    'word_count' => $count,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Groups the document into sections under their headings. Text before the
     * first heading becomes a section with no heading.
     *
     * @return list<array{heading: string|null, text: string}>
     */
    private function splitBySection(string $text): array
    {
        $sections = [];
        $heading  = null;
        $buffer   = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);

            if ($this->headings->looksLikeHeading($trimmed)) {
                if (trim(implode("\n", $buffer)) !== '') {
                    $sections[] = ['heading' => $heading, 'text' => trim(implode("\n", $buffer))];
                }

                $heading = $trimmed;
                $buffer  = [];

                continue;
            }

            $buffer[] = $line;
        }

        if (trim(implode("\n", $buffer)) !== '') {
            $sections[] = ['heading' => $heading, 'text' => trim(implode("\n", $buffer))];
        }

        return $sections;
    }

    /**
     * Slides a fixed-size window over the words, stepping forward by
     * (chunkWords - overlapWords) each time.
     *
     * @return list<string>
     */
    private function windowed(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $total = count($words);

        if ($total === 0) {
            return [];
        }

        if ($total <= $this->chunkWords) {
            return [implode(' ', $words)];
        }

        $step = max(1, $this->chunkWords - $this->overlapWords);
        $out  = [];

        for ($start = 0; $start < $total; $start += $step) {
            $slice = array_slice($words, $start, $this->chunkWords);

            if ($slice === []) {
                break;
            }

            $out[] = implode(' ', $slice);

            // Stop once the window has reached the end of the text.
            if ($start + $this->chunkWords >= $total) {
                break;
            }
        }

        return $out;
    }
}
```

### `app/Console/Commands/IngestCorpus.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Corpus\Chunker;
use App\Services\Corpus\RestrictedSectionStripper;
use App\Services\Corpus\TextExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns the PDFs listed in sources.json into documents and chunks.
 *
 *   php artisan agrivisit:ingest
 *   php artisan agrivisit:ingest --fresh          # wipe and rebuild
 *   php artisan agrivisit:ingest --only=DOC-007   # one document
 *
 * Ingestion is idempotent: re-running replaces a document and its chunks rather
 * than duplicating them, so a chunking change can be applied and re-applied
 * without the corpus drifting.
 */
class IngestCorpus extends Command
{
    protected $signature = 'agrivisit:ingest
                            {--fresh : Delete all documents and chunks first}
                            {--only= : Ingest a single doc_ref}';

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

        if ($this->option('fresh')) {
            Chunk::query()->delete();
            Document::query()->delete();
            $this->warn('Existing corpus deleted.');
        }

        $only = $this->option('only');
        $rows = [];
        $failures = [];
        $chunkCounter = Chunk::max('id') ? Chunk::count() : 0;

        foreach ($sources as $source) {
            $ref = $source['doc_ref'] ?? null;

            if ($ref === null || ($only !== null && $ref !== $only)) {
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

            $stripped = $stripper->strip($extracted['text']);
            $pieces   = $chunker->chunk($stripped['text']);

            DB::transaction(function () use ($source, $ref, $extracted, $stripped, $pieces, &$chunkCounter) {
                Document::where('doc_ref', $ref)->delete();   // cascades to chunks

                $document = Document::create([
                    'doc_ref'          => $ref,
                    'title'            => $source['title'],
                    'publisher'        => $source['publisher'],
                    'source_url'       => $source['source_url'],
                    'licence'          => $source['licence'] ?? null,
                    'retrieved_on'     => $source['retrieved_on'],
                    'crops'            => $source['crops'] ?? [],
                    'file_name'        => $source['file_name'],
                    'page_count'       => $extracted['pages'],
                    'word_count'       => str_word_count($stripped['text']),
                    'sections_removed' => $stripped['sections_removed'],
                    'removed_headings' => $stripped['removed_headings'],
                    'notes'            => $source['notes'] ?? null,
                ]);

                foreach ($pieces as $position => $piece) {
                    $chunkCounter++;

                    Chunk::create([
                        'document_id' => $document->id,
                        'chunk_ref'   => sprintf('C-%04d', $chunkCounter),
                        'section'     => $piece['section'],
                        'position'    => $position,
                        'text'        => $piece['text'],
                        'word_count'  => $piece['word_count'],
                    ]);
                }
            });

            $rows[] = [
                $ref,
                mb_strimwidth($source['title'], 0, 38, '…'),
                $extracted['pages'],
                number_format(str_word_count($stripped['text'])),
                count($pieces),
                $stripped['sections_removed'],
            ];

            $this->line("  {$ref}: " . count($pieces) . ' chunks');
        }

        $this->newLine();
        $this->table(['Ref', 'Title', 'Pages', 'Words', 'Chunks', 'Sections cut'], $rows);

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
}
```

### `app/Console/Commands/BuildCorpusRegister.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Console\Command;

/**
 * Generates the Corpus/Source Register from the database.
 *
 *   php artisan agrivisit:register
 *
 * The register is generated rather than hand-maintained so it cannot drift away
 * from what is actually indexed. Re-run it after every ingest.
 */
class BuildCorpusRegister extends Command
{
    protected $signature = 'agrivisit:register {--out= : Output path}';

    protected $description = 'Write the Corpus/Source Register from the ingested corpus';

    public function handle(): int
    {
        $out = $this->option('out') ?: base_path('knowledge/corpus-register.md');

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }

        $documents = Document::withCount('chunks')->orderBy('doc_ref')->get();

        if ($documents->isEmpty()) {
            $this->error('No documents ingested yet. Run agrivisit:ingest first.');

            return self::FAILURE;
        }

        $md = "# Corpus / Source Register\n\n";
        $md .= "AgriVisit — AgriVisit_Capstone. Generated from the ingested corpus by "
            . "`php artisan agrivisit:register`; do not edit by hand.\n\n";

        $md .= "| Documents | Chunks | Words | Sections removed |\n|---|---|---|---|\n";
        $md .= sprintf(
            "| %d | %d | %s | %d |\n\n",
            $documents->count(),
            Chunk::count(),
            number_format($documents->sum('word_count')),
            $documents->sum('sections_removed')
        );

        $md .= "## Documents\n\n";
        $md .= "| Ref | Title | Publisher | Crops | Licence | Retrieved | Pages | Chunks |\n";
        $md .= "|---|---|---|---|---|---|---|---|\n";

        foreach ($documents as $doc) {
            $md .= sprintf(
                "| %s | [%s](%s) | %s | %s | %s | %s | %d | %d |\n",
                $doc->doc_ref,
                str_replace('|', '\\|', $doc->title),
                $doc->source_url,
                str_replace('|', '\\|', $doc->publisher),
                implode(', ', $doc->crops ?? []),
                $doc->licence ?: 'not stated',
                $doc->retrieved_on?->toDateString() ?? '',
                $doc->page_count,
                $doc->chunks_count
            );
        }

        $withRemovals = $documents->where('sections_removed', '>', 0);

        $md .= "\n## Sections removed at ingestion\n\n";
        $md .= "Dosing and veterinary sections are excluded before indexing, so retrieval "
            . "cannot surface them. Each removal is listed here.\n\n";

        if ($withRemovals->isEmpty()) {
            $md .= "No sections were removed from the current corpus.\n";
        } else {
            $md .= "| Ref | Sections cut | Headings |\n|---|---|---|\n";

            foreach ($withRemovals as $doc) {
                $md .= sprintf(
                    "| %s | %d | %s |\n",
                    $doc->doc_ref,
                    $doc->sections_removed,
                    str_replace('|', '\\|', implode('; ', $doc->removed_headings ?? []))
                );
            }
        }

        $notes = $documents->whereNotNull('notes')->where('notes', '!=', '');

        if ($notes->isNotEmpty()) {
            $md .= "\n## Notes\n\n";

            foreach ($notes as $doc) {
                $md .= "- **{$doc->doc_ref}** — {$doc->notes}\n";
            }
        }

        file_put_contents($out, $md);

        $this->info("Register written to {$out}");

        return self::SUCCESS;
    }
}
```

### `tests/Feature/RestrictedSectionStripperTest.php`

```php
<?php

namespace Tests\Feature;

use App\Services\Corpus\RestrictedSectionStripper;
use Tests\TestCase;

class RestrictedSectionStripperTest extends TestCase
{
    private function stripper(): RestrictedSectionStripper
    {
        return new RestrictedSectionStripper(config('agrivisit.corpus.excluded_headings'));
    }

    private function sample(): string
    {
        return "MAIZE PRODUCTION GUIDE\n\n"
            . "1. Land Preparation\n"
            . "Clear the plot and remove crop residue before the rains begin.\n\n"
            . "2. Chemical Control\n"
            . "Apply 200 ml/l of the product to the knapsack sprayer.\n"
            . "Use protective clothing during all spraying operations.\n\n"
            . "3. Mulching Practice\n"
            . "Mulch conserves soil moisture and suppresses weed growth.\n"
            . "Apply 2.5 kg per hectare at planting.\n"
            . "The farmer should record the planting date in the book.\n";
    }

    public function test_it_removes_an_excluded_section_including_its_body(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringNotContainsString('protective clothing', $result['text']);
        $this->assertStringNotContainsString('200 ml/l', $result['text']);
        $this->assertSame(1, $result['sections_removed']);
        $this->assertContains('2. Chemical Control', $result['removed_headings']);
    }

    public function test_it_removes_a_stray_rate_line_outside_an_excluded_section(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringNotContainsString('2.5 kg per hectare', $result['text']);
    }

    public function test_it_keeps_the_line_next_to_a_removed_rate(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringContainsString('record the planting date', $result['text']);
    }

    public function test_it_keeps_legitimate_sections(): void
    {
        $result = $this->stripper()->strip($this->sample());

        $this->assertStringContainsString('Clear the plot', $result['text']);
        $this->assertStringContainsString('Mulch conserves', $result['text']);
    }

    public function test_it_recognises_heading_shapes(): void
    {
        $s = $this->stripper();

        $this->assertTrue($s->looksLikeHeading('3.2 Land Preparation'));
        $this->assertTrue($s->looksLikeHeading('CHEMICAL CONTROL'));
        $this->assertTrue($s->looksLikeHeading('Mulching Practice'));
        $this->assertFalse($s->looksLikeHeading('Clear the plot and remove crop residue before the rains.'));
        $this->assertFalse($s->looksLikeHeading(''));
    }
}
```

### `tests/Feature/ChunkerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Services\Corpus\Chunker;
use App\Services\Corpus\RestrictedSectionStripper;
use Tests\TestCase;

class ChunkerTest extends TestCase
{
    private function chunker(int $size = 400, int $overlap = 80, int $min = 40): Chunker
    {
        return new Chunker(
            new RestrictedSectionStripper(config('agrivisit.corpus.excluded_headings')),
            $size,
            $overlap,
            $min
        );
    }

    private function words(int $n, string $stem = 'word'): string
    {
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[] = $stem . $i;
        }

        return implode(' ', $out);
    }

    public function test_a_short_section_becomes_one_chunk(): void
    {
        $text = "Land Preparation\n" . $this->words(120);

        $chunks = $this->chunker()->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertSame('Land Preparation', $chunks[0]['section']);
    }

    public function test_consecutive_chunks_overlap_by_the_configured_word_count(): void
    {
        $text = "Land Preparation\n" . $this->words(1000);

        $chunks = $this->chunker()->chunk($text);

        $this->assertGreaterThan(1, count($chunks));

        $tail = array_slice(explode(' ', $chunks[0]['text']), -80);
        $head = array_slice(explode(' ', $chunks[1]['text']), 0, 80);

        $this->assertSame($tail, $head);
    }

    public function test_no_words_are_lost_across_chunks(): void
    {
        $text = "Land Preparation\n" . $this->words(1000);

        $seen = [];

        foreach ($this->chunker()->chunk($text) as $chunk) {
            foreach (explode(' ', $chunk['text']) as $word) {
                $seen[$word] = true;
            }
        }

        $this->assertCount(1000, $seen);
    }

    public function test_a_chunk_never_spans_two_sections(): void
    {
        $text = "Banana Spacing\n" . $this->words(60, 'banana')
            . "\nCoffee Pruning\n" . $this->words(60, 'coffee');

        foreach ($this->chunker()->chunk($text) as $chunk) {
            $hasBanana = str_contains($chunk['text'], 'banana0');
            $hasCoffee = str_contains($chunk['text'], 'coffee0');

            $this->assertFalse($hasBanana && $hasCoffee, 'A chunk spanned two sections.');
        }
    }

    public function test_fragments_below_the_minimum_are_discarded(): void
    {
        $text = "Tiny Section\n" . $this->words(10);

        $this->assertSame([], $this->chunker()->chunk($text));
    }
}
```

### `storage/app/corpus/sources.json — template, three example rows`

```json
[
  {
    "doc_ref": "DOC-001",
    "file_name": "maize-production-guide.pdf",
    "title": "Maize Production Guide",
    "publisher": "NARO Uganda",
    "source_url": "https://example.org/replace-with-real-url.pdf",
    "licence": "Public, no reuse restriction stated",
    "retrieved_on": "2026-10-05",
    "crops": ["maize"],
    "notes": ""
  },
  {
    "doc_ref": "DOC-002",
    "file_name": "bean-agronomy-factsheet.pdf",
    "title": "Common Bean Agronomy Factsheet",
    "publisher": "CGIAR / CIAT",
    "source_url": "https://example.org/replace-with-real-url.pdf",
    "licence": "CC BY 4.0",
    "retrieved_on": "2026-10-05",
    "crops": ["beans"],
    "notes": ""
  },
  {
    "doc_ref": "DOC-003",
    "file_name": "robusta-coffee-handbook.pdf",
    "title": "Robusta Coffee Handbook",
    "publisher": "Uganda Coffee Development Authority",
    "source_url": "https://example.org/replace-with-real-url.pdf",
    "licence": "Public",
    "retrieved_on": "2026-10-05",
    "crops": ["coffee"],
    "notes": "Chemical control chapter removed at ingestion."
  }
]
```

### `provider bindings — merge into AgriVisitServiceProvider::register()`

```php
<?php

/*
 * Add these bindings to AgriVisitServiceProvider::register().
 *
 * Imports needed at the top of that file:
 *   use App\Services\Corpus\Chunker;
 *   use App\Services\Corpus\RestrictedSectionStripper;
 *   use App\Services\Corpus\TextExtractor;
 *   use Smalot\PdfParser\Parser;
 */

$this->app->singleton(TextExtractor::class, fn () => new TextExtractor(new Parser()));

$this->app->singleton(RestrictedSectionStripper::class, fn () => new RestrictedSectionStripper(
    config('agrivisit.corpus.excluded_headings')
));

$this->app->singleton(Chunker::class, fn ($app) => new Chunker(
    $app->make(RestrictedSectionStripper::class),
    config('agrivisit.corpus.chunk_words'),
    config('agrivisit.corpus.overlap_words'),
    config('agrivisit.corpus.min_words')
));
```

---

## 5. Verification

Run these in order. Do not report the phase complete until all pass.

```bash
php artisan migrate:status                       # both corpus migrations shown as ran
php artisan test --filter=RestrictedSectionStripperTest
php artisan test --filter=ChunkerTest
php artisan agrivisit:ingest                     # with at least one real PDF present
php artisan agrivisit:register
```

The two test classes need no API key, no network and no PDFs. They must pass
before ingestion is attempted. If either fails, fix the service, not the test.

### Expected results

| Check | Expected |
|---|---|
| `ChunkerTest` | 5 passing assertions, including exact 80-word overlap and no lost words |
| `RestrictedSectionStripperTest` | 5 passing assertions, including section body removal |
| `agrivisit:ingest` | A summary table with one row per document, and a separate failures table if any PDF could not be read |
| `agrivisit:register` | `knowledge/corpus-register.md` written, listing every document and every removed heading |

### Common first-run problems

- **"PDF not found"** — `file_name` in `sources.json` does not match the actual
  file in `storage/app/corpus/pdfs/`. This is the most frequent error.
- **Two chunks from a thirty-page guide** — the text extracted badly. Check the
  extracted word count against the page count.
- **Forty chunks from a two-page factsheet** — heading detection is treating
  ordinary lines as headings. Inspect `looksLikeHeading` against that document's
  formatting.
- **Zero sections removed from a document that clearly contains dosing** — its
  headings do not match `excluded_headings`. Add the wording it actually uses.

## 6. Definition of done

Phase 1 is complete when all of the following are true.

- [ ] Both migrations have run; `documents` and `chunks` exist with the columns specified.
- [ ] `ChunkerTest` and `RestrictedSectionStripperTest` both pass.
- [ ] At least one real PDF has been ingested end to end.
- [ ] `knowledge/corpus-register.md` is generated and lists that document with full provenance.
- [ ] `storage/app/corpus/pdfs/` is gitignored; no PDFs are committed.
- [ ] No file outside the permitted list in section 1 has been modified.
- [ ] `php artisan agrivisit:ingest --fresh` can be run twice with identical results.

## 7. Out of scope — do not build these

Embedding generation, vector storage, cosine similarity, a `Retriever` interface,
prompt v2.0, citation rendering in the UI, and any change to the checklist
drafting flow. They belong to Phase 2 and Phase 3. If you find yourself editing
`ChecklistDrafter`, stop — you have left this phase.

## 8. Report back

When finished, state: how many files were created, which tests passed, whether
ingestion succeeded on a real PDF, and anything in this brief that turned out to
be wrong or ambiguous. If a design rule in section 2 could not be satisfied, say
which and why rather than working around it silently.
