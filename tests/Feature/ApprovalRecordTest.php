<?php

namespace Tests\Feature;

use App\Models\ToolApproval;
use App\Models\Visit;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Llm\LlmException;
use App\Services\Tools\ToolCallLog;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeFunctionCallingClient as Fake;
use Tests\TestCase;

/**
 * The approval record, driven through the console as an officer would.
 */
class ApprovalRecordTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->dir = sys_get_temp_dir() . '/agrivisit-approval-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id'            => 'UG-KYA-012',
            'farmer_alias'       => 'Farmer A',
            'district'           => 'Kyankwanzi',
            'crops'              => [['crop' => 'maize'], ['crop' => 'beans']],
            'last_visit'         => '2026-05-22',
            'outstanding_issues' => [],
            'previous_advice'    => [],
        ]]));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/logs'));

        config([
            'agrivisit.officer.id'            => 'officer-test-7',
            'agrivisit.tools.session_log_path' => $this->dir . '/sessions',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['logs', 'sessions'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function args(): array
    {
        return [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->addWeek()->toDateString(),
            'purpose'    => 'Follow up unconfirmed maize leaf damage',
        ];
    }

    private function holdManual(): TestResponse
    {
        return $this->post(route('tools.run'), ['tool' => 'schedule_visit', 'args' => $this->args()])
            ->assertSee('APPROVAL_REQUIRED');
    }

    private function token(TestResponse $response): string
    {
        preg_match('/name="token" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'No approval token on the page');

        return $m[1];
    }

    private function decide(string $token, string $decision, array $extra = []): TestResponse
    {
        return $this->post(route('tools.approve'), ['token' => $token, 'decision' => $decision] + $extra);
    }

    public function test_1_approving_writes_one_approved_record(): void
    {
        $this->decide($this->token($this->holdManual()), 'approve')
            ->assertSee('Approved — recorded as')
            ->assertSee('A-0001')
            ->assertSee('"visit_id": "V-0001"');

        $this->assertSame(1, ToolApproval::count());

        $record = ToolApproval::first();
        $this->assertSame('A-0001', $record->approval_ref);
        $this->assertSame(ToolApproval::APPROVED, $record->decision);
        $this->assertSame('officer-test-7', $record->decided_by);
        $this->assertSame("Schedule a visit to UG-KYA-012 on {$this->args()['visit_date']}.", $record->summary_shown);
        $this->assertSame($this->args(), $record->arguments);
        $this->assertSame('OK', $record->result_code);
        $this->assertSame('V-0001', $record->result_ref);
        $this->assertNotNull($record->decided_at);
        $this->assertNotEmpty($record->call_id, 'The gate call id should be read back from the tool log');
        $this->assertContains('Farm', array_column($record->preview_shown, 'label'));
    }

    public function test_2_the_visit_row_carries_the_approval_ref_and_created_by(): void
    {
        $this->decide($this->token($this->holdManual()), 'approve');

        $visit = Visit::sole();
        $this->assertSame('A-0001', $visit->approval_ref);
        $this->assertSame('officer-test-7', $visit->created_by);
    }

    public function test_3_rejecting_records_the_reason_and_writes_no_visit(): void
    {
        $this->decide($this->token($this->holdManual()), 'reject', ['reason' => 'Farmer asked to wait until after harvest'])
            ->assertSee('Rejected — nothing was written')
            ->assertSee('A-0001')
            ->assertSee('Farmer asked to wait until after harvest');

        $this->assertSame(0, Visit::count());

        $record = ToolApproval::sole();
        $this->assertSame(ToolApproval::REJECTED, $record->decision);
        $this->assertSame('Farmer asked to wait until after harvest', $record->reject_reason);
        $this->assertSame('officer-test-7', $record->decided_by);
        $this->assertNull($record->result_code);
    }

    public function test_4_the_same_token_twice_creates_no_second_visit(): void
    {
        $token = $this->token($this->holdManual());

        $this->decide($token, 'approve');
        $this->decide($token, 'approve')
            ->assertSee('expired or was already used')
            ->assertSee('already been decided');

        // Rejecting a decided token cannot undo or re-record it either.
        $this->decide($token, 'reject');

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, ToolApproval::count());
    }

    public function test_5_a_token_older_than_ten_minutes_is_recorded_as_expired(): void
    {
        $token = $this->token($this->holdManual());

        $this->travel(11)->minutes();

        $this->decide($token, 'approve')
            ->assertSee('This request timed out')
            ->assertSee('Submit the schedule_visit call again');

        $this->assertSame(0, Visit::count());
        $record = ToolApproval::sole();
        $this->assertSame(ToolApproval::EXPIRED, $record->decision);
        $this->assertNull($record->result_code);

        // A second try finds the decided token, not a fresh expiry.
        $this->decide($token, 'approve')->assertSee('already been decided');
        $this->assertSame(1, ToolApproval::count());
    }

    public function test_5b_a_token_inside_ten_minutes_still_approves(): void
    {
        $token = $this->token($this->holdManual());

        $this->travel(9)->minutes();
        $this->decide($token, 'approve');

        $this->assertSame(1, Visit::count());
    }

    public function test_6_origin_is_model_for_the_assistant_and_manual_for_a_person(): void
    {
        $fake = new Fake(Fake::calls(['schedule_visit', $this->args()]), Fake::text('Visit V-0001 is booked.'));
        $this->app->instance(FunctionCallingClient::class, $fake);

        $held = $this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012 next week'])
            ->assertSee('Proposed by the assistant');

        $this->post(route('tools.ask.approve'), ['token' => $this->token($held), 'decision' => 'approve'])
            ->assertSee('Reply (after officer approval)')
            ->assertSee('A-0001');

        $this->holdManual()->assertSee('Entered manually');
        $this->decide($this->token($this->holdManual()), 'reject');

        $model = ToolApproval::where('approval_ref', 'A-0001')->sole();
        $this->assertSame(ToolApproval::ORIGIN_MODEL, $model->origin);
        $this->assertNotNull($model->session_id);
        $this->assertSame('V-0001', $model->result_ref);

        $manual = ToolApproval::where('approval_ref', 'A-0002')->sole();
        $this->assertSame(ToolApproval::ORIGIN_MANUAL, $manual->origin);
        $this->assertNull($manual->session_id);
    }

    public function test_7_summary_shown_is_exactly_the_panel_text(): void
    {
        $held = $this->holdManual();

        preg_match('/<p class="summary">(.*?)<\/p>/s', $held->getContent(), $m);
        $panelText = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        $this->assertNotSame('', $panelText);

        $this->decide($this->token($held), 'reject');

        $this->assertSame($panelText, ToolApproval::sole()->summary_shown);
    }

    public function test_a_duplicate_warning_advises_but_does_not_block_and_the_audit_keeps_it(): void
    {
        $this->decide($this->token($this->holdManual()), 'approve');   // V-0001, same farm and date

        $held = $this->holdManual()
            ->assertSee('<tr class="warning">', false)
            ->assertSee('A visit to this farm is already scheduled for this date (V-0001).');

        $this->decide($this->token($held), 'approve')->assertSee('"visit_id": "V-0002"');
        $this->assertSame(2, Visit::count());

        $stored = ToolApproval::where('approval_ref', 'A-0002')->sole()->preview_shown;
        $this->assertSame('warning', $stored[0]['level']);

        $this->get(route('tools.audit'))
            ->assertSee('<tr class="warning">', false)
            ->assertSee('already scheduled for this date (V-0001)');
    }

    public function test_an_expired_assistant_request_offers_to_ask_again(): void
    {
        $this->app->instance(FunctionCallingClient::class, new Fake(Fake::calls(['schedule_visit', $this->args()])));

        $token = $this->token($this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012 next week']));
        $this->travel(11)->minutes();

        $this->post(route('tools.ask.approve'), ['token' => $token, 'decision' => 'approve'])
            ->assertSee('This request timed out')
            ->assertSee('<input type="hidden" name="question" value="Book a visit to UG-KYA-012 next week">', false);

        $this->assertSame(ToolApproval::ORIGIN_MODEL, ToolApproval::sole()->origin);
        $this->assertSame(0, Visit::count());
    }

    public function test_a_model_failure_after_approval_still_records_the_written_visit(): void
    {
        $fake = new Fake(Fake::calls(['schedule_visit', $this->args()]), new LlmException('HTTP 503'));
        $this->app->instance(FunctionCallingClient::class, $fake);

        $token = $this->token($this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012 next week']));
        $this->post(route('tools.ask.approve'), ['token' => $token, 'decision' => 'approve'])
            ->assertSee('The approved action was carried out');

        $record = ToolApproval::sole();
        $this->assertSame('OK', $record->result_code);
        $this->assertSame('V-0001', $record->result_ref);
        $this->assertSame('A-0001', Visit::sole()->approval_ref);
    }
}
