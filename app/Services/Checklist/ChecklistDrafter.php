<?php

namespace App\Services\Checklist;

use App\Services\Embedding\EmbeddingException;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Prompts\PromptRepository;
use App\Services\Retrieval\EvidenceSet;
use App\Services\Retrieval\FarmQueryBuilder;
use App\Services\Retrieval\Retriever;
use Illuminate\Support\Str;

/**
 * Drafts field-visit checklist items from a farm profile.
 *
 * Still deliberately narrow: no tools, no memory, no agent loop. From prompt
 * v2.0 every item must cite a passage retrieved from the corpus. The order of
 * operations is the order the AI Boundary Matrix requires:
 *
 *   1. deterministic restricted-topic screen on the request
 *   2. retrieval of evidence for the farm (v2.0), stopping if nothing is relevant
 *   3. model call for drafting only
 *   4. deterministic restricted-topic screen on the output
 *   5. strict output validation, including every citation against the evidence
 *   6. trace written for evidence
 *
 * Versions without retrieval (v1.x) skip step 2 and keep "ungrounded" items.
 * The result is always a draft; approval for field use is the officer's.
 */
class ChecklistDrafter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRepository $prompts,
        private readonly RestrictedTopicGuard $guard,
        private readonly ChecklistParser $parser,
        private readonly TraceWriter $traces,
        private readonly FarmQueryBuilder $queryBuilder,
        private readonly Retriever $retriever,
        private readonly string $promptVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $farm  Synthetic farm profile.
     * @param string               $notes Free-text officer notes (untrusted input).
     */
    public function draft(array $farm, string $notes = ''): DraftResult
    {
        $traceId = (string) Str::uuid();
        $farmId  = $farm['farm_id'] ?? null;

        $category = $this->guard->screenRequest($notes);

        if ($category !== null) {
            $message = $this->guard->refusalFor($category);

            $this->traces->write($traceId, [
                'outcome'          => DraftResult::STATUS_REFUSED,
                'refusal_category' => $category,
                'stage'            => 'pre-generation',
                'farm_id'          => $farmId,
                'notes'            => $notes,
                'prompt_version'   => $this->promptVersion,
            ]);

            return DraftResult::refused($category, $message, $this->promptVersion, $traceId);
        }

        $settings = $this->versionSettings();
        $evidence = null;
        $context  = [];

        if ($settings['grounded']) {
            try {
                [$evidence, $context] = $this->retrieveEvidence($farm, $notes);
            } catch (EmbeddingException $e) {
                $this->traces->write($traceId, [
                    'outcome'        => DraftResult::STATUS_FAILED,
                    'stage'          => 'retrieval',
                    'error'          => $e->getMessage(),
                    'farm_id'        => $farmId,
                    'prompt_version' => $this->promptVersion,
                ]);

                return DraftResult::failed(
                    'The evidence search is unavailable. No checklist was produced.',
                    $this->promptVersion,
                    $traceId
                );
            }

            if ($evidence->isEmpty()) {
                $this->traces->write($traceId, [
                    'outcome'        => DraftResult::STATUS_NO_EVIDENCE,
                    'stage'          => 'retrieval',
                    'farm_id'        => $farmId,
                    'notes'          => $notes,
                    'prompt_version' => $this->promptVersion,
                ] + $context);

                return DraftResult::noEvidence(
                    'No supporting guidance was found in the extension manuals for this farm, '
                    . 'so no checklist was drafted. Prepare this visit from the farm record directly.',
                    $this->promptVersion,
                    $traceId
                );
            }
        }

        $system = $this->prompts->render('checklist', $this->promptVersion, 'system', [
            'MIN_ITEMS' => (string) $settings['min_items'],
            'MAX_ITEMS' => (string) $settings['max_items'],
        ]);

        $user = $this->prompts->render('checklist', $this->promptVersion, 'user', [
            'EVIDENCE'      => $evidence?->render() ?? '',
            'FARM_PROFILE'  => json_encode($farm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'OFFICER_NOTES' => $notes !== '' ? $notes : '(none supplied)',
        ]);

        try {
            $response = $this->llm->complete($system, $user);
        } catch (LlmException $e) {
            $this->traces->write($traceId, [
                'outcome'        => DraftResult::STATUS_FAILED,
                'stage'          => 'model-call',
                'error'          => $e->getMessage(),
                'farm_id'        => $farmId,
                'prompt_version' => $this->promptVersion,
            ] + $context);

            return DraftResult::failed(
                'The drafting service is unavailable. No checklist was produced.',
                $this->promptVersion,
                $traceId
            );
        }

        $outputCategory = $this->guard->screenOutput($response->text);

        if ($outputCategory !== null) {
            $message = $this->guard->refusalFor($outputCategory);

            $this->traces->write($traceId, [
                'outcome'          => DraftResult::STATUS_REFUSED,
                'refusal_category' => $outputCategory,
                'stage'            => 'post-generation',
                'farm_id'          => $farmId,
                'prompt_version'   => $this->promptVersion,
                'model'            => $response->toArray(),
                'raw_output'       => $response->text,
            ] + $context);

            return DraftResult::refused($outputCategory, $message, $this->promptVersion, $traceId, $response);
        }

        try {
            $parsed = $this->parser
                ->withRange($settings['min_items'], $settings['max_items'])
                ->parse($response->text, $evidence?->labelList());
        } catch (ChecklistFormatException $e) {
            $this->traces->write($traceId, [
                'outcome'        => DraftResult::STATUS_FAILED,
                'stage'          => 'output-validation',
                'error'          => $e->getMessage(),
                'farm_id'        => $farmId,
                'prompt_version' => $this->promptVersion,
                'model'          => $response->toArray(),
                'raw_output'     => $response->text,
            ] + $context);

            return DraftResult::failed(
                'The draft did not meet the required format and was discarded: ' . $e->getMessage(),
                $this->promptVersion,
                $traceId
            );
        }

        if ($evidence !== null && $parsed['items'] === []) {
            $this->traces->write($traceId, [
                'outcome'        => DraftResult::STATUS_NO_EVIDENCE,
                'stage'          => 'model',
                'farm_id'        => $farmId,
                'notes'          => $notes,
                'prompt_version' => $this->promptVersion,
                'model'          => $response->toArray(),
                'raw_output'     => $response->text,
            ] + $context);

            return DraftResult::noEvidence(
                sprintf(
                    'Guidance was retrieved (%d passages), but none of it supported a checklist item '
                    . 'for this farm, so no checklist was drafted.',
                    $evidence->count()
                ),
                $this->promptVersion,
                $traceId,
                $evidence->count(),
                $response
            );
        }

        $items = $evidence === null ? $parsed['items'] : $this->attachCitations($parsed['items'], $evidence);

        $this->traces->write($traceId, [
            'outcome'        => DraftResult::STATUS_DRAFTED,
            'farm_id'        => $farmId,
            'notes'          => $notes,
            'prompt_version' => $this->promptVersion,
            'model'          => $response->toArray(),
            'item_count'     => count($items),
            'raw_output'     => $response->text,
        ] + $context + $this->citationTrace($items, $evidence));

        return DraftResult::drafted(
            $items,
            $parsed['summary'],
            $response,
            $this->promptVersion,
            $traceId,
            $evidence?->count() ?? 0
        );
    }

    /** @return array{min_items: int, max_items: int, grounded: bool} */
    private function versionSettings(): array
    {
        // Indexed directly: dot notation would split "v2.0" into "v2" and "0".
        $settings = config('agrivisit.checklist.versions', [])[$this->promptVersion] ?? [];

        return [
            'min_items' => (int) ($settings['min_items'] ?? config('agrivisit.checklist.min_items')),
            'max_items' => (int) ($settings['max_items'] ?? config('agrivisit.checklist.max_items')),
            'grounded'  => (bool) ($settings['grounded'] ?? false),
        ];
    }

    /**
     * Retrieves for each query separately and merges, keeping each chunk's best
     * score. Passages from the farm's own crops come first, then the top-up.
     *
     * @return array{0: EvidenceSet, 1: array<string, mixed>} The evidence and its trace fields.
     *
     * @throws EmbeddingException
     */
    private function retrieveEvidence(array $farm, string $notes): array
    {
        $startedAt = microtime(true);
        $max       = (int) config('agrivisit.retrieval.max_chunks');
        $crops     = $this->queryBuilder->crops($farm);
        $merged    = [];
        $queries   = [];

        foreach ($this->queryBuilder->build($farm, $notes) as $query) {
            $results = $this->retriever->retrieveForCrops($query, $crops, $max);

            $queries[] = [
                'query'     => $query,
                'hits'      => count($results),
                'top_score' => $results === [] ? null : round($results[0]['score'], 4),
            ];

            foreach ($results as $result) {
                $id = $result['chunk']->id;

                if (! isset($merged[$id]) || $result['score'] > $merged[$id]['score']) {
                    $merged[$id] = $result;
                }
            }
        }

        usort($merged, fn ($a, $b) => [$b['in_crop'], $b['score']] <=> [$a['in_crop'], $a['score']]);

        $evidence = new EvidenceSet(array_slice($merged, 0, $max));

        return [$evidence, [
            'retrieval' => [
                'crops'        => $crops,
                'threshold'    => (float) config('agrivisit.retrieval.threshold'),
                'queries'      => $queries,
                'retrieval_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
            'evidence' => $evidence->toTrace(),
        ]];
    }

    /**
     * Maps each item's label back to the real chunk, so the result carries the
     * citation the officer sees rather than the per-request label.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function attachCitations(array $items, EvidenceSet $evidence): array
    {
        return array_map(
            fn ($item) => $item + ['citation' => $evidence->citation($item['grounding'])],
            $items
        );
    }

    /** @return array<string, mixed> */
    private function citationTrace(array $items, ?EvidenceSet $evidence): array
    {
        if ($evidence === null) {
            return [];
        }

        $cited = array_values(array_unique(array_column($items, 'grounding')));

        return [
            'cited_labels'   => $cited,
            'uncited_labels' => array_values(array_diff($evidence->labelList(), $cited)),
            'citations'      => array_map(fn ($item) => [
                'label'     => $item['grounding'],
                'chunk_ref' => $item['citation']['chunk_ref'] ?? null,
                'item'      => $item['item'],
            ], $items),
        ];
    }
}
