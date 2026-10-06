<?php

namespace Tests\Feature;

use App\Services\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SchemaValidator();
    }

    private function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'farm_id'    => ['type' => 'string', 'pattern' => '^UG-[A-Z]{3}-\d{3}$'],
                'visit_date' => ['type' => 'string', 'format' => 'date'],
                'outcome'    => ['type' => 'string', 'enum' => ['resolved', 'ongoing', 'escalated']],
                'acres'      => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'crops'      => ['type' => 'array', 'items' => ['type' => 'string']],
                'urgent'     => ['type' => 'boolean'],
            ],
            'required' => ['farm_id', 'visit_date'],
        ];
    }

    public function test_a_valid_object_returns_no_errors(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => '2026-12-01',
            'outcome'    => 'ongoing',
            'acres'      => 3.5,
            'crops'      => ['maize', 'beans'],
            'urgent'     => false,
        ]);

        $this->assertSame([], $errors);
    }

    public function test_a_missing_required_property_is_reported_by_name(): void
    {
        $errors = $this->validator->validate($this->schema(), ['farm_id' => 'UG-KYA-012']);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("'visit_date'", $errors[0]);
        $this->assertStringContainsString('Missing required', $errors[0]);
    }

    public function test_a_wrong_type_names_the_property_and_both_types(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id'    => 12,
            'visit_date' => '2026-12-01',
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("'farm_id'", $errors[0]);
        $this->assertStringContainsString('expected string', $errors[0]);
        $this->assertStringContainsString('received integer', $errors[0]);
    }

    public function test_an_unexpected_property_is_reported(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => '2026-12-01',
            'approved'   => true,
        ]);

        $this->assertSame(["Unexpected parameter 'approved'."], $errors);
    }

    public function test_a_value_outside_an_enum_is_reported(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => '2026-12-01',
            'outcome'    => 'fixed',
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("'outcome'", $errors[0]);
        $this->assertStringContainsString('"fixed" is not one of', $errors[0]);
    }

    public function test_a_string_failing_a_pattern_is_reported(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id'    => 'KYA-12',
            'visit_date' => '2026-12-01',
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("'farm_id'", $errors[0]);
        $this->assertStringContainsString('does not match the pattern', $errors[0]);
    }

    public function test_a_malformed_date_is_reported(): void
    {
        foreach (['01/12/2026', '2026-02-30', 'next tuesday', '2026-1-5'] as $date) {
            $errors = $this->validator->validate($this->schema(), [
                'farm_id'    => 'UG-KYA-012',
                'visit_date' => $date,
            ]);

            $this->assertCount(1, $errors, "'{$date}' should be rejected");
            $this->assertStringContainsString('not a valid date', $errors[0]);
        }
    }

    public function test_multiple_problems_return_multiple_errors(): void
    {
        $errors = $this->validator->validate($this->schema(), [
            'farm_id' => 'nope',
            'outcome' => 'fixed',
            'acres'   => -1,
            'crops'   => ['maize', 7],
            'urgent'  => 'yes',
            'extra'   => 1,
        ]);

        $this->assertCount(7, $errors);

        $all = implode("\n", $errors);
        $this->assertStringContainsString("'visit_date'", $all);
        $this->assertStringContainsString("'farm_id'", $all);
        $this->assertStringContainsString("'outcome'", $all);
        $this->assertStringContainsString("'acres'", $all);
        $this->assertStringContainsString("'crops[1]'", $all);
        $this->assertStringContainsString("'urgent'", $all);
        $this->assertStringContainsString("'extra'", $all);
    }

    public function test_length_limits_and_nullable_types(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'purpose'    => ['type' => 'string', 'maxLength' => 5],
                'last_visit' => ['type' => ['string', 'null'], 'format' => 'date'],
            ],
        ];

        $this->assertSame([], $this->validator->validate($schema, ['purpose' => 'ok', 'last_visit' => null]));

        $errors = $this->validator->validate($schema, ['purpose' => 'far too long']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('at most 5 characters', $errors[0]);
    }
}
