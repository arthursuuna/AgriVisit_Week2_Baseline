<?php

namespace App\Console\Commands;

use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolPolicy;
use App\Services\Tools\ToolRegistry;
use Illuminate\Console\Command;

/**
 * Prints the tool catalogue.
 *
 *   php artisan agrivisit:tools
 *   php artisan agrivisit:tools --json
 *
 * The JSON form is the published Tool Catalogue: it is generated from the same
 * schemas the invoker validates against, so the document cannot drift from
 * the code.
 */
class ListTools extends Command
{
    protected $signature = 'agrivisit:tools
                            {--json : Print the full input and output schemas as JSON}';

    protected $description = 'List the registered tools, their access level and whether they are enabled';

    public function handle(ToolRegistry $registry, ToolPolicy $policy): int
    {
        $tools = $registry->all();

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(fn (ToolContract $tool) => [
                    'name'              => $tool->name(),
                    'access'            => $tool->isWrite() ? 'write' : 'read',
                    'requires_approval' => $tool->isWrite(),
                    'enabled'           => $policy->allows($tool),
                    'description'       => $tool->description(),
                    'input_schema'      => $tool->inputSchema(),
                    'output_schema'     => $tool->outputSchema(),
                ], $tools),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Access', 'Enabled', 'Required parameters', 'Description'],
            array_map(fn (ToolContract $tool) => [
                $tool->name(),
                $tool->isWrite() ? 'write (approval)' : 'read',
                $policy->allows($tool) ? 'yes' : 'no',
                implode(', ', $tool->inputSchema()['required'] ?? []),
                $tool->description(),
            ], $tools)
        );

        return self::SUCCESS;
    }
}
