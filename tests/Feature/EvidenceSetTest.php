<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Retrieval\EvidenceSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceSetTest extends TestCase
{
    use RefreshDatabase;

    private function results(): array
    {
        $document = Document::create([
            'doc_ref'      => 'DOC-001',
            'title'        => 'Beans Training Manual',
            'publisher'    => 'MAAIF',
            'source_url'   => 'https://example.org/beans.pdf',
            'retrieved_on' => '2026-09-29',
            'file_name'    => 'beans.pdf',
        ]);

        $chunk = fn (string $ref, ?string $section, string $text, int $position) => Chunk::create([
            'document_id' => $document->id,
            'chunk_ref'   => $ref,
            'section'     => $section,
            'position'    => $position,
            'text'        => $text,
            'word_count'  => str_word_count($text),
        ]);

        return [
            ['chunk' => $chunk('DOC-001-C021', '2.6: Planting', 'Planting should be done on the onset of rains.', 0), 'score' => 0.81],
            ['chunk' => $chunk('DOC-001-C107', null, 'Farm records facilitate quick reference.', 1), 'score' => 0.72],
        ];
    }

    public function test_labels_are_numbered_per_request_not_by_chunk_ref(): void
    {
        $set = new EvidenceSet($this->results());

        $this->assertSame(['C-1', 'C-2'], $set->labelList());
        $this->assertSame('DOC-001-C021', $set->chunk('C-1')->chunk_ref);
        $this->assertSame('DOC-001-C107', $set->labels()['C-2']->chunk_ref);
        $this->assertSame(2, $set->count());
    }

    public function test_an_unknown_label_maps_to_nothing(): void
    {
        $set = new EvidenceSet($this->results());

        $this->assertNull($set->chunk('C-3'));
        $this->assertNull($set->citation('DOC-001-C021'));
    }

    public function test_it_renders_labelled_passages_with_title_and_section(): void
    {
        $rendered = (new EvidenceSet($this->results()))->render();

        $this->assertSame(
            "[C-1] Beans Training Manual — 2.6: Planting\nPlanting should be done on the onset of rains.\n\n"
            . "[C-2] Beans Training Manual\nFarm records facilitate quick reference.",
            $rendered
        );
    }

    public function test_a_citation_carries_the_real_source(): void
    {
        $citation = (new EvidenceSet($this->results()))->citation('C-1');

        $this->assertSame('DOC-001-C021', $citation['chunk_ref']);
        $this->assertSame('Beans Training Manual', $citation['document']);
        $this->assertSame('2.6: Planting', $citation['section']);
        $this->assertStringContainsString('onset of rains', $citation['text']);
    }

    public function test_an_empty_set_is_empty(): void
    {
        $set = new EvidenceSet([]);

        $this->assertTrue($set->isEmpty());
        $this->assertSame('', $set->render());
    }
}
