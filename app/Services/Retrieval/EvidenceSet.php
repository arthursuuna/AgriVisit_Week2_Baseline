<?php

namespace App\Services\Retrieval;

use App\Models\Chunk;

/**
 * The passages supplied to the model for one request.
 *
 * Passages are numbered per request (C-1, C-2, ...) rather than by database
 * chunk ref. The model cites the local label and the application maps it back,
 * which keeps the prompt short and makes a fabricated label obvious: anything
 * outside C-1..C-n was invented.
 */
final class EvidenceSet
{
    /** @var array<string, array{chunk: Chunk, score: float}> */
    private array $entries = [];

    /** @param  list<array{chunk: Chunk, score: float}>  $results  Best first. */
    public function __construct(array $results)
    {
        foreach (array_values($results) as $i => $result) {
            $this->entries['C-' . ($i + 1)] = ['chunk' => $result['chunk'], 'score' => (float) $result['score']];
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return array<string, Chunk> Label => chunk. */
    public function labels(): array
    {
        return array_map(fn ($entry) => $entry['chunk'], $this->entries);
    }

    /** @return list<string> */
    public function labelList(): array
    {
        return array_keys($this->entries);
    }

    public function chunk(string $label): ?Chunk
    {
        return $this->entries[$label]['chunk'] ?? null;
    }

    public function score(string $label): ?float
    {
        return $this->entries[$label]['score'] ?? null;
    }

    /** The evidence block for the prompt. */
    public function render(): string
    {
        $blocks = [];

        foreach ($this->entries as $label => $entry) {
            $blocks[] = "[{$label}] " . $this->source($entry['chunk']) . "\n" . trim($entry['chunk']->text);
        }

        return implode("\n\n", $blocks);
    }

    /** The citation shown to the officer for one label. */
    public function citation(string $label): ?array
    {
        $chunk = $this->chunk($label);

        if ($chunk === null) {
            return null;
        }

        return [
            'label'     => $label,
            'chunk_ref' => $chunk->chunk_ref,
            'document'  => $chunk->document->title,
            'doc_ref'   => $chunk->document->doc_ref,
            'section'   => $chunk->section,
            'text'      => $chunk->text,
            'score'     => round($this->score($label), 4),
        ];
    }

    /** @return list<array<string, mixed>> Supplied evidence, for the trace. */
    public function toTrace(): array
    {
        $rows = [];

        foreach ($this->entries as $label => $entry) {
            $rows[] = [
                'label'     => $label,
                'chunk_ref' => $entry['chunk']->chunk_ref,
                'document'  => $entry['chunk']->document->doc_ref,
                'section'   => $entry['chunk']->section,
                'score'     => round($entry['score'], 4),
            ];
        }

        return $rows;
    }

    private function source(Chunk $chunk): string
    {
        $section = trim((string) $chunk->section);

        return $section === '' ? $chunk->document->title : "{$chunk->document->title} — {$section}";
    }
}
