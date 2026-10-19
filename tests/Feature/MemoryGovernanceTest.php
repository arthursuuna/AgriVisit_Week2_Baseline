<?php

namespace Tests\Feature;

use App\Models\AgentRunRecord;
use App\Models\FarmMemory;
use App\Models\FarmMemoryDeletion;
use App\Models\ToolApproval;
use App\Services\Agent\AgentTraceWriter;
use App\Services\AgentTask\AgentRunner;
use App\Services\AgentTask\CitationCheck;
use App\Services\Llm\FunctionCalling\AgentModelClient;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Memory\MemoryDesignNote;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolSession;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeAgentModelClient as Fake;
use Tests\Fakes\FakeFunctionCallingClient;
use Tests\TestCase;

/**
 * Week 6 Phase 3: who reads memory, how long it lives, how it is deleted, and
 * what the officer is shown.
 */
class MemoryGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'The farmer is away until November. Please pick a date after 10 November.';

    private string $dir;

    private Fake $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-governance-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([
            ['farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
             'last_visit' => '2026-05-22', 'outstanding_issues' => ['Leaf damage on the maize.'], 'previous_advice' => []],
            ['farm_id' => 'UG-KYA-001', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'beans']],
             'last_visit' => '2026-04-01', 'outstanding_issues' => [], 'previous_advice' => []],
        ]));

        config(['agrivisit.agent.trace_path' => $this->dir . '/runs', 'agrivisit.officer.id' => 'officer-test-9']);
        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));
        $this->app->instance(AgentTraceWriter::class, new AgentTraceWriter($this->dir . '/runs'));
    }

    protected function tearDown(): void
    {
        foreach (['tool-logs', 'runs'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function model(...$replies): void
    {
        $this->fake = new Fake(...$replies);
        $this->app->instance(AgentModelClient::class, $this->fake);
    }

    private function visit(string $date, string $farm = 'UG-KYA-012'): array
    {
        return ['farm_id' => $farm, 'visit_date' => $date, 'purpose' => 'Confirm the cause of the maize leaf damage.'];
    }

    /** The Phase 2 live scenario: a visit proposed for one date, declined on another, with the officer's reason. */
    private function declined(string $date = '2026-10-26', string $farm = 'UG-KYA-012', string $reason = self::REASON): AgentRunRecord
    {
        $this->model(Fake::call('farm_profile_lookup', ['farm_id' => $farm]), Fake::call('schedule_visit', $this->visit($date, $farm)));
        $record = app(AgentRunner::class)->start("Schedule a follow-up visit for farm {$farm}");

        return app(AgentRunner::class)->decide($record, $record->approval['token'], 'reject', $reason)[1];
    }

    private function recall(string $farm = 'UG-KYA-012'): array
    {
        return app(ToolInvoker::class)->invoke('recall_farm_history', ['farm_id' => $farm])->data;
    }

    // 1
    public function test_an_entry_past_the_retention_window_is_not_recalled_but_still_exists(): void
    {
        config(['agrivisit.memory.retention_months' => 12]);
        $this->travelTo('2026-10-01 09:00');
        $this->declined('2026-10-26');                        // M-0001
        $this->travelTo('2027-09-15 09:00');
        $this->declined('2027-10-01');                        // M-0002

        $this->assertSame(['M-0002', 'M-0001'], array_column($this->recall()['entries'], 'memory_ref'), 'Both inside 12 months.');

        $this->travelTo('2027-10-02 09:00');                  // M-0001 is now a year and a day old
        $this->assertSame(['M-0002'], array_column($this->recall()['entries'], 'memory_ref'));
        $this->assertSame(1, $this->recall()['count']);
        $this->assertTrue(FarmMemory::where('memory_ref', 'M-0001')->exists(), 'Filtered from reads, not purged.');

        // The officer still sees it, apart, and can delete it.
        $this->get(route('memory.show', 'UG-KYA-012'))->assertOk()
            ->assertSeeInOrder(['What the assistant can recall', 'M-0002', 'Held, but no longer recalled', 'M-0001']);
    }

    // 2
    public function test_deleting_an_entry_removes_it_from_recall_keeps_traces_and_is_recorded(): void
    {
        $record = $this->declined();

        // A later run recalls and cites it, so a trace now carries M-0001.
        $this->model(
            Fake::call('recall_farm_history', ['farm_id' => 'UG-KYA-012']),
            Fake::text('Proposing November: the officer declined an October visit (M-0001).'),
        );
        $citing = app(AgentRunner::class)->start('When should we visit UG-KYA-012?');

        $traces = [];
        foreach (glob($this->dir . '/runs/*.json') as $file) {
            $traces[basename($file)] = sha1_file($file);
        }
        $this->assertStringContainsString('M-0001', file_get_contents($this->dir . '/runs/' . $citing->trace_files[0]));

        $this->delete(route('memory.destroy', ['UG-KYA-012', 'M-0001']))
            ->assertRedirect(route('memory.show', 'UG-KYA-012'));

        $this->assertSame(0, $this->recall()['count']);
        $this->assertFalse(FarmMemory::where('memory_ref', 'M-0001')->exists());

        // Every trace byte-identical, none removed, none added.
        $after = [];
        foreach (glob($this->dir . '/runs/*.json') as $file) {
            $after[basename($file)] = sha1_file($file);
        }
        $this->assertSame($traces, $after);

        // The deletion is recorded: who, when, which. Not what it said.
        $deletion = FarmMemoryDeletion::sole();
        $this->assertSame('M-0001', $deletion->memory_ref);
        $this->assertSame('UG-KYA-012', $deletion->farm_id);
        $this->assertSame('officer-test-9', $deletion->deleted_by);
        $this->assertNotNull($deletion->deleted_at);
        $this->assertStringNotContainsString('November', json_encode($deletion->getAttributes()));

        // The approval audit and the citing run are untouched; the citation still checks against that run.
        $this->assertSame(self::REASON, ToolApproval::sole()->reject_reason);
        $this->assertSame([], app(CitationCheck::class)->check($citing->fresh())['memory_ref']['unsupported']);
        $this->get(route('memory.show', 'UG-KYA-012'))->assertSee('Deleted')->assertSee('M-0001')->assertDontSee(self::REASON);
    }

    // 3
    public function test_memory_never_reaches_the_model_unless_the_tool_is_called(): void
    {
        $this->declined();
        $this->declined('2026-11-02');
        $this->assertSame(2, FarmMemory::count());

        $this->model(
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012']),
            Fake::call('search_guidance', ['query' => 'maize leaf damage causes']),
            Fake::text('Prepared.'),
        );
        app(AgentRunner::class)->start('Prepare me for a visit to farm UG-KYA-012');

        $sent = json_encode($this->fake->requests);
        $this->assertDoesNotMatchRegularExpression('/M-\d{4}/', $sent, 'No memory_ref in anything sent to the model.');
        $this->assertStringNotContainsString('away until November', $sent);
        $this->assertStringNotContainsString(FarmMemory::first()->summary, $sent);
        $this->assertStringNotContainsString('away until November', $this->fake->requests[0]['system'], 'Never in the system prompt.');
    }

    // 4
    public function test_the_memory_view_shows_every_entry_for_a_farm_and_nothing_for_another(): void
    {
        $record = $this->declined();                                                    // M-0001, UG-KYA-012
        $this->declined('2026-11-20', 'UG-KYA-001', 'Beans harvest that week.');      // M-0002, UG-KYA-001

        $this->get(route('memory.show', 'UG-KYA-012'))->assertOk()
            ->assertSee('what the assistant can recall about this farm, and nothing else', false)
            ->assertSee('M-0001')
            ->assertSee('Declined: Schedule a visit to UG-KYA-012 on 2026-10-26.')
            ->assertSee('2026-10-26')                                                  // proposed for
            ->assertSee(now()->timezone(config('app.timezone'))->format('j M Y'))      // decided
            ->assertSee(self::REASON)
            ->assertSee('officer-test-9')
            ->assertSee(route('agent.runs.show', $record->run_id), false)
            ->assertSee('Delete M-0001')
            ->assertDontSee('M-0002')
            ->assertDontSee('Beans harvest');

        $this->get(route('memory.show', 'UG-KYA-001'))->assertOk()->assertSee('M-0002')->assertDontSee('M-0001');
        $this->get(route('memory.show', 'UG-KYA-999'))->assertOk()->assertSee('no past decisions to recall');
        $this->get('/farms/not-a-farm/memory')->assertNotFound();

        // A farm's ref cannot be deleted through another farm's page.
        $this->delete(route('memory.destroy', ['UG-KYA-001', 'M-0001']))->assertNotFound();
        $this->assertSame(2, FarmMemory::count());
    }

    // 5
    public function test_the_approval_panel_carries_the_proposing_steps_plan_and_what_was_recalled(): void
    {
        $this->declined();   // M-0001

        // The Phase 2 live run's shape: cited one step above, not in the proposing step.
        $this->model(
            Fake::call('recall_farm_history', ['farm_id' => 'UG-KYA-012'], 'Checking past decisions first.'),
            Fake::call('farm_profile_lookup', ['farm_id' => 'UG-KYA-012'], 'The officer declined October; the farmer is away until November (M-0001).'),
            Fake::call('schedule_visit', $this->visit('2026-11-12'), "I'm proposing 2026-11-12, after the farmer returns."),
        );
        $run = app(AgentRunner::class)->start('Schedule a follow-up visit for farm UG-KYA-012');

        $this->get(route('agent.runs.show', $run->run_id))->assertOk()->assertSeeInOrder([
            'APPROVAL_REQUIRED',
            'Schedule a visit to UG-KYA-012 on 2026-11-12.',
            "The assistant's reasoning for this proposal",
            "I'm proposing 2026-11-12, after the farmer returns.",
            'Past decisions the assistant recalled in this run',
            'M-0001',
            self::REASON,
            'Approve',
        ], false);

        // And the recall step links to the farm's memory page.
        $this->get(route('agent.runs.show', $run->run_id))->assertSee(route('memory.show', 'UG-KYA-012'), false);
    }

    // 6
    public function test_proposed_and_occurred_at_are_separate_fields_with_different_dates(): void
    {
        $this->travelTo('2026-10-19 02:40');
        $this->declined('2026-10-26');

        $entry = $this->recall()['entries'][0];
        $this->assertSame('2026-10-19', $entry['occurred_at'], 'When the officer decided.');
        $this->assertSame('2026-10-26', $entry['proposed'], 'What they decided about.');
        $this->assertSame('officer-test-9', $entry['officer_id']);

        // The date only: the model-composed purpose is not in memory at all.
        $this->assertStringNotContainsString('Confirm the cause', json_encode($entry));
        $this->assertStringNotContainsString('Confirm the cause', json_encode(FarmMemory::sole()->getAttributes()));
    }

    // 7
    public function test_the_console_model_is_not_offered_recall_but_the_agent_is_and_a_person_can_still_call_it(): void
    {
        $gemini = new FakeFunctionCallingClient(FakeFunctionCallingClient::text('Hello.'));
        $this->app->instance(FunctionCallingClient::class, $gemini);
        app(ToolSession::class)->handle('What can you do?');
        $this->assertNotContains('recall_farm_history', array_column($gemini->requests[0]['declarations'], 'name'));

        $this->model(Fake::text('Nothing to do.'));
        app(AgentRunner::class)->start('Prepare me for a visit to farm UG-KYA-012');
        $this->assertContains('recall_farm_history', array_column($this->fake->requests[0]['declarations'], 'name'));

        // By hand on the console: listed and callable.
        $this->get(route('tools.index'))->assertSee('recall_farm_history');
        $this->post(route('tools.run'), ['tool' => 'recall_farm_history', 'args' => ['farm_id' => 'UG-KYA-012']])
            ->assertOk()->assertSee('recall_farm_history')->assertDontSee('UNAUTHORIZED');
    }

    // Part F

    public function test_the_note_is_generated_and_every_column_is_justified(): void
    {
        $this->assertSame([], app(MemoryDesignNote::class)->unjustified());

        config(['agrivisit.memory.retention_months' => 9]);
        $this->assertSame(0, Artisan::call('agrivisit:memory-note'));
        $note = Artisan::output();

        $this->assertStringContainsString('**9 months**', $note);
        $this->assertStringNotContainsString('{{', $note, 'Every placeholder filled.');
        $this->assertStringNotContainsString('no justification', $note);
        $this->assertStringContainsString('"proposed"', $note, 'The output schema is read from the tool.');
        $this->assertStringContainsString('| `schedule_visit` | `farm_id`, `visit_date` | `purpose` |', $note);

        foreach (['What is stored', 'What is deliberately not stored', 'Who writes it', 'Who reads it', 'How it reaches the model',
                  'Retention', 'Deletion', 'How use is made visible', 'What is not guaranteed', 'Known gaps'] as $section) {
            $this->assertStringContainsString("## {$section}", $note);
        }
    }
}
