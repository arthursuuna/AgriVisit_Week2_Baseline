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

    public function test_word_count_includes_numeric_tokens(): void
    {
        // 40 plain words plus four measurement tokens: 44 in all.
        $text = "Land Preparation\n" . $this->words(40) . ' store 2.5 tonnes 400kg';

        $chunks = $this->chunker()->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertSame(44, $chunks[0]['word_count']);
    }

    public function test_numbers_count_towards_the_minimum(): void
    {
        // 38 words plus two numbers is exactly min_words (40), so the chunk is kept.
        $text = "Land Preparation\n" . $this->words(38) . ' 2.5 400';

        $chunks = $this->chunker()->chunk($text);

        $this->assertCount(1, $chunks);
        $this->assertSame(40, $chunks[0]['word_count']);
    }
}
