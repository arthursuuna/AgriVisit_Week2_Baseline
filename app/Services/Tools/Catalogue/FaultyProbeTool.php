<?php

namespace App\Services\Tools\Catalogue;

use App\Services\Tools\DiagnosticTool;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use RuntimeException;

/**
 * DIAGNOSTIC PROBE, not a product capability (Week 4 Phase 4).
 *
 * Simulates an unreachable dependency so SERVICE_UNAVAILABLE can be shown in
 * the running application, through the gate's own try/catch rather than a
 * test stub. Disabled by default; never offered to the model.
 */
class FaultyProbeTool implements ToolContract, DiagnosticTool
{
    public function name(): string
    {
        return 'faulty_probe';
    }

    public function description(): string
    {
        return 'Diagnostic tool, not a product feature: exists only to exercise the SERVICE_UNAVAILABLE failure path. Always fails as if a dependency were unreachable.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => [
                    'type'        => 'string',
                    'description' => 'Any well-formed farm ID, e.g. UG-KYA-012. The probe fails regardless.',
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
                'farm_id' => ['type' => 'string'],
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
        return "Run the faulty diagnostic probe for {$args['farm_id']}.";
    }

    public function execute(array $args): ToolResult
    {
        throw new RuntimeException('Simulated outage: the farm records service did not respond (diagnostic probe).');
    }
}
