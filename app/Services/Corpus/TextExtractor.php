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
        $words = $this->wordCount($text);

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

    /** Whitespace-delimited count, so numeric tokens such as "2.5" are included. */
    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }
}
