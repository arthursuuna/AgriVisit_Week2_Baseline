<?php

namespace App\Services\Tools\Catalogue;

use App\Models\Followup;
use App\Models\Visit;
use App\Services\Tools\PreflightsArguments;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write tool: records what happened after a visit. Never runs without officer
 * approval.
 */
class LogFollowupTool implements ToolContract, PreflightsArguments
{
    public function name(): string
    {
        return 'log_followup';
    }

    public function description(): string
    {
        return 'Record a follow-up note and outcome against an existing visit; the officer must approve before it is saved.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => [
                    'type'        => 'string',
                    'description' => 'Farm ID in the form UG-XXX-000. Must be the farm the visit belongs to.',
                    'pattern'     => FarmProfileLookupTool::FARM_ID_PATTERN,
                ],
                'visit_id' => [
                    'type'        => 'string',
                    'description' => 'Visit ID returned by schedule_visit, e.g. V-0001.',
                    'pattern'     => '^V-\d{4,}$',
                ],
                'notes' => [
                    'type'        => 'string',
                    'description' => 'What was observed or agreed.',
                    'minLength'   => 1,
                    'maxLength'   => 2000,
                ],
                'outcome' => [
                    'type'        => 'string',
                    'description' => 'Status of the issue after the follow-up.',
                    'enum'        => ['resolved', 'ongoing', 'escalated'],
                ],
            ],
            'required' => ['farm_id', 'visit_id', 'notes', 'outcome'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'entry_id'    => ['type' => 'string', 'pattern' => '^F-\d{4,}$'],
                'farm_id'     => ['type' => 'string', 'pattern' => FarmProfileLookupTool::FARM_ID_PATTERN],
                'recorded_at' => ['type' => 'string'],
            ],
            'required' => ['entry_id', 'farm_id', 'recorded_at'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function approvalSummary(array $args): string
    {
        // The officer reads this in the approval panel: "an ongoing", "a resolved".
        $article = preg_match('/^[aeiou]/i', $args['outcome']) === 1 ? 'an' : 'a';

        return "Record {$article} {$args['outcome']} follow-up note against visit {$args['visit_id']}.";
    }

    public function preflight(array $args): ?ToolResult
    {
        $visit = Visit::where('visit_ref', $args['visit_id'])->first();

        if ($visit === null) {
            return ToolResult::failure(ToolResult::RESOURCE_NOT_FOUND,
                "No visit with ID {$args['visit_id']} exists.");
        }

        if ($visit->farm_id !== $args['farm_id']) {
            return ToolResult::failure(ToolResult::INVALID_ARGUMENTS,
                "Visit {$args['visit_id']} belongs to farm {$visit->farm_id}, not {$args['farm_id']}.");
        }

        return null;
    }

    public function execute(array $args): ToolResult
    {
        if (($refusal = $this->preflight($args)) !== null) {
            return $refusal;
        }

        $entry = DB::transaction(function () use ($args) {
            $entry = Followup::create([
                'entry_ref'   => 'pending-' . Str::uuid(),
                'visit_id'    => Visit::where('visit_ref', $args['visit_id'])->value('id'),
                'farm_id'     => $args['farm_id'],
                'notes'       => $args['notes'],
                'outcome'     => $args['outcome'],
                'recorded_at' => now(),
            ]);

            // Derived from the row id, never from a counter held in memory.
            $entry->update(['entry_ref' => sprintf('F-%04d', $entry->id)]);

            return $entry;
        });

        return ToolResult::success([
            'entry_id'    => $entry->entry_ref,
            'farm_id'     => $entry->farm_id,
            'recorded_at' => $entry->recorded_at->toIso8601String(),
        ]);
    }
}
