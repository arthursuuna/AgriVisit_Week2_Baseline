<?php

namespace Tests\Feature;

use App\Models\Followup;
use App\Models\Visit;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use Tests\Fakes\FakeFunctionCallingClient;
use App\Services\Tools\ToolCallLog;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The /tools page, walked through the brief's verification scenario.
 */
class ToolConsoleTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->dir = sys_get_temp_dir() . '/agrivisit-console-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id'            => 'UG-KYA-012',
            'district'           => 'Kyankwanzi',
            'crops'              => [['crop' => 'maize'], ['crop' => 'beans']],
            'last_visit'         => '2026-05-22',
            'outstanding_issues' => ['Leaf damage reported on the maize plot.'],
            'previous_advice'    => [],
        ]]));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/logs'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/logs/*') ?: []);
        @rmdir($this->dir . '/logs');
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function runTool(string $tool, array $args = [], array $extra = []): TestResponse
    {
        return $this->post(route('tools.run'), ['tool' => $tool, 'args' => $args] + $extra);
    }

    /** The approval token the amber panel posts back. */
    private function token(TestResponse $response): string
    {
        preg_match('/name="token" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'No approval token on the page');

        return $m[1];
    }

    private function scheduleArgs(): array
    {
        return [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->addWeek()->toDateString(),
            'purpose'    => 'Follow up unconfirmed maize leaf damage',
        ];
    }

    public function test_the_form_is_generated_from_the_schema(): void
    {
        $this->get(route('tools.index', ['tool' => 'log_followup']))
            ->assertOk()
            ->assertSee('<select name="args[outcome]"', false)
            ->assertSee('<textarea name="args[notes]"', false)
            ->assertSee('<input type="text" name="args[visit_id]"', false)
            ->assertSee('Visit ID returned by schedule_visit')
            ->assertSee('^V-\d{4,}$')
            ->assertDontSee('pattern=', false)
            ->assertDontSee('maxlength=', false);

        $this->get(route('tools.index', ['tool' => 'schedule_visit']))
            ->assertSee('<input type="date" name="args[visit_date]"', false);
    }

    public function test_step_1_a_read_tool_returns_data_without_approval(): void
    {
        $this->runTool('farm_profile_lookup', ['farm_id' => 'UG-KYA-012'])
            ->assertOk()
            ->assertSee('banner ok', false)
            ->assertSee('Leaf damage reported on the maize plot.')
            ->assertSee('maize')
            ->assertDontSee('APPROVAL_REQUIRED</span></strong>', false);
    }

    public function test_steps_2_to_4_write_tools_wait_for_approval_then_write_once(): void
    {
        $held = $this->runTool('schedule_visit', $this->scheduleArgs())
            ->assertSee('APPROVAL_REQUIRED')
            ->assertSee('Schedule a visit to UG-KYA-012 on ' . $this->scheduleArgs()['visit_date'] . '.');

        $this->assertSame(0, Visit::count());

        $token = $this->token($held);

        $this->post(route('tools.approve'), ['token' => $token, 'decision' => 'approve'])
            ->assertSee('banner ok', false)
            ->assertSee('"visit_id": "V-0001"');

        $this->assertSame(1, Visit::count());

        // The token is single use: replaying it writes nothing more.
        $this->post(route('tools.approve'), ['token' => $token, 'decision' => 'approve'])
            ->assertSee('expired or was already used');
        $this->assertSame(1, Visit::count());

        $held = $this->runTool('log_followup', [
            'farm_id'  => 'UG-KYA-012',
            'visit_id' => 'V-0001',
            'notes'    => 'Leaf damage confirmed as fall armyworm.',
            'outcome'  => 'ongoing',
        ])->assertSee('Record an ongoing follow-up note against visit V-0001.');

        $this->assertSame(0, Followup::count());

        $this->post(route('tools.approve'), ['token' => $this->token($held), 'decision' => 'approve'])
            ->assertSee('"entry_id": "F-0001"');

        $this->assertSame(1, Followup::count());
    }

    public function test_approval_replays_the_held_arguments_not_the_form(): void
    {
        $token = $this->token($this->runTool('schedule_visit', $this->scheduleArgs()));

        $this->post(route('tools.approve'), [
            'token'     => $token,
            'decision'  => 'approve',
            'tool'      => 'schedule_visit',
            'args'      => ['farm_id' => 'UG-MTY-045', 'purpose' => 'Tampered'],
            'arguments' => ['purpose' => 'Tampered'],
        ])->assertOk();

        $this->assertSame('Follow up unconfirmed maize leaf damage', Visit::first()->purpose);
    }

    public function test_cancel_discards_the_call_and_writes_nothing(): void
    {
        $token = $this->token($this->runTool('schedule_visit', $this->scheduleArgs()));

        $this->post(route('tools.approve'), ['token' => $token, 'decision' => 'cancel'])
            ->assertSee('nothing was written');

        $this->assertSame(0, Visit::count());
    }

    public function test_step_5_each_refusal_button_produces_its_code(): void
    {
        $this->runTool('__other', [], ['other' => 'delete_farm'])
            ->assertSee('TOOL_NOT_FOUND');

        $this->runTool('farm_profile_lookup', ['farm_id' => 'KYA12'])
            ->assertSee('INVALID_ARGUMENTS')
            ->assertSee("Parameter &#039;farm_id&#039;: &#039;KYA12&#039; does not match the pattern", false);

        $this->runTool('schedule_visit', ['visit_date' => now()->subYear()->toDateString()] + $this->scheduleArgs())
            ->assertSee('INVALID_ARGUMENTS')
            ->assertSee('is in the past');

        $this->runTool('log_followup', [
            'farm_id'  => 'UG-KYA-012',
            'visit_id' => 'V-9999',
            'notes'    => 'n',
            'outcome'  => 'ongoing',
        ])->assertSee('RESOURCE_NOT_FOUND');

        // The refusal buttons link to pre-filled forms for exactly these calls.
        $this->get(route('tools.index'))
            ->assertSee('other=delete_farm', false)
            ->assertSee('KYA12')
            ->assertSee('V-9999');
    }

    public function test_every_validation_error_gets_its_own_line(): void
    {
        $response = $this->runTool('schedule_visit', ['farm_id' => 'bad', 'visit_date' => '01/12/2026']);

        $this->assertSame(3, substr_count(
            explode('<ul class="errs">', $response->getContent())[1] ?? '',
            '<li>'
        ));
    }

    public function test_ask_shows_the_approval_panel_and_approving_writes_one_row(): void
    {
        $fake = new FakeFunctionCallingClient(
            FakeFunctionCallingClient::calls(['schedule_visit', $this->scheduleArgs()]),
            FakeFunctionCallingClient::text('Visit V-0001 is booked for UG-KYA-012.'),
        );
        $this->app->instance(FunctionCallingClient::class, $fake);

        $held = $this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012'])
            ->assertSee('APPROVAL_REQUIRED')
            ->assertSee('Proposed by the assistant')
            ->assertSee('Schedule a visit to UG-KYA-012 on ' . $this->scheduleArgs()['visit_date'] . '.');

        $this->assertSame(0, Visit::count());
        $this->assertSame(1, $fake->callCount());

        preg_match('/action="[^"]*ask\/approve".*?name="token" value="([^"]+)"/s', $held->getContent(), $m);

        $this->post(route('tools.ask.approve'), ['token' => $m[1], 'decision' => 'approve'])
            ->assertSee('Reply (after officer approval)')
            ->assertSee('Visit V-0001 is booked for UG-KYA-012.')
            ->assertSee('<span class="tag ok">OK</span>', false);

        $this->assertSame(1, Visit::count());
        $this->assertSame(2, $fake->callCount());
    }

    public function test_ask_refuses_a_dosing_request_without_calling_the_model(): void
    {
        $fake = new FakeFunctionCallingClient();
        $this->app->instance(FunctionCallingClient::class, $fake);

        $this->post(route('tools.ask'), ['question' => 'What dose of pesticide should the farmer apply?'])
            ->assertSee('Refused before any model call')
            ->assertSee('does not provide pesticide doses');

        $this->assertSame(0, $fake->callCount());
    }

    public function test_step_6_recent_calls_list_every_attempt_including_refusals(): void
    {
        $this->runTool('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']);
        $this->runTool('__other', [], ['other' => 'delete_farm']);
        $token = $this->token($this->runTool('schedule_visit', $this->scheduleArgs()));
        $this->post(route('tools.approve'), ['token' => $token, 'decision' => 'approve']);

        $page = $this->get(route('tools.index'))->getContent();
        $rows = explode('<h2>Recent calls', $page)[1];

        $this->assertSame(4, substr_count($rows, '<span class="tag'));
        $this->assertStringContainsString('<span class="tag refused">TOOL_NOT_FOUND</span>', $rows);
        $this->assertStringContainsString('<span class="tag pending">APPROVAL_REQUIRED</span>', $rows);
        $this->assertSame(2, substr_count($rows, '<span class="tag ok">OK</span>'));

        // Newest first: the approved schedule_visit heads the table.
        $this->assertLessThan(strpos($rows, 'TOOL_NOT_FOUND'), strpos($rows, 'tag ok'));
    }
}
