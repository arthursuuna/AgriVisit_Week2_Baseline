<?php

namespace Tests\Feature;

use App\Services\Checklist\ChecklistFormatException;
use App\Services\Checklist\ChecklistParser;
use Tests\TestCase;

class ChecklistParserTest extends TestCase
{
    private function parser(): ChecklistParser
    {
        return new ChecklistParser(5, 10);
    }

    private function validPayload(int $items = 5): string
    {
        $list = [];

        for ($i = 0; $i < $items; $i++) {
            $list[] = [
                'item'      => 'Inspect the maize plot margins.',
                'category'  => 'crop health',
                'rationale' => 'Leaf damage was reported at the last visit.',
                'grounding' => 'ungrounded',
            ];
        }

        return json_encode(['summary' => 'Follow up reported leaf damage.', 'items' => $list]);
    }

    public function test_it_accepts_a_well_formed_payload(): void
    {
        $parsed = $this->parser()->parse($this->validPayload());

        $this->assertCount(5, $parsed['items']);
    }

    public function test_it_tolerates_a_fenced_code_block(): void
    {
        $parsed = $this->parser()->parse("```json\n" . $this->validPayload() . "\n```");

        $this->assertCount(5, $parsed['items']);
    }

    public function test_it_rejects_prose_instead_of_json(): void
    {
        $this->expectException(ChecklistFormatException::class);

        $this->parser()->parse('Here is a helpful checklist for your visit!');
    }

    public function test_it_rejects_too_few_items(): void
    {
        $this->expectException(ChecklistFormatException::class);

        $this->parser()->parse($this->validPayload(3));
    }

    public function test_it_rejects_a_fabricated_citation(): void
    {
        $payload = json_decode($this->validPayload(), true);
        $payload['items'][0]['grounding'] = 'MAAIF Coffee Handbook p.14';

        $this->expectException(ChecklistFormatException::class);

        $this->parser()->parse(json_encode($payload));
    }

    /** A v2.0 payload: every item cites one of the given labels. */
    private function groundedPayload(array $labels): string
    {
        $items = array_map(fn ($label) => [
            'item'      => 'Check the bean seed bed is fine and even.',
            'category'  => 'husbandry',
            'rationale' => 'The farmer asked about improving bean yields.',
            'grounding' => $label,
        ], $labels);

        return json_encode(['summary' => 'Follow up bean yields.', 'items' => $items]);
    }

    public function test_grounded_mode_accepts_labels_from_the_supplied_evidence(): void
    {
        $parsed = $this->parser()->withRange(3, 10)
            ->parse($this->groundedPayload(['C-1', 'C-3', 'C-2']), ['C-1', 'C-2', 'C-3']);

        $this->assertCount(3, $parsed['items']);
    }

    public function test_grounded_mode_rejects_a_label_outside_the_supplied_evidence(): void
    {
        $this->expectException(ChecklistFormatException::class);
        $this->expectExceptionMessage('C-9');

        $this->parser()->withRange(3, 10)
            ->parse($this->groundedPayload(['C-1', 'C-2', 'C-9']), ['C-1', 'C-2', 'C-3']);
    }

    public function test_grounded_mode_rejects_ungrounded_items(): void
    {
        $this->expectException(ChecklistFormatException::class);

        $this->parser()->withRange(3, 10)->parse($this->validPayload(5), ['C-1', 'C-2']);
    }

    public function test_grounded_mode_uses_its_own_item_range(): void
    {
        $this->assertCount(3, $this->parser()->withRange(3, 10)
            ->parse($this->groundedPayload(['C-1', 'C-1', 'C-2']), ['C-1', 'C-2'])['items']);

        $this->expectException(ChecklistFormatException::class);

        $this->parser()->withRange(3, 10)->parse($this->groundedPayload(['C-1', 'C-2']), ['C-1', 'C-2']);
    }

    public function test_grounded_mode_returns_an_honest_empty_list(): void
    {
        $parsed = $this->parser()->withRange(3, 10)->parse('{"summary": "", "items": []}', ['C-1']);

        $this->assertSame([], $parsed['items']);
    }
}
