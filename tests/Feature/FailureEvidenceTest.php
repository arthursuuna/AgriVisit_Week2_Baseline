<?php

namespace Tests\Feature;

use App\Console\Commands\GatherFailureEvidence;
use App\Models\Followup;
use App\Models\Visit;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Services\Tools\ToolSession;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fakes\FakeFunctionCallingClient as Fake;
use Tests\TestCase;

class FailureEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private const EXPECTED = [
        1 => ToolResult::TOOL_NOT_FOUND,
        2 => ToolResult::INVALID_ARGUMENTS,
        3 => ToolResult::INVALID_ARGUMENTS,
        4 => ToolResult::INVALID_ARGUMENTS,
        5 => ToolResult::INVALID_ARGUMENTS,
        6 => ToolResult::RESOURCE_NOT_FOUND,
        7 => ToolResult::APPROVAL_REQUIRED,
        8 => ToolResult::UNAUTHORIZED,
        9 => ToolResult::SERVICE_UNAVAILABLE,
        10 => ToolResult::UNEXPECTED_RESPONSE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-evidence-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
            'last_visit' => null, 'outstanding_issues' => [], 'previous_advice' => [],
        ]]));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/logs'));
        config(['agrivisit.tools.session_log_path' => $this->dir . '/sessions']);
    }

    protected function tearDown(): void
    {
        foreach (['logs', 'sessions'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        array_map('unlink', glob("{$this->dir}/*.*") ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** Rebuilds the registry and invoker so the probe switch takes effect. */
    private function probes(bool $enabled): void
    {
        config(['agrivisit.tools.probes_enabled' => $enabled]);
        $this->app->forgetInstance(ToolRegistry::class);
        $this->app->forgetInstance(ToolInvoker::class);
    }

    private function runCommand(int $exit = 0): array
    {
        $this->artisan('agrivisit:evidence', ['--output' => $this->dir . '/evidence.md'])->assertExitCode($exit);

        return json_decode(file_get_contents($this->dir . '/evidence.json'), true)['cases'];
    }

    public function test_1_the_command_runs_end_to_end_and_writes_the_evidence_file(): void
    {
        $this->probes(true);
        $this->runCommand();

        $md = file_get_contents($this->dir . '/evidence.md');
        $this->assertStringContainsString('# Week 4 — Failure and Authorization Evidence', $md);
        $this->assertStringContainsString(GatherFailureEvidence::BEGIN, $md);
        $this->assertStringContainsString('**10 of 10 cases pass.**', $md);
        $this->assertStringContainsString('test instrumentation, not product behaviour', $md);

        // A re-run replaces only the automated block; hand-written sections survive.
        file_put_contents($this->dir . '/evidence.md', str_replace('_Run through the Ask panel',
            'HAND-RECORDED NOTE. _Run through the Ask panel', $md));
        $this->runCommand();
        $this->assertStringContainsString('HAND-RECORDED NOTE.', file_get_contents($this->dir . '/evidence.md'));
        $this->assertSame(1, substr_count(file_get_contents($this->dir . '/evidence.md'), GatherFailureEvidence::BEGIN));
    }

    public function test_2_every_case_returns_the_code_it_expects(): void
    {
        $this->probes(true);
        $cases = $this->runCommand();

        $this->assertSame(self::EXPECTED, array_column($cases, 'returned', 'case'));
        $this->assertSame(['pass'], array_values(array_unique(array_column($cases, 'status'))));

        foreach ($cases as $case) {
            $this->assertNotEmpty($case['call_id'], "Case {$case['case']} has no tool-log call_id");
            $this->assertSame('person', $case['caller']);
        }

        // The malformed payload never reaches the caller.
        $this->assertSame([], $cases[9]['data_returned']);
    }

    public function test_3_case_8_restores_the_enabled_tools_even_when_the_invocation_throws(): void
    {
        $this->probes(true);
        $original = config('agrivisit.tools.enabled');
        $real     = $this->app->make(ToolInvoker::class);

        $this->mock(ToolInvoker::class, fn ($mock) => $mock->shouldReceive('invoke')
            ->andReturnUsing(function (string $name, array $args, bool $approved = false) use ($real) {
                if ($name === 'log_followup') {
                    throw new RuntimeException('Simulated crash inside case 8');
                }

                return $real->invoke($name, $args, $approved);
            }));

        $cases = $this->runCommand(1);

        $this->assertSame($original, config('agrivisit.tools.enabled'));
        $this->assertContains('log_followup', config('agrivisit.tools.enabled'));
        $this->assertSame('EXCEPTION', $cases[7]['returned']);
        $this->assertSame('fail', $cases[7]['status']);
    }

    public function test_3b_case_8_restores_the_enabled_tools_after_a_normal_run(): void
    {
        $this->probes(true);
        $original = config('agrivisit.tools.enabled');

        $this->runCommand();

        $this->assertSame($original, config('agrivisit.tools.enabled'));
    }

    public function test_4_no_case_changes_the_visit_or_followup_counts(): void
    {
        $this->probes(true);
        $visit = Visit::create(['visit_ref' => 'V-0001', 'farm_id' => 'UG-KYA-012', 'visit_date' => now()->addWeek(),
            'purpose' => 'existing', 'status' => 'scheduled']);
        Followup::create(['entry_ref' => 'F-0001', 'visit_id' => $visit->id, 'farm_id' => 'UG-KYA-012',
            'notes' => 'existing', 'outcome' => 'ongoing', 'recorded_at' => now()]);

        $cases = $this->runCommand();

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, Followup::count());
        foreach ($cases as $case) {
            $this->assertTrue($case['db_unchanged'], "Case {$case['case']} changed the database");
            $this->assertSame(['visits' => 1, 'followups' => 1, 'tool_approvals' => 0], $case['counts_after']);
        }
    }

    public function test_5_the_probe_tools_are_not_registered_when_disabled(): void
    {
        $this->probes(false);
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertFalse($registry->has('faulty_probe'));
        $this->assertFalse($registry->has('malformed_probe'));

        // Checked before any probe call, which would show up in "Recent calls".
        $this->get(route('tools.index'))->assertDontSee('faulty_probe')->assertDontSee('malformed_probe');

        $this->assertSame(ToolResult::TOOL_NOT_FOUND, $this->app->make(ToolInvoker::class)->invoke('faulty_probe', ['farm_id' => 'UG-KYA-012'])->code);

        // The command says so rather than reporting a false pass.
        $cases = $this->runCommand(1);
        $this->assertSame('skipped', $cases[8]['status']);
        $this->assertSame('skipped', $cases[9]['status']);
    }

    public function test_6_the_probe_tools_are_never_declared_to_the_model_even_when_enabled(): void
    {
        $this->probes(true);
        $this->assertTrue($this->app->make(ToolRegistry::class)->has('faulty_probe'));
        $this->get(route('tools.index'))->assertSee('faulty_probe');   // the manual picker does list them

        $fake = new Fake(Fake::text('Hello.'));
        $this->app->instance(FunctionCallingClient::class, $fake);
        $this->app->make(ToolSession::class)->handle('What can you do?');

        $offered = array_column($fake->requests[0]['declarations'], 'name');
        $this->assertSame(['farm_profile_lookup', 'schedule_visit', 'log_followup'], $offered);
    }
}
