<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Services\Tools\Catalogue\ScheduleVisitTool;
use App\Services\Tools\SchemaValidator;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolPolicy;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use App\Support\FarmProfileRepository;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ToolInvokerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private ToolRegistry $registry;

    private ToolCallLog $log;

    private ToolInvoker $invoker;

    /** Calls to execute() on the fake tool, which must stay 0 for refused calls. */
    private int $executions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/agrivisit-invoker-test-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([
            ['farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => []],
        ]));

        $this->registry = new ToolRegistry();
        $this->registry->register(new ScheduleVisitTool(new FarmProfileRepository($this->dir . '/farms.json')));
        $this->registry->register($this->fakeTool('echo_farm', fn (array $args) => ToolResult::success($args)));

        $this->log     = new ToolCallLog($this->dir . '/logs');
        $this->invoker = new ToolInvoker($this->registry, new SchemaValidator(), new ToolPolicy(), $this->log);

        config(['agrivisit.tools.enabled' => ['schedule_visit', 'echo_farm', 'exploding', 'misshapen']]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/logs/*') ?: []);
        @rmdir($this->dir . '/logs');
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** A read tool taking one farm_id and returning one farm_id. */
    private function fakeTool(string $name, Closure $execute): ToolContract
    {
        return new class($name, $execute, $this->executions) implements ToolContract
        {
            public function __construct(
                private readonly string $name,
                private readonly Closure $run,
                private int &$executions,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'Test tool.';
            }

            public function inputSchema(): array
            {
                return [
                    'type'       => 'object',
                    'properties' => ['farm_id' => ['type' => 'string', 'pattern' => '^UG-[A-Z]{3}-\d{3}$']],
                    'required'   => ['farm_id'],
                ];
            }

            public function outputSchema(): array
            {
                return $this->inputSchema();
            }

            public function isWrite(): bool
            {
                return false;
            }

            public function approvalSummary(array $args): string
            {
                return 'Read.';
            }

            public function execute(array $args): ToolResult
            {
                $this->executions++;

                return ($this->run)($args);
            }
        };
    }

    private function visitArgs(): array
    {
        return [
            'farm_id'    => 'UG-KYA-012',
            'visit_date' => now()->addDays(14)->toDateString(),
            'purpose'    => 'Follow up leaf damage on the maize plot',
        ];
    }

    private function lastLoggedOutcome(): ?string
    {
        $entries = $this->log->entries();

        return end($entries)['outcome'] ?? null;
    }

    public function test_an_unregistered_name_returns_tool_not_found_and_executes_nothing(): void
    {
        $result = $this->invoker->invoke('delete_farm', ['farm_id' => 'UG-KYA-012']);

        $this->assertFalse($result->ok);
        $this->assertSame(ToolResult::TOOL_NOT_FOUND, $result->code);
        $this->assertSame(0, $this->executions);
        $this->assertSame(0, Visit::count());
        $this->assertSame(ToolResult::TOOL_NOT_FOUND, $this->lastLoggedOutcome());
    }

    public function test_invalid_arguments_are_refused_before_execution(): void
    {
        $result = $this->invoker->invoke('echo_farm', ['farm_id' => 'kya 12', 'extra' => true]);

        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $result->code);
        $this->assertStringContainsString("'farm_id'", $result->message);
        $this->assertStringContainsString("'extra'", $result->message);
        $this->assertSame(0, $this->executions);
        $this->assertSame(ToolResult::INVALID_ARGUMENTS, $this->lastLoggedOutcome());
    }

    public function test_a_registered_but_disabled_tool_returns_unauthorized(): void
    {
        config(['agrivisit.tools.enabled' => ['schedule_visit']]);

        $result = $this->invoker->invoke('echo_farm', ['farm_id' => 'UG-KYA-012']);

        $this->assertSame(ToolResult::UNAUTHORIZED, $result->code);
        $this->assertSame(0, $this->executions);
        $this->assertSame(ToolResult::UNAUTHORIZED, $this->lastLoggedOutcome());
    }

    public function test_a_write_tool_without_approval_writes_nothing(): void
    {
        $result = $this->invoker->invoke('schedule_visit', $this->visitArgs());

        $this->assertFalse($result->ok);
        $this->assertSame(ToolResult::APPROVAL_REQUIRED, $result->code);
        $this->assertSame('Schedule a visit to UG-KYA-012 on ' . $this->visitArgs()['visit_date'] . '.', $result->message);
        $this->assertSame(['tool' => 'schedule_visit', 'arguments' => $this->visitArgs()], $result->pending);
        $this->assertSame(0, Visit::count());
        $this->assertSame(ToolResult::APPROVAL_REQUIRED, $this->lastLoggedOutcome());
    }

    public function test_the_same_call_with_approval_writes_exactly_one_row(): void
    {
        $pending = $this->invoker->invoke('schedule_visit', $this->visitArgs())->pending;

        $result = $this->invoker->invoke($pending['tool'], $pending['arguments'], approved: true);

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertNull($result->code);
        $this->assertSame(1, Visit::count());
        $this->assertSame('V-0001', $result->data['visit_id']);
        $this->assertSame('scheduled', $result->data['status']);
        $this->assertSame('OK', $this->lastLoggedOutcome());
    }

    public function test_a_tool_that_throws_returns_service_unavailable(): void
    {
        $this->registry->register($this->fakeTool('exploding', function () {
            throw new RuntimeException('Farm profile file not found');
        }));

        $result = $this->invoker->invoke('exploding', ['farm_id' => 'UG-KYA-012']);

        $this->assertSame(ToolResult::SERVICE_UNAVAILABLE, $result->code);
        $this->assertStringContainsString('Farm profile file not found', $result->message);
        $this->assertSame(1, $this->executions);
        $this->assertSame(ToolResult::SERVICE_UNAVAILABLE, $this->lastLoggedOutcome());
    }

    public function test_output_failing_its_schema_returns_unexpected_response_and_drops_the_data(): void
    {
        $this->registry->register($this->fakeTool('misshapen', fn () => ToolResult::success([
            'farm_id'  => 42,
            'password' => 'leaked',
        ])));

        $result = $this->invoker->invoke('misshapen', ['farm_id' => 'UG-KYA-012']);

        $this->assertFalse($result->ok);
        $this->assertSame(ToolResult::UNEXPECTED_RESPONSE, $result->code);
        $this->assertSame([], $result->data);
        $this->assertStringNotContainsString('leaked', json_encode($result->toArray()));
        $this->assertSame(ToolResult::UNEXPECTED_RESPONSE, $this->lastLoggedOutcome());
    }

    public function test_every_attempt_is_logged_with_its_outcome_and_duration(): void
    {
        $this->registry->register($this->fakeTool('exploding', fn () => throw new RuntimeException('down')));
        $this->registry->register($this->fakeTool('misshapen', fn () => ToolResult::success(['farm_id' => 1])));

        $this->invoker->invoke('delete_farm', []);
        $this->invoker->invoke('echo_farm', ['farm_id' => 'bad']);
        $this->invoker->invoke('schedule_visit', $this->visitArgs());
        $this->invoker->invoke('schedule_visit', $this->visitArgs(), approved: true);
        $this->invoker->invoke('exploding', ['farm_id' => 'UG-KYA-012']);
        $this->invoker->invoke('misshapen', ['farm_id' => 'UG-KYA-012']);
        config(['agrivisit.tools.enabled' => []]);
        $this->invoker->invoke('echo_farm', ['farm_id' => 'UG-KYA-012']);

        $entries = $this->log->entries();

        $this->assertSame([
            ToolResult::TOOL_NOT_FOUND,
            ToolResult::INVALID_ARGUMENTS,
            ToolResult::APPROVAL_REQUIRED,
            'OK',
            ToolResult::SERVICE_UNAVAILABLE,
            ToolResult::UNEXPECTED_RESPONSE,
            ToolResult::UNAUTHORIZED,
        ], array_column($entries, 'outcome'));

        foreach ($entries as $entry) {
            $this->assertArrayHasKey('tool', $entry);
            $this->assertArrayHasKey('arguments', $entry);
            $this->assertIsNumeric($entry['duration_ms']);
        }

        $this->assertSame('delete_farm', $entries[0]['tool']);
        $this->assertFalse($entries[0]['registered']);
        $this->assertTrue($entries[3]['approved']);
    }

    public function test_registering_a_duplicate_name_throws(): void
    {
        $this->expectException(LogicException::class);

        $this->registry->register($this->fakeTool('echo_farm', fn () => ToolResult::success([])));
    }
}
