<?php

namespace Tests\Feature;

use App\Services\Retrieval\FarmQueryBuilder;
use Tests\TestCase;

class FarmQueryBuilderTest extends TestCase
{
    private function farm(array $issues = []): array
    {
        return [
            'farm_id'            => 'UG-KYA-012',
            'total_area_acres'   => 3.5,
            'water_source'       => 'Seasonal stream',
            'crops'              => [['crop' => 'maize'], ['crop' => 'Beans']],
            'outstanding_issues' => $issues,
        ];
    }

    public function test_one_query_per_issue_plus_one_for_the_crops(): void
    {
        $queries = (new FarmQueryBuilder())->build($this->farm([
            'Leaf damage reported on the maize plot.',
            'Farmer asked about improving bean yields.',
        ]));

        $this->assertSame([
            'Leaf damage reported on the maize plot. maize, beans',
            'Farmer asked about improving bean yields. maize, beans',
            'maize, beans',
        ], $queries);
    }

    public function test_officer_notes_become_their_own_query(): void
    {
        $queries = (new FarmQueryBuilder())->build($this->farm(), 'Termites seen near the store.');

        $this->assertSame(['Termites seen near the store. maize, beans', 'maize, beans'], $queries);
    }

    public function test_a_farm_with_no_issues_gets_the_crop_query_only(): void
    {
        $this->assertSame(['maize, beans'], (new FarmQueryBuilder())->build($this->farm()));
    }

    public function test_irrelevant_profile_fields_are_not_queried(): void
    {
        $joined = implode(' ', (new FarmQueryBuilder())->build($this->farm(['Wilting seen.'])));

        $this->assertStringNotContainsString('UG-KYA-012', $joined);
        $this->assertStringNotContainsString('Seasonal stream', $joined);
    }

    public function test_crop_names_are_lower_cased_and_unique(): void
    {
        $farm = $this->farm();
        $farm['crops'][] = ['crop' => 'maize'];

        $this->assertSame(['maize', 'beans'], (new FarmQueryBuilder())->crops($farm));
    }
}
