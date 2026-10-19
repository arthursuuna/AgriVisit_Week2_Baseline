<?php

namespace App\Services\Memory;

use App\Models\FarmMemory;
use App\Services\Tools\Catalogue\RecallFarmHistoryTool;
use Illuminate\Support\Facades\Schema;

/**
 * The Memory Design and Data Handling Note (Week 6 Phase 3).
 *
 * The prose is resources/docs/memory-design.md. The parts that describe the
 * running system are generated into it: the farm_memories columns (read from
 * the table), the details keys and the arguments kept per tool (from the
 * recorder), the retention period (from config) and the tool's schemas (from
 * the tool). Every column must have a reason here to be listed: a field that
 * cannot justify itself is removed, not documented.
 */
class MemoryDesignNote
{
    /** Why each farm_memories column earns its place. */
    public const COLUMNS = [
        'id'          => 'Row key, and the sequence `memory_ref` is derived from.',
        'memory_ref'  => 'The handle the agent cites and the officer recognises (`M-0001`). Without it a citation could not be checked.',
        'farm_id'     => 'The scope. A farm\'s history is the farm\'s, so every read is by farm.',
        'officer_id'  => 'Whose decision it was. History is shared between officers, so the reader must be able to see who decided.',
        'run_id'      => 'Provenance: from an entry back to the run, and its trace, that produced the proposal.',
        'kind'        => '`approved`, `declined` or `lapsed`: the decision itself, as one of three observed outcomes.',
        'summary'     => 'What the officer reads: the gate\'s own one-line summary of the call plus the outcome. System text, never model text.',
        'details'     => 'The facts behind the summary, keyed as listed below.',
        'occurred_at' => 'When the officer decided (or the window closed). Retention is measured from it.',
        'created_at'  => 'When the system wrote the entry. Differs from `occurred_at` for a lapse noticed on a later page load.',
    ];

    /** Why each details key earns its place. */
    public const DETAILS = [
        'tool'           => 'Which action was proposed; also selects which arguments are kept and where `proposed` comes from.',
        'arguments'      => 'The schema-constrained arguments only (below). The `proposed` date is read from here.',
        'approval_ref'   => 'The link to the full decision in `tool_approvals`, the approval audit, which keeps the complete call.',
        'officer_reason' => 'Declined only: the officer\'s own words, verbatim. The one piece of free text memory holds, because it is the officer\'s, not the model\'s.',
        'result_code'    => 'Approved only: whether the approved call actually succeeded. An approval that failed wrote nothing.',
        'result_ref'     => 'Approved only: what was created (`V-0005`), so "approved" can be checked against the record.',
    ];

    public function __construct(private readonly RecallFarmHistoryTool $tool)
    {
    }

    public function toMarkdown(): string
    {
        $template = file_get_contents(resource_path('docs/memory-design.md'));

        return strtr($template, [
            '{{columns}}'          => $this->columns(),
            '{{details}}'          => $this->table(['Key', 'Why it is kept'], array_map(
                fn ($k, $why) => ["`{$k}`", $why], array_keys(self::DETAILS), self::DETAILS)),
            '{{kept_arguments}}'   => $this->table(['Tool', 'Arguments kept', 'Left out (free text the model composed)'], array_map(
                fn ($tool, $kept) => ["`{$tool}`", implode(', ', array_map(fn ($a) => "`{$a}`", $kept)), $this->leftOut($tool, $kept)],
                array_keys(FarmMemoryRecorder::KEPT_ARGUMENTS), FarmMemoryRecorder::KEPT_ARGUMENTS)),
            '{{retention_months}}' => (string) (int) config('agrivisit.memory.retention_months'),
            '{{tool_description}}' => $this->tool->description(),
            '{{tool_input}}'       => json_encode($this->tool->inputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '{{tool_output}}'      => json_encode($this->tool->outputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return list<string> columns of farm_memories with no reason in COLUMNS */
    public function unjustified(): array
    {
        return array_values(array_diff(Schema::getColumnListing('farm_memories'), array_keys(self::COLUMNS)));
    }

    private function columns(): string
    {
        return $this->table(['Column', 'Why it earns its place'], array_map(
            fn ($c) => ["`{$c}`", self::COLUMNS[$c] ?? '**(no justification: remove this column)**'],
            Schema::getColumnListing('farm_memories')));
    }

    private function leftOut(string $tool, array $kept): string
    {
        $all = array_keys(app(\App\Services\Tools\ToolRegistry::class)->get($tool)?->inputSchema()['properties'] ?? []);

        return implode(', ', array_map(fn ($a) => "`{$a}`", array_diff($all, $kept))) ?: '—';
    }

    private function table(array $head, array $rows): string
    {
        $lines = ['| ' . implode(' | ', $head) . ' |', '|' . str_repeat('---|', count($head))];

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines);
    }
}
