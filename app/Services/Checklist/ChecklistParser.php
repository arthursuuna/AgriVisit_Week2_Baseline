<?php

namespace App\Services\Checklist;

use JsonException;

/**
 * Validates that the model returned the structured output the prompt demanded.
 *
 * A fluent answer in the wrong shape is a failure, not a partial success
 * (Lecture 3, slide 10). Parsing is strict so that schema drift is caught in
 * the evaluation set rather than in the officer's browser.
 */
class ChecklistParser
{
    public function __construct(
        private readonly int $minItems,
        private readonly int $maxItems,
    ) {
    }

    /** A parser with a different item range, for a prompt version that needs one. */
    public function withRange(int $minItems, int $maxItems): self
    {
        return new self($minItems, $maxItems);
    }

    /**
     * Without $validLabels, every item must say "ungrounded" (prompt v1.x, no
     * corpus). With them, every item must cite one of those labels (v2.0): the
     * same deterministic check, inverted, so a fabricated citation is rejected
     * either way. In grounded mode an empty list is the model's honest answer
     * that the evidence supports nothing, and is returned rather than rejected.
     *
     * @param  list<string>|null  $validLabels
     * @return array{items: list<array<string, mixed>>, summary: string}
     *
     * @throws ChecklistFormatException
     */
    public function parse(string $raw, ?array $validLabels = null): array
    {
        $json = $this->extractJson($raw);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ChecklistFormatException('Model output was not valid JSON: ' . $e->getMessage());
        }

        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
            throw new ChecklistFormatException('Model output is missing an "items" array.');
        }

        $count = count($data['items']);

        if ($validLabels !== null && $count === 0) {
            return ['items' => [], 'summary' => is_string($data['summary'] ?? null) ? $data['summary'] : ''];
        }

        if ($count < $this->minItems || $count > $this->maxItems) {
            throw new ChecklistFormatException(
                sprintf('Expected %d-%d checklist items, received %d.', $this->minItems, $this->maxItems, $count)
            );
        }

        foreach ($data['items'] as $index => $item) {
            foreach (['item', 'category', 'rationale', 'grounding'] as $field) {
                if (! isset($item[$field]) || ! is_string($item[$field]) || trim($item[$field]) === '') {
                    throw new ChecklistFormatException(
                        sprintf('Item %d is missing a non-empty "%s" field.', $index + 1, $field)
                    );
                }
            }

            if ($validLabels === null && $item['grounding'] !== 'ungrounded') {
                throw new ChecklistFormatException(
                    sprintf('Item %d claims grounding "%s"; no corpus exists before Week 3.', $index + 1, $item['grounding'])
                );
            }

            if ($validLabels !== null && ! in_array($item['grounding'], $validLabels, true)) {
                throw new ChecklistFormatException(sprintf(
                    'Item %d cites "%s", which is not among the evidence supplied (%s).',
                    $index + 1,
                    $item['grounding'],
                    $validLabels === [] ? 'none' : implode(', ', $validLabels)
                ));
            }
        }

        return [
            'items'   => array_values($data['items']),
            'summary' => is_string($data['summary'] ?? null) ? $data['summary'] : '',
        ];
    }

    /** Tolerates a fenced code block around the JSON without accepting prose. */
    private function extractJson(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }
}
