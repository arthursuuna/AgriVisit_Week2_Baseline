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

        $md .= "| Documents | Chunks | Words | Sections removed | Sentences removed | Chunks dropped |\n"
            . "|---|---|---|---|---|---|\n";
        $md .= sprintf(
            "| %d | %d | %s | %d | %d | %d |\n\n",
            $documents->count(),
            Chunk::count(),
            number_format($documents->sum('word_count')),
            $documents->sum('sections_removed'),
            $documents->sum('sentences_removed'),
            $documents->sum('chunks_dropped')
        );

        $md .= "## Documents\n\n";
        $md .= "| Ref | Title | Publisher | Crops | Licence | Retrieved | Pages | Chunks | Sentences removed | Chunks dropped |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ($documents as $doc) {
            $md .= sprintf(
                "| %s | [%s](%s) | %s | %s | %s | %s | %d | %d | %d | %d |\n",
                $doc->doc_ref,
                str_replace('|', '\\|', $doc->title),
                $doc->source_url,
                str_replace('|', '\\|', $doc->publisher),
                implode(', ', $doc->crops ?? []),
                $doc->licence ?: 'not stated',
                $doc->retrieved_on?->toDateString() ?? '',
                $doc->page_count,
                $doc->chunks_count,
                $doc->sentences_removed,
                $doc->chunks_dropped
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

        $withSentences = $documents->where('sentences_removed', '>', 0);

        $md .= "\n## Sentences removed at ingestion\n\n";
        $md .= "Sentences and bullet items stating a mix ratio (an amount per litre or in a "
            . "volume of water) are removed before chunking, so the guidance around them stays "
            . "in the corpus.\n\n";

        if ($withSentences->isEmpty()) {
            $md .= "No sentences were removed from the current corpus.\n";
        } else {
            $md .= "| Ref | Sentences removed |\n|---|---|\n";

            foreach ($withSentences as $doc) {
                $md .= sprintf("| %s | %d |\n", $doc->doc_ref, $doc->sentences_removed);
            }
        }

        $withDrops = $documents->where('chunks_dropped', '>', 0);

        $md .= "\n## Chunks dropped at ingestion\n\n";
        $md .= "Chunks containing both a chemical indicator and a measured volume are dropped "
            . "at ingestion, so dosing content cannot be retrieved even where it appears "
            . "outside a recognised section heading.\n\n";

        if ($withDrops->isEmpty()) {
            $md .= "No chunks were dropped from the current corpus.\n";
        } else {
            $md .= "| Ref | Chunks dropped |\n|---|---|\n";

            foreach ($withDrops as $doc) {
                $md .= sprintf("| %s | %d |\n", $doc->doc_ref, $doc->chunks_dropped);
            }
        }

        $notes =$documents->whereNotNull('notes')->where('notes', '!=', '');

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
