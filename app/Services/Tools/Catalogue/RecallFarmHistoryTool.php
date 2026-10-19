<?php

namespace App\Services\Tools\Catalogue;

use App\Models\FarmMemory;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;

/**
 * Read tool: what the officer decided about a farm on earlier runs (Week 6).
 *
 * A tool rather than context injected into the prompt, so every recall is an
 * action the agent chose: traced, counted against the iteration cap and
 * validated by the gate. Memory that shaped a run invisibly is exactly what
 * this phase exists to prevent.
 *
 * No history is a successful call with count 0, like search_guidance finding
 * nothing above its threshold: a farm nobody has decided anything about is the
 * normal case. An unknown farm is RESOURCE_NOT_FOUND, as in farm_profile_lookup,
 * so a mistyped ID is not mistaken for a farm without history.
 *
 * officer_reason is returned exactly as the officer wrote it. It is a record
 * of what they said, not an instruction (agent prompt v1.1).
 *
 * Phase 3: entries past the retention window are not returned; occurred_at
 * (when it was decided) and proposed (the date it was about) are separate
 * fields, because the Phase 2 live run read one as the other; officer_id says
 * whose decision it was, since a farm's history is shared by every officer.
 */
class RecallFarmHistoryTool implements ToolContract
{
    public const DEFAULT_LIMIT = 5;

    public function __construct(private readonly FarmProfileRepository $farms)
    {
    }

    public function name(): string
    {
        return 'recall_farm_history';
    }

    public function description(): string
    {
        return 'Recall what officers decided about a farm before (approved, declined or lapsed proposals, newest first, from the last '
            . (int) config('agrivisit.memory.retention_months') . ' months), each with a memory_ref to cite, or none. occurred_at is when it was decided; proposed is the date the proposal was for.';
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
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'How many past decisions to return at most, newest first. Default 5.',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'default'     => self::DEFAULT_LIMIT,
                ],
            ],
            'required'             => ['farm_id'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id' => ['type' => 'string', 'pattern' => FarmProfileLookupTool::FARM_ID_PATTERN],
                'count'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                'entries' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'memory_ref'     => ['type' => 'string', 'pattern' => '^M-\d{4,}$'],
                            'kind'           => ['type' => 'string', 'enum' => [FarmMemory::APPROVED, FarmMemory::DECLINED, FarmMemory::LAPSED]],
                            'occurred_at'    => ['type' => 'string', 'format' => 'date'],
                            'proposed'       => ['type' => ['string', 'null'], 'format' => 'date'],
                            'officer_id'     => ['type' => 'string', 'minLength' => 1],
                            'summary'        => ['type' => 'string', 'minLength' => 1],
                            'officer_reason' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['memory_ref', 'kind', 'occurred_at', 'proposed', 'officer_id', 'summary', 'officer_reason'],
                    ],
                ],
            ],
            'required' => ['farm_id', 'count', 'entries'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function approvalSummary(array $args): string
    {
        return "Recall past decisions about farm {$args['farm_id']}.";
    }

    public function execute(array $args): ToolResult
    {
        if ($this->farms->find($args['farm_id']) === null) {
            return ToolResult::failure(ToolResult::RESOURCE_NOT_FOUND,
                "No farm with ID {$args['farm_id']} exists.");
        }

        // Retention (Phase 3): an entry past the window is never returned.
        $entries = FarmMemory::recallable()->where('farm_id', $args['farm_id'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($args['limit'] ?? self::DEFAULT_LIMIT)
            ->get()
            ->map(fn (FarmMemory $m) => [
                'memory_ref'     => $m->memory_ref,
                'kind'           => $m->kind,
                'occurred_at'    => $m->occurred_at->toDateString(),
                'proposed'       => $m->proposed(),
                'summary'        => $m->summary,
                'officer_reason' => $m->details['officer_reason'] ?? null,
                'officer_id'     => $m->officer_id,
            ])->all();

        return ToolResult::success([
            'farm_id' => $args['farm_id'],
            'count'   => count($entries),
            'entries' => $entries,
        ]);
    }
}
