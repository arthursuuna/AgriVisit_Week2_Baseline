<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Corpus\TextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class IngestCorpusTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-ingest-test-' . uniqid();
        mkdir($this->dir);

        $this->writeSources([$this->source('DOC-001'), $this->source('DOC-002')]);

        config([
            'agrivisit.corpus.sources_file' => $this->dir . '/sources.json',
            'agrivisit.corpus.pdf_path'     => $this->dir,
        ]);

        // Stand in for PDF extraction so the test needs no files and no parser.
        $this->app->instance(TextExtractor::class, new class(new Parser()) extends TextExtractor {
            public function extract(string $path): array
            {
                $words = [];

                for ($i = 0; $i < 900; $i++) {
                    $words[] = 'word' . $i;
                }

                return ['text' => "Land Preparation\n" . implode(' ', $words), 'pages' => 3, 'words' => 901];
            }
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/sources.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function source(string $ref, array $overrides = []): array
    {
        return array_merge([
            'doc_ref'      => $ref,
            'file_name'    => strtolower($ref) . '.pdf',
            'title'        => "Test Guide {$ref}",
            'publisher'    => 'Test',
            'source_url'   => 'https://example.org/test.pdf',
            'licence'      => 'test',
            'retrieved_on' => '2026-09-29',
            'crops'        => ['maize'],
        ], $overrides);
    }

    private function writeSources(array $sources): void
    {
        file_put_contents($this->dir . '/sources.json', json_encode($sources));
    }

    private function refs(): array
    {
        return Chunk::orderBy('chunk_ref')->pluck('chunk_ref')->all();
    }

    public function test_ingesting_twice_without_fresh_is_idempotent(): void
    {
        $this->artisan('agrivisit:ingest')->assertSuccessful();
        $first = $this->refs();

        $this->artisan('agrivisit:ingest')->assertSuccessful();
        $second = $this->refs();

        $this->assertNotEmpty($first);
        $this->assertSame(count($first), Chunk::count());
        $this->assertSame($first, $second);
    }

    public function test_reingesting_one_document_does_not_collide_with_another(): void
    {
        $this->artisan('agrivisit:ingest')->assertSuccessful();
        $this->artisan('agrivisit:ingest')->assertSuccessful();
        $before = $this->refs();

        // The sequence that reproduced the unique-constraint failure.
        $this->artisan('agrivisit:ingest', ['--only' => 'DOC-001'])->assertSuccessful();
        $this->artisan('agrivisit:ingest', ['--only' => 'DOC-002'])->assertSuccessful();

        $this->assertSame($before, $this->refs());
    }

    public function test_chunk_refs_name_their_document_and_position(): void
    {
        $this->artisan('agrivisit:ingest')->assertSuccessful();

        $this->assertSame(
            ['DOC-001-C001', 'DOC-001-C002', 'DOC-001-C003'],
            Chunk::whereHas('document', fn ($q) => $q->where('doc_ref', 'DOC-001'))
                ->orderBy('position')->pluck('chunk_ref')->all()
        );
    }

    public function test_a_placeholder_date_is_reported_and_does_not_abort_the_run(): void
    {
        $this->writeSources([
            $this->source('DOC-001', ['retrieved_on' => 'YYYY-MM-DD']),
            $this->source('DOC-002'),
        ]);

        $this->artisan('agrivisit:ingest')
            ->expectsOutputToContain('Entries skipped (invalid metadata):')
            ->expectsOutputToContain('retrieved_on is still the placeholder')
            ->assertSuccessful();

        $this->assertSame(['DOC-002'], Document::pluck('doc_ref')->all());
    }

    public function test_a_duplicate_doc_ref_is_refused_and_the_first_document_survives(): void
    {
        $this->writeSources([
            $this->source('DOC-001', ['title' => 'First']),
            $this->source('DOC-001', ['title' => 'Second']),
        ]);

        $this->artisan('agrivisit:ingest')
            ->expectsOutputToContain('duplicate doc_ref')
            ->assertSuccessful();

        $this->assertSame(1, Document::count());
        $this->assertSame('First', Document::first()->title);
        $this->assertSame(
            ['DOC-001-C001', 'DOC-001-C002', 'DOC-001-C003'],
            Document::first()->chunks()->orderBy('position')->pluck('chunk_ref')->all()
        );
    }

    public function test_an_entry_missing_publisher_is_reported_as_invalid(): void
    {
        $entry = $this->source('DOC-001');
        unset($entry['publisher']);
        $this->writeSources([$entry, $this->source('DOC-002')]);

        $this->artisan('agrivisit:ingest')
            ->expectsOutputToContain('missing or empty: publisher')
            ->assertSuccessful();

        $this->assertSame(['DOC-002'], Document::pluck('doc_ref')->all());
    }

    public function test_validate_makes_no_database_changes(): void
    {
        $this->artisan('agrivisit:ingest')->assertSuccessful();
        $documents = Document::count();
        $refs      = $this->refs();

        $this->writeSources([
            $this->source('DOC-003'),
            $this->source('DOC-003'),
            $this->source('DOC-004', ['retrieved_on' => 'YYYY-MM-DD']),
        ]);

        $this->artisan('agrivisit:ingest', ['--validate' => true])
            ->expectsOutputToContain('Nothing was ingested.')
            ->assertFailed();

        $this->assertSame($documents, Document::count());
        $this->assertSame($refs, $this->refs());
    }
}
