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
}
