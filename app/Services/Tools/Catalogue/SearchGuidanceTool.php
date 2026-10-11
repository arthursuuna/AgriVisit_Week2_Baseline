<?php

namespace App\Services\Tools\Catalogue;

use App\Services\Checklist\RestrictedTopicGuard;
use App\Services\Retrieval\Retriever;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;

/**
 * Read tool: the agent's only way into the Week 3 guidance corpus.
 *
 * A tool rather than a direct Retriever call from the loop, so every search is
 * an action the agent chose: traced, counted against the iteration cap and
 * validated by the gate.
 *
 * The guard runs here, on the agent-composed query, every time. Week 4's guard
 * protected what the officer typed; in Week 5 the agent writes its own search
 * queries from its own reasoning, and no person sees them before they run. An
 * agent helping with "leaf damage on maize" may decide by itself to search for
 * a fungicide application rate, so screening only the officer's goal would let
 * that query through.
 *
 * Finding nothing above the threshold is a successful call with count 0. There
 * is no fallback to the best results below it: a threshold that bends under
 * pressure is not a threshold.
 */
class SearchGuidanceTool implements ToolContract
{
    public const DEFAULT_RESULTS = 3;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly RestrictedTopicGuard $guard,
        private readonly float $threshold,
    ) {
    }

    public function name(): string
    {
        return 'search_guidance';
    }

    public function description(): string
    {
        return 'Search the curated agronomy guidance corpus; returns passages above the relevance threshold, each with a chunk_ref to cite, or none.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'What to search the guidance corpus for, in plain words, e.g. "maize leaf damage causes".',
                    'minLength'   => 3,
                    'maxLength'   => 300,
                ],
                'max_results' => [
                    'type'        => 'integer',
                    'description' => 'How many passages to return at most. Default 3.',
                    'minimum'     => 1,
                    'maximum'     => 5,
                    'default'     => self::DEFAULT_RESULTS,
                ],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query'   => ['type' => 'string'],
                'count'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5],
                'results' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'chunk_ref'    => ['type' => 'string', 'minLength' => 1],
                            'source_title' => ['type' => 'string'],
                            'section'      => ['type' => 'string'],
                            'text'         => ['type' => 'string'],
                            'score'        => ['type' => 'number'],
                        ],
                        'required' => ['chunk_ref', 'source_title', 'section', 'text', 'score'],
                    ],
                ],
            ],
            'required' => ['query', 'count', 'results'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function approvalSummary(array $args): string
    {
        return "Search the guidance corpus for '{$args['query']}'.";
    }

    public function execute(array $args): ToolResult
    {
        $query = $args['query'];

        if (($category = $this->guard->screenRequest($query)) !== null) {
            // GUARD_REFUSED, not INVALID_ARGUMENTS: the query was well-formed,
            // the topic was refused. The loop ends the run on this code.
            return ToolResult::failure(ToolResult::GUARD_REFUSED,
                "Query refused by the restricted-topic guard ({$category}): " . $this->guard->refusalFor($category));
        }

        $limit = $args['max_results'] ?? self::DEFAULT_RESULTS;

        // retrieve() is best first and applies no threshold, so it is applied
        // here, with the same configured value Week 3 evaluated.
        $passages = array_values(array_filter(
            $this->retriever->retrieve($query, $limit),
            fn (array $r) => $r['score'] >= $this->threshold
        ));

        $results = array_map(fn (array $r) => [
            'chunk_ref'    => (string) $r['chunk']->chunk_ref,
            'source_title' => (string) $r['chunk']->document->title,
            'section'      => (string) ($r['chunk']->section ?? ''),
            'text'         => trim((string) $r['chunk']->text),
            'score'        => round((float) $r['score'], 4),
        ], array_slice($passages, 0, $limit));

        return ToolResult::success([
            'query'   => $query,
            'count'   => count($results),
            'results' => $results,
        ]);
    }
}
