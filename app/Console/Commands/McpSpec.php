<?php

namespace App\Console\Commands;

use App\Services\AgentTask\McpInterfaceSpec;
use Illuminate\Console\Command;

/**
 * Prints the MCP-style interface specification for search_guidance, or writes it.
 *
 *   php artisan agrivisit:mcp-spec
 *   php artisan agrivisit:mcp-spec --write   (evidence/week6-mcp-interface.md)
 *
 * Generated from the tool, config, the corpus tables and the registry, so it
 * cannot describe an interface the system does not have. Refuses to run if a
 * registered tool or a result code is not accounted for.
 */
class McpSpec extends Command
{
    protected $signature = 'agrivisit:mcp-spec
                            {--write : Write it to evidence/week6-mcp-interface.md instead of printing it}';

    protected $description = 'Print the MCP-style interface specification for the guidance corpus';

    public function handle(): int
    {
        $spec = McpInterfaceSpec::fromConfig();

        if (($problems = $spec->problems()) !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        if ($this->option('write')) {
            file_put_contents($path = base_path('evidence/week6-mcp-interface.md'), $spec->toMarkdown());
            $this->info("Written to {$path}");
        } else {
            $this->line($spec->toMarkdown());
        }

        return self::SUCCESS;
    }
}
