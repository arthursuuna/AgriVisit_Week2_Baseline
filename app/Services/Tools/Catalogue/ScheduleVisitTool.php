<?php

namespace App\Services\Tools\Catalogue;

use App\Models\Visit;
use App\Services\Tools\PreflightsArguments;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write tool: books a visit. Never runs without officer approval.
 */
class ScheduleVisitTool implements ToolContract, PreflightsArguments
{
    public function __construct(private readonly FarmProfileRepository $farms)
    {
    }

    public function name(): string
    {
        return 'schedule_visit';
    }

    public function description(): string
    {
        return 'Schedule a field visit to a farm on a given date; the officer must approve before it is booked.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => [
                    'type'        => 'string',
                    'description' => 'Farm ID in the form UG-XXX-000, e.g. UG-KYA-012.',
                    'pattern'     => FarmProfileLookupTool::FARM_ID_PATTERN,
                ],
                'visit_date' => [
                    'type'        => 'string',
                    'description' => 'Visit date as YYYY-MM-DD. Today or later.',
                    'format'      => 'date',
                ],
                'purpose' => [
                    'type'        => 'string',
                    'description' => 'Why the visit is needed, in one short sentence.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ],
            ],
            'required' => ['farm_id', 'visit_date', 'purpose'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'visit_id'   => ['type' => 'string', 'pattern' => '^V-\d{4,}$'],
                'farm_id'    => ['type' => 'string', 'pattern' => FarmProfileLookupTool::FARM_ID_PATTERN],
                'visit_date' => ['type' => 'string', 'format' => 'date'],
                'status'     => ['type' => 'string', 'enum' => ['scheduled']],
            ],
            'required' => ['visit_id', 'farm_id', 'visit_date', 'status'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function approvalSummary(array $args): string
    {
        return "Schedule a visit to {$args['farm_id']} on {$args['visit_date']}.";
    }

    public function preflight(array $args): ?ToolResult
    {
        if ($args['visit_date'] < now()->toDateString()) {
            return ToolResult::failure(ToolResult::INVALID_ARGUMENTS,
                "Parameter 'visit_date': {$args['visit_date']} is in the past.");
        }

        if ($this->farms->find($args['farm_id']) === null) {
            return ToolResult::failure(ToolResult::RESOURCE_NOT_FOUND,
                "No farm with ID {$args['farm_id']} exists.");
        }

        return null;
    }

    public function execute(array $args): ToolResult
    {
        // Checked again: an approved call may be replayed after the date has passed.
        if (($refusal = $this->preflight($args)) !== null) {
            return $refusal;
        }

        $visit = DB::transaction(function () use ($args) {
            $visit = Visit::create([
                'visit_ref'  => 'pending-' . Str::uuid(),
                'farm_id'    => $args['farm_id'],
                'visit_date' => $args['visit_date'],
                'purpose'    => $args['purpose'],
                'status'     => 'scheduled',
            ]);

            // Derived from the row id, never from a counter held in memory.
            $visit->update(['visit_ref' => sprintf('V-%04d', $visit->id)]);

            return $visit;
        });

        return ToolResult::success([
            'visit_id'   => $visit->visit_ref,
            'farm_id'    => $visit->farm_id,
            'visit_date' => $visit->visit_date->toDateString(),
            'status'     => $visit->status,
        ]);
    }
}
