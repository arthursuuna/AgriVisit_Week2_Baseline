<?php

namespace Tests\Feature;

use App\Models\Followup;
use App\Models\Visit;
use App\Services\Tools\Catalogue\LogFollowupTool;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The three catalogue tools, resolved from the container exactly as the
 * application wires them, against a fixture farm file.
 */
class ToolCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private ToolInvoker $invoker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-catalogue-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id'            => 'UG-KYA-012',
            'farmer_alias'       => 'Farmer A',
            'district'           => 'Kyankwanzi',
            'soil_notes'         => 'Sandy loam.',
            'crops'              => [
                ['crop' => 'maize', 'area_acres' => 2.0, 'variety' => 'Longe 5', 'planted' => '2026-03-10'],
            ],
            'last_visit'         => null,
            'outstanding_issues' => ['Leaf damage on the maize plot.'],
            'previous_advice'    => ['Keep a planting record book.'],
        ]]));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/logs'));

        $this->invoker = $this->app->make(ToolInvoker::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/logs/*') ?: []);
        @rmdir($this->dir . '/logs');
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function scheduleVisit(): string
    {
        $result = $this->invoker->invoke('schedule_visit', [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->addWeek()->toDateString(),
            'purpose'    => 'Inspect leaf damage',
        ], approved: true);

        $this->assertTrue($result->ok, (string) $result->message);

        return $result->data['visit_id'];
    }

    public function test_farm_profile_lookup_returns_the_declared_fields(): void
    {
        $result = $this->invoker->invoke('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame(
            ['farm_id', 'district', 'crops', 'last_visit', 'outstanding_issues', 'previous_advice'],
            array_keys($result->data)
        );
        $this->assertSame('Kyankwanzi', $result->data['district']);
        $this->assertSame('maize', $result->data['crops'][0]['crop']);
        $this->assertNull($result->data['last_visit']);
        $this->assertArrayNotHasKey('farmer_alias', $result->data);
    }

    public function test_farm_profile_lookup_with_an_unknown_id_returns_resource_not_found(): void
    {
        $result = $this->invoker->invoke('farm_profile_lookup', ['farm_id' => 'UG-ZZZ-999']);

        $this->assertSame(ToolResult::RESOURCE_NOT_FOUND, $result->code);
    }

    public function test_schedule_visit_with_a_past_date_returns_invalid_arguments(): void
    {
        $args = [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->subDay()->toDateString(),
            'purpose'    => 'Inspect leaf damage',
        ];

        // Refused before the approval step, and again if replayed as approved.
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $this->invoker->invoke('schedule_visit', $args)->code);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $this->invoker->invoke('schedule_visit', $args, approved: true)->code);
        $this->assertSame(0, Visit::count());
    }

    public function test_schedule_visit_approved_creates_one_row_with_a_unique_ref(): void
    {
        $first = $this->scheduleVisit();

        $this->assertSame(1, Visit::count());
        $this->assertSame('V-0001', $first);
        $this->assertDatabaseHas('visits', ['visit_ref' => 'V-0001', 'farm_id' => 'UG-KYA-012', 'status' => 'scheduled']);

        $second = $this->scheduleVisit();

        $this->assertSame(2, Visit::count());
        $this->assertNotSame($first, $second);
    }

    public function test_schedule_visit_for_an_unknown_farm_returns_resource_not_found(): void
    {
        $result = $this->invoker->invoke('schedule_visit', [
            'farm_id'    => 'UG-ZZZ-999',
            'visit_date' => now()->addWeek()->toDateString(),
            'purpose'    => 'Inspect',
        ]);

        $this->assertSame(ToolResult::RESOURCE_NOT_FOUND, $result->code);
    }

    public function test_log_followup_against_an_unknown_visit_returns_resource_not_found(): void
    {
        $args = [
            'farm_id'  => 'UG-KYA-012',
            'visit_id' => 'V-9999',
            'notes'    => 'Leaf damage confirmed as fall armyworm.',
            'outcome'  => 'escalated',
        ];

        $this->assertSame(ToolResult::RESOURCE_NOT_FOUND, $this->invoker->invoke('log_followup', $args)->code);
        $this->assertSame(ToolResult::RESOURCE_NOT_FOUND, $this->invoker->invoke('log_followup', $args, approved: true)->code);
        $this->assertSame(0, Followup::count());
    }

    public function test_log_followup_approved_records_one_entry(): void
    {
        $visitId = $this->scheduleVisit();
        $args    = [
            'farm_id'  => 'UG-KYA-012',
            'visit_id' => $visitId,
            'notes'    => 'Leaf damage confirmed as fall armyworm.',
            'outcome'  => 'escalated',
        ];

        $held = $this->invoker->invoke('log_followup', $args);
        $this->assertSame(ToolResult::APPROVAL_REQUIRED, $held->code);
        $this->assertSame("Record an escalated follow-up note against visit {$visitId}.", $held->message);
        $this->assertSame(0, Followup::count());

        $result = $this->invoker->invoke('log_followup', $args, approved: true);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame('F-0001', $result->data['entry_id']);
        $this->assertSame(1, Followup::count());
        $this->assertSame($visitId, Followup::first()->visit->visit_ref);
    }

    public function test_log_followup_against_another_farms_visit_is_refused(): void
    {
        $visitId = $this->scheduleVisit();

        $result = $this->invoker->invoke('log_followup', [
            'farm_id'  => 'UG-MTY-045',
            'visit_id' => $visitId,
            'notes'    => 'Wrong farm.',
            'outcome'  => 'ongoing',
        ], approved: true);

        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $result->code);
        $this->assertSame(0, Followup::count());
    }

    public function test_schedule_visit_accepts_today_in_kampala_even_when_utc_is_still_yesterday(): void
    {
        $this->assertSame('Africa/Kampala', config('app.timezone'));

        // 01:30 in Kampala on 5 October is 22:30 UTC on 4 October.
        $this->travelTo(Carbon::parse('2026-10-05 01:30', 'Africa/Kampala'));

        $args = ['farm_id' => 'UG-KYA-012', 'purpose' => 'Inspect leaf damage'];

        $today = $this->invoker->invoke('schedule_visit', $args + ['visit_date' => '2026-10-05'], approved: true);
        $this->assertTrue($today->ok, (string) $today->message);
        $this->assertSame('2026-10-05', $today->data['visit_date']);

        $yesterday = $this->invoker->invoke('schedule_visit', $args + ['visit_date' => '2026-10-04'], approved: true);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $yesterday->code);
        $this->assertSame(1, Visit::count());
    }

    public function test_log_followup_approval_summary_uses_the_right_article_for_every_outcome(): void
    {
        $tool = new LogFollowupTool();
        $args = ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-0001', 'notes' => 'n'];

        $this->assertSame('Record a resolved follow-up note against visit V-0001.',
            $tool->approvalSummary($args + ['outcome' => 'resolved']));
        $this->assertSame('Record an ongoing follow-up note against visit V-0001.',
            $tool->approvalSummary($args + ['outcome' => 'ongoing']));
        $this->assertSame('Record an escalated follow-up note against visit V-0001.',
            $tool->approvalSummary($args + ['outcome' => 'escalated']));

        // Covers every value the schema allows, so a new outcome cannot slip past this test.
        $this->assertSame(['resolved', 'ongoing', 'escalated'], $tool->inputSchema()['properties']['outcome']['enum']);
    }

    public function test_every_registered_schema_is_well_formed(): void
    {
        $tools = $this->app->make(ToolRegistry::class)->all();

        $this->assertSame(
            ['farm_profile_lookup', 'schedule_visit', 'log_followup'],
            array_map(fn (ToolContract $tool) => $tool->name(), $tools)
        );

        foreach ($tools as $tool) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $tool->name());
            $this->assertNotSame('', trim($tool->description()));

            foreach (['input' => $tool->inputSchema(), 'output' => $tool->outputSchema()] as $kind => $schema) {
                $where = "{$tool->name()} {$kind} schema";

                $this->assertSame('object', $schema['type'] ?? null, "{$where}: type must be object");
                $this->assertNotEmpty($schema['properties'] ?? [], "{$where}: properties must not be empty");

                foreach ($schema['required'] ?? [] as $name) {
                    $this->assertArrayHasKey($name, $schema['properties'], "{$where}: required '{$name}' is not a property");
                }

                foreach ($schema['properties'] as $name => $property) {
                    $this->assertArrayHasKey('type', $property, "{$where}: '{$name}' has no type");
                }
            }

            // The model is told about every input parameter, so each needs a description.
            foreach ($tool->inputSchema()['properties'] as $name => $property) {
                $this->assertNotEmpty($property['description'] ?? '', "{$tool->name()}: '{$name}' has no description");
            }
        }
    }
}
