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
                $count = $this->wordCount($piece);

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

    /** Whitespace-delimited count, so numeric tokens such as "2.5" are included. */
    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
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
