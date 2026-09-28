<?php

namespace App\Services\Checklist;

use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Prompts\PromptRepository;
use Illuminate\Support\Str;

/**
 * The Week 2 baseline capability: draft field-visit checklist items from a
 * farm profile.
 *
 * Deliberately narrow. No retrieval, no tools, no memory, no agent loop -- those
 * arrive in Weeks 3, 4 and 6. The order of operations here is the order the AI
 * Boundary Matrix requires:
 *
 *   1. deterministic restricted-topic screen on the request
 *   2. model call for drafting only
 *   3. strict output validation
 *   4. deterministic restricted-topic screen on the output
 *   5. trace written for evidence
 *
 * The result is always a draft. Nothing here marks a checklist ready for field
 * use; that is the officer's approval, added in a later week.
 */
class ChecklistDrafter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptRepository $prompts,
        private readonly RestrictedTopicGuard $guard,
        private readonly ChecklistParser $parser,
        private readonly TraceWriter $traces,
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

        $category = $this->guard->screenRequest($notes);

        if ($category !== null) {
            $message = $this->guard->refusalFor($category);

            $this->traces->write($traceId, [
                'outcome'          => DraftResult::STATUS_REFUSED,
                'refusal_category' => $category,
                'stage'            => 'pre-generation',
                'farm_id'          => $farm['farm_id'] ?? null,
                'notes'            => $notes,
                'prompt_version'   => $this->promptVersion,
            ]);

            return DraftResult::refused($category, $message, $this->promptVersion, $traceId);
        }

        $system = $this->prompts->render('checklist', $this->promptVersion, 'system', [
            'MIN_ITEMS' => (string) config('agrivisit.checklist.min_items'),
            'MAX_ITEMS' => (string) config('agrivisit.checklist.max_items'),
        ]);

        $user = $this->prompts->render('checklist', $this->promptVersion, 'user', [
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
                'farm_id'        => $farm['farm_id'] ?? null,
                'prompt_version' => $this->promptVersion,
            ]);

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
                'farm_id'          => $farm['farm_id'] ?? null,
                'prompt_version'   => $this->promptVersion,
                'model'            => $response->toArray(),
                'raw_output'       => $response->text,
            ]);

            return DraftResult::refused($outputCategory, $message, $this->promptVersion, $traceId, $response);
        }

        try {
            $parsed = $this->parser->parse($response->text);
        } catch (ChecklistFormatException $e) {
            $this->traces->write($traceId, [
                'outcome'        => DraftResult::STATUS_FAILED,
                'stage'          => 'output-validation',
                'error'          => $e->getMessage(),
                'farm_id'        => $farm['farm_id'] ?? null,
                'prompt_version' => $this->promptVersion,
                'model'          => $response->toArray(),
                'raw_output'     => $response->text,
            ]);

            return DraftResult::failed(
                'The draft did not meet the required format and was discarded: ' . $e->getMessage(),
                $this->promptVersion,
                $traceId
            );
        }

        $this->traces->write($traceId, [
            'outcome'        => DraftResult::STATUS_DRAFTED,
            'farm_id'        => $farm['farm_id'] ?? null,
            'notes'          => $notes,
            'prompt_version' => $this->promptVersion,
            'model'          => $response->toArray(),
            'item_count'     => count($parsed['items']),
            'raw_output'     => $response->text,
        ]);

        return DraftResult::drafted(
            $parsed['items'],
            $parsed['summary'],
            $response,
            $this->promptVersion,
            $traceId
        );
    }
}
