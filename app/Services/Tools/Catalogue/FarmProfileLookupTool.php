<?php

namespace App\Services\Tools\Catalogue;

use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;

/**
 * Read tool: the farm record an officer would check before a visit.
 *
 * Returns only the fields the output schema declares. The farmer alias, soil
 * and water notes stay out: the model has no use for them when deciding what
 * to do next, and anything not declared would fail output validation.
 */
class FarmProfileLookupTool implements ToolContract
{
    public const FARM_ID_PATTERN = '^UG-[A-Z]{3}-\d{3}$';

    public function __construct(private readonly FarmProfileRepository $farms)
    {
    }

    public function name(): string
    {
        return 'farm_profile_lookup';
    }

    public function description(): string
    {
        return 'Look up a farm by its ID to get its district, crops, last visit date, outstanding issues and previous advice.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => [
                    'type'        => 'string',
                    'description' => 'Farm ID in the form UG-XXX-000, e.g. UG-KYA-012.',
                    'pattern'     => self::FARM_ID_PATTERN,
                ],
            ],
            'required' => ['farm_id'],
        ];
    }

    public function outputSchema(): array
    {
        $text = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type'       => 'object',
            'properties' => [
                'farm_id'  => ['type' => 'string', 'pattern' => self::FARM_ID_PATTERN],
                'district' => ['type' => 'string'],
                'crops'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'crop'       => ['type' => 'string'],
                            'variety'    => ['type' => ['string', 'null']],
                            'area_acres' => ['type' => ['number', 'null'], 'minimum' => 0],
                            'planted'    => ['type' => ['string', 'null'], 'format' => 'date'],
                        ],
                        'required' => ['crop'],
                    ],
                ],
                'last_visit'         => ['type' => ['string', 'null'], 'format' => 'date'],
                'outstanding_issues' => $text,
                'previous_advice'    => $text,
            ],
            'required' => ['farm_id', 'district', 'crops', 'last_visit', 'outstanding_issues', 'previous_advice'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function approvalSummary(array $args): string
    {
        return "Look up farm {$args['farm_id']}.";
    }

    public function execute(array $args): ToolResult
    {
        $farm = $this->farms->find($args['farm_id']);

        if ($farm === null) {
            return ToolResult::failure(ToolResult::RESOURCE_NOT_FOUND,
                "No farm with ID {$args['farm_id']} exists.");
        }

        return ToolResult::success([
            'farm_id'  => $farm['farm_id'],
            'district' => $farm['district'] ?? null,
            'crops'    => array_map(fn (array $crop) => array_intersect_key(
                $crop, array_flip(['crop', 'variety', 'area_acres', 'planted'])
            ), $farm['crops'] ?? []),
            'last_visit'         => $farm['last_visit'] ?? null,
            'outstanding_issues' => $farm['outstanding_issues'] ?? [],
            'previous_advice'    => $farm['previous_advice'] ?? [],
        ]);
    }
}
