<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Services\Tools\ApprovalPreview;
use App\Services\Tools\ToolCallLog;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private ToolCallLog $toolLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 09:00:00');

        $this->dir = sys_get_temp_dir() . '/agrivisit-preview-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id'      => 'UG-KYA-012',
            'farmer_alias' => 'Farmer A',
            'district'     => 'Kyankwanzi',
            'crops'        => [
                ['crop' => 'maize', 'variety' => 'Longe 5', 'area_acres' => 2.0],
                ['crop' => 'beans', 'variety' => 'NABE 15', 'area_acres' => 1.0],
            ],
            'last_visit'         => '2026-05-22',
            'outstanding_issues' => [],
            'previous_advice'    => [],
        ]]));

        $this->toolLog = new ToolCallLog($this->dir . '/logs');
        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, $this->toolLog);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/logs/*') ?: []);
        @rmdir($this->dir . '/logs');
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function preview(string $tool, array $args): array
    {
        return $this->app->make(ApprovalPreview::class)->for($tool, $args);
    }

    /** @return array{label: string, value: string, note: ?string} */
    private function row(array $preview, string $label): array
    {
        foreach ($preview as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        $this->fail("No '{$label}' row in the preview.");
    }

    private function visitArgs(array $overrides = []): array
    {
        return $overrides + [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => '2026-11-20',
            'purpose'    => 'Follow up the leaf damage',
        ];
    }

    public function test_1_schedule_visit_preview_names_the_farm_district_and_crops(): void
    {
        $farm = $this->row($this->preview('schedule_visit', $this->visitArgs()), 'Farm');

        $this->assertStringContainsString('Kyankwanzi', $farm['value']);
        $this->assertStringContainsString("Farmer A's farm", $farm['value']);
        $this->assertStringContainsString('maize (Longe 5, 2 acres)', $farm['note']);
        $this->assertStringContainsString('beans (NABE 15, 1 acre)', $farm['note']);

        $labels = array_column($this->preview('schedule_visit', $this->visitArgs()), 'label');
        $this->assertSame(['Action', 'Farm', 'Date', 'Purpose', 'Existing visits', 'Reversible'], $labels);
    }

    public function test_2_the_date_row_names_the_weekday_and_days_from_today(): void
    {
        $date = $this->row($this->preview('schedule_visit', $this->visitArgs()), 'Date');

        $this->assertSame('Friday 20 November 2026', $date['value']);
        $this->assertSame('In 45 days.', $date['note']);

        $tomorrow = $this->row($this->preview('schedule_visit', $this->visitArgs(['visit_date' => '2026-10-07'])), 'Date');
        $this->assertSame('Wednesday 7 October 2026', $tomorrow['value']);
        $this->assertSame('Tomorrow.', $tomorrow['note']);
    }

    public function test_3_an_unknown_farm_is_could_not_be_confirmed_not_a_missing_row(): void
    {
        $preview = $this->preview('schedule_visit', $this->visitArgs(['farm_id' => 'UG-XXX-999']));
        $farm    = $this->row($preview, 'Farm');

        $this->assertSame(ApprovalPreview::UNCONFIRMED, $farm['value']);
        $this->assertStringContainsString('UG-XXX-999', $farm['note']);
        $this->assertCount(6, $preview);

        $badDate = $this->row($this->preview('schedule_visit', $this->visitArgs(['visit_date' => '2026-02-30'])), 'Date');
        $this->assertSame(ApprovalPreview::UNCONFIRMED, $badDate['value']);
    }

    public function test_4_log_followup_preview_names_the_visit_farm_and_date(): void
    {
        Visit::create(['visit_ref' => 'V-0001', 'farm_id' => 'UG-KYA-012', 'visit_date' => '2026-11-20',
            'purpose' => 'Follow up the leaf damage', 'status' => 'scheduled']);

        $preview = $this->preview('log_followup', [
            'farm_id'  => 'UG-KYA-012',
            'visit_id' => 'V-0001',
            'notes'    => 'Leaf damage confirmed as fall armyworm. Farmer to scout weekly.',
            'outcome'  => 'escalated',
        ]);

        $visit = $this->row($preview, 'Visit');
        $this->assertStringContainsString('V-0001', $visit['value']);
        $this->assertStringContainsString('UG-KYA-012 (Kyankwanzi)', $visit['value']);
        $this->assertStringContainsString('Friday 20 November 2026', $visit['value']);

        $this->assertSame('Leaf damage confirmed as fall armyworm. Farmer to scout weekly.', $this->row($preview, 'Notes')['value']);
        $this->assertStringContainsString('specialist or supervisor', $this->row($preview, 'Outcome')['note']);
        $this->assertSame('A follow-up note cannot be edited once recorded', $this->row($preview, 'Reversible')['value']);

        $missing = $this->row($this->preview('log_followup', ['visit_id' => 'V-9999', 'farm_id' => 'UG-KYA-012',
            'notes' => 'n', 'outcome' => 'ongoing']), 'Visit');
        $this->assertSame(ApprovalPreview::UNCONFIRMED, $missing['value']);
    }

    public function test_5_building_a_preview_executes_no_tool(): void
    {
        $this->preview('schedule_visit', $this->visitArgs());
        $this->preview('log_followup', ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-0001',
            'notes' => 'n', 'outcome' => 'ongoing']);

        $this->assertSame(0, Visit::count());
        $this->assertSame([], $this->toolLog->entries());
    }

    private function book(string $ref, string $farmId, string $date): void
    {
        Visit::create(['visit_ref' => $ref, 'farm_id' => $farmId, 'visit_date' => $date,
            'purpose' => 'p', 'status' => 'scheduled']);
    }

    public function test_dup_1_a_visit_on_the_same_farm_and_date_heads_the_preview(): void
    {
        $this->book('V-0007', 'UG-KYA-012', '2026-11-20');

        $preview = $this->preview('schedule_visit', $this->visitArgs());

        $this->assertSame('Possible duplicate', $preview[0]['label']);
        $this->assertSame('A visit to this farm is already scheduled for this date (V-0007).', $preview[0]['value']);
        $this->assertSame('Action', $preview[1]['label']);
    }

    public function test_dup_2_the_same_farm_on_a_different_date_is_not_flagged(): void
    {
        // The day before and the day after: near misses are normal, not warnings.
        $this->book('V-0001', 'UG-KYA-012', '2026-11-19');
        $this->book('V-0002', 'UG-KYA-012', '2026-11-21');

        $labels = array_column($this->preview('schedule_visit', $this->visitArgs()), 'label');

        $this->assertNotContains('Possible duplicate', $labels);
        $this->assertSame('Action', $labels[0]);
    }

    public function test_dup_3_a_different_farm_on_the_same_date_is_not_flagged(): void
    {
        $this->book('V-0001', 'UG-MTY-045', '2026-11-20');

        $this->assertNotContains('Possible duplicate',
            array_column($this->preview('schedule_visit', $this->visitArgs()), 'label'));
    }

    public function test_dup_4_every_visit_on_that_date_is_named(): void
    {
        $this->book('V-0007', 'UG-KYA-012', '2026-11-20');
        $this->book('V-0008', 'UG-MTY-045', '2026-11-20');
        $this->book('V-0009', 'UG-KYA-012', '2026-11-20');

        $this->assertSame('A visit to this farm is already scheduled for this date (V-0007, V-0009).',
            $this->preview('schedule_visit', $this->visitArgs())[0]['value']);
    }

    public function test_dup_5_only_the_duplicate_row_is_a_warning(): void
    {
        $this->book('V-0007', 'UG-KYA-012', '2026-11-20');

        $preview = $this->preview('schedule_visit', $this->visitArgs());

        $this->assertSame(ApprovalPreview::WARNING, $preview[0]['level']);
        foreach (array_slice($preview, 1) as $row) {
            $this->assertSame(ApprovalPreview::NORMAL, $row['level'], "Row '{$row['label']}' should be normal");
        }

        // log_followup rows carry the key too, all normal.
        $followup = $this->preview('log_followup', ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-0007',
            'notes' => 'n', 'outcome' => 'ongoing']);
        $this->assertSame([ApprovalPreview::NORMAL], array_values(array_unique(array_column($followup, 'level'))));
    }

    public function test_dup_6_the_duplicate_lookup_executes_no_tool_and_creates_no_rows(): void
    {
        $this->book('V-0007', 'UG-KYA-012', '2026-11-20');

        $this->preview('schedule_visit', $this->visitArgs());

        $this->assertSame(1, Visit::count());
        $this->assertSame([], $this->toolLog->entries());
    }

    /** Today is Tuesday 6 October 2026 (setUp). */
    private function existingVisits(array $dates): string
    {
        foreach ($dates as $i => $date) {
            Visit::create(['visit_ref' => sprintf('V-%04d', $i + 1), 'farm_id' => 'UG-KYA-012',
                'visit_date' => $date, 'purpose' => 'p', 'status' => 'scheduled']);
        }

        return $this->row($this->preview('schedule_visit', $this->visitArgs()), 'Existing visits')['value'];
    }

    public function test_existing_visits_all_ahead_name_the_next_not_the_most_recent(): void
    {
        $this->assertSame('2 already booked; next is on Tuesday 20 October 2026',
            $this->existingVisits(['2026-10-20', '2026-11-03']));
    }

    public function test_existing_visits_all_past_name_the_latest(): void
    {
        $this->assertSame('2 already booked; latest was on Friday 2 October 2026',
            $this->existingVisits(['2026-09-14', '2026-10-02']));
    }

    public function test_existing_visits_past_and_ahead_name_both(): void
    {
        $this->assertSame('3 already booked; latest was on Monday 5 October 2026; next is on Tuesday 6 October 2026',
            $this->existingVisits(['2026-09-14', '2026-10-05', '2026-10-06']));
    }
}
