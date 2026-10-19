<?php

namespace App\Console\Commands;

use App\Services\Memory\MemoryDesignNote;
use Illuminate\Console\Command;

/**
 * Prints the Memory Design and Data Handling Note, or writes it.
 *
 *   php artisan agrivisit:memory-note
 *   php artisan agrivisit:memory-note --write   (evidence/week6-memory-design.md)
 *
 * Schema, retention, kept arguments and the tool's schemas are generated, so
 * the note cannot describe a memory the system no longer has.
 */
class MemoryNote extends Command
{
    protected $signature = 'agrivisit:memory-note
                            {--write : Write it to evidence/week6-memory-design.md instead of printing it}';

    protected $description = 'Print the Memory Design and Data Handling Note, generated from the table, config and tool';

    public function handle(MemoryDesignNote $note): int
    {
        if (($missing = $note->unjustified()) !== []) {
            $this->error('farm_memories has columns with no justification: ' . implode(', ', $missing) . '. Justify or remove them.');

            return self::FAILURE;
        }

        if ($this->option('write')) {
            file_put_contents($path = base_path('evidence/week6-memory-design.md'), $note->toMarkdown());
            $this->info("Written to {$path}");
        } else {
            $this->line($note->toMarkdown());
        }

        return self::SUCCESS;
    }
}
