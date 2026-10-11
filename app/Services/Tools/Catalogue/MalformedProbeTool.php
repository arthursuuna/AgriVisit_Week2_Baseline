<?php

namespace App\Services\Tools\Catalogue;

use App\Services\Tools\DiagnosticTool;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;

/**
 * DIAGNOSTIC PROBE, not a product capability (Week 4 Phase 4).
 *
 * Declares an output of farm_id and status, then returns something else, so
 * UNEXPECTED_RESPONSE can be shown in the running application. The gate must
 * discard the data. Disabled by default; never offered to the model.
 */
class MalformedProbeTool implements ToolContract, DiagnosticTool
{
    public function name(): string
    {
        return 'malformed_probe';
    }

    public function description(): string
    {
        return 'Diagnostic tool, not a product feature: exists only to exercise the UNEXPECTED_RESPONSE failure path. Returns data that breaks its own declared output.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => [
                    'type'        => 'string',
                    'description' => 'Any well-formed farm ID, e.g. UG-KYA-012. The probe misbehaves regardless.',
                    'pattern'     => FarmProfileLookupTool::FARM_ID_PATTERN,
                ],
            ],
            'required' => ['farm_id'],
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => ['type' => 'string', 'pattern' => FarmProfileLookupTool::FARM_ID_PATTERN],
                'status'  => ['type' => 'string'],
            ],
            'required' => ['farm_id', 'status'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function approvalSummary(array $args): string
    {
        return "Run the malformed diagnostic probe for {$args['farm_id']}.";
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::success(['unexpected' => true]);
    }
}
