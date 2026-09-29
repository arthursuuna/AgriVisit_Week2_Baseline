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
    /**
     * Volume-based application rates: "200 ml/l", "1.5lts/acre", "150ml/20".
     *
     * Volume only. Weight per area ("30 kg per acre") is almost always a seed
     * rate or a yield in agronomy text, and removing it strips legitimate
     * content. A bare-number denominator covers dilution into a sprayer of
     * stated size ("150ml/20" for a 20-litre knapsack).
     */
    private const RATE_PATTERN =
        '/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|l|lt|lts|litres?|liters?)\s*'
        . '(?:\/|per\s+)\s*(?:l|litre|liter|ha|hectare|acre|knapsack|tank|plant|tree|\d+(?:[.,]\d+)?)\b/iu';

    /**
     * A genuine section heading: a multi-level number followed by a capitalised
     * title ("4.3.3 Mixing Agro-Chemicals", "10.8: Mitigation"), or MODULE.
     * The capital rules out calculation lines such as "0.15 x 1000ml".
     */
    private const SECTION_PATTERN = '/^(\d+(?:\.\d+)+[.:]?\s*\p{Lu}|MODULE\b)/u';

    /** Words that put a chunk in a chemical-application context. */
    private const CHEMICAL_INDICATOR =
        '/\b(?:pesticide|insecticide|herbicide|fungicide|agro-chemical|agrochemical|'
        . 'roundup|round up|knapsack|sprayer|spray pump|dilution)/iu';

    /** A measured volume: "1.5L", "150mls", "0.15lts", "15 litres". */
    private const VOLUME_QUANTITY = '/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|l|lt|lts|litre|litres|liter|liters)\b/iu';

    /**
     * A quantity dissolved in a volume: "70ml in 20 litres", "1.5mls per litre",
     * "500gms/litre", "50gms of copper oxychloride in 20 litres". An amount per
     * litre is an application rate whatever the substance, so this needs no
     * vocabulary of product names; the optional "of ..." only spans a short name.
     */
    private const MIX_RATIO_PATTERN =
        '/\b\d+(?:[.,]\d+)?\s*(?:ml|mls|g|gm|gms|gram|grams|kg|l|lt|lts|litre|litres|liter|liters)\s*'
        . '(?:\/\s*|per\s+|(?:of\s+[^\d.;:]{1,40}?\s+)?in\s+(?:\d+(?:[.,]\d+)?\s*)?)'
        . '(?:litre|litres|liter|liters|l|lt|lts)\b/iu';

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

            // While skipping, only a genuine section heading ends the skip.
            // Numbered steps ("1. Measur") pass looksLikeHeading() but belong
            // to the excluded section.
            $endsSection = ! $skipping || preg_match(self::SECTION_PATTERN, $trimmed) === 1;

            if ($endsSection && $this->looksLikeHeading($trimmed)) {
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
     * The chunk-level backstop. Heading exclusion depends on the publisher using
     * a heading, and a table or a question-and-answer block has none. A chunk
     * that pairs a chemical indicator with a measured volume is treated as
     * dosing content wherever it sits.
     *
     * Either signal alone is allowed: safe-handling advice without numbers, or a
     * water volume with no chemical context, stays in the corpus.
     *
     * A mix ratio ("50gms in 20 litres") is restricted on its own. The two-signal
     * rule stays for calculations that spread their amounts across sentences,
     * such as the Roundup worked example.
     */
    public function chunkIsRestricted(string $text): bool
    {
        if (preg_match(self::MIX_RATIO_PATTERN, $text) === 1) {
            return true;
        }

        return preg_match(self::CHEMICAL_INDICATOR, $text) === 1
            && preg_match(self::VOLUME_QUANTITY, $text) === 1;
    }

    /**
     * Removes each sentence or bullet item that carries a mix ratio, leaving the
     * guidance around it in place. Runs before chunking so a single dose line no
     * longer costs the whole passage it sits in.
     *
     * Heading lines pass through untouched, and the whitespace between sentences
     * is kept, so the chunker still sees the same lines and section boundaries.
     *
     * @return array{text: string, sentences_removed: int}
     */
    public function stripMixRatioSentences(string $text): array
    {
        $removed = 0;
        $out     = [];
        $block   = [];

        $flush = function () use (&$block, &$out, &$removed) {
            if ($block !== []) {
                $out[] = $this->dropMixRatioSentences(implode("\n", $block), $removed);
                $block = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            if ($this->looksLikeHeading(trim($line))) {
                $flush();

                if (preg_match(self::MIX_RATIO_PATTERN, $line) === 1) {
                    $removed++;
                } else {
                    $out[] = $line;
                }

                continue;
            }

            $block[] = $line;
        }

        $flush();

        $text = preg_replace(['/[ \t]{2,}/u', '/[ \t]+\n/u', '/\n{3,}/u'], [' ', "\n", "\n\n"], implode("\n", $out));

        return ['text' => trim($text), 'sentences_removed' => $removed];
    }

    /**
     * Splits after sentence-ending punctuation and before each run of bullet
     * markers (➢ • ▪ ●), keeping the separators, and drops the pieces that
     * match MIX_RATIO_PATTERN.
     */
    private function dropMixRatioSentences(string $text, int &$removed): string
    {
        $parts = preg_split(
            '/((?<=[.!?])\s+|\s*(?<![➢•▪●])(?=[➢•▪●]))/u',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $kept = '';

        foreach ($parts as $i => $part) {
            // Odd indexes are the captured separators.
            if ($i % 2 === 0 && preg_match(self::MIX_RATIO_PATTERN, $part) === 1) {
                $removed++;

                continue;
            }

            $kept .= $part;
        }

        return $kept;
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
