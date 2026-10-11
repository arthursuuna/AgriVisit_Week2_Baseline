<?php

namespace Tests\Feature;

use App\Models\Visit;
use App\Services\Llm\FunctionCalling\FunctionCallingClient;
use App\Services\Llm\LlmException;
use App\Services\Tools\ToolCallLog;
use App\Support\FarmProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeFunctionCallingClient as Fake;
use Tests\TestCase;

/**
 * A rate-limited or timed-out model is a handled failure: a red panel with the
 * reason and a session-log entry, never an exception or an error page.
 */
class ModelFailureTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->dir = sys_get_temp_dir() . '/agrivisit-model-failure-' . uniqid();
        mkdir($this->dir, 0775, true);

        file_put_contents($this->dir . '/farms.json', json_encode([[
            'farm_id' => 'UG-KYA-012', 'district' => 'Kyankwanzi', 'crops' => [['crop' => 'maize']],
            'last_visit' => null, 'outstanding_issues' => [], 'previous_advice' => [],
        ]]));

        $this->app->instance(FarmProfileRepository::class, new FarmProfileRepository($this->dir . '/farms.json'));
        $this->app->instance(ToolCallLog::class, new ToolCallLog($this->dir . '/tool-logs'));
        config(['agrivisit.tools.session_log_path' => $this->dir . '/sessions']);
    }

    protected function tearDown(): void
    {
        foreach (['tool-logs', 'sessions'] as $sub) {
            array_map('unlink', glob("{$this->dir}/{$sub}/*") ?: []);
            @rmdir("{$this->dir}/{$sub}");
        }
        @unlink($this->dir . '/farms.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function sessionLog(): array
    {
        return (new ToolCallLog($this->dir . '/sessions'))->entries();
    }

    private function rateLimited(): LlmException
    {
        return new LlmException('Model returned HTTP 429: {"error":{"status":"RESOURCE_EXHAUSTED"}}', 0, null, 429, LlmException::RATE_LIMITED);
    }

    public function test_a_429_from_the_model_is_a_handled_result_with_a_session_log_entry(): void
    {
        $this->app->instance(FunctionCallingClient::class, new Fake($this->rateLimited()));

        $this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012 on 20 November'])
            ->assertOk()
            ->assertSee('<div class="banner refused">', false)
            ->assertSee('Model failure')
            ->assertSee('The model is rate limited, try again later.')
            ->assertDontSee('RESOURCE_EXHAUSTED')
            ->assertDontSee('Stack trace');

        $entries = $this->sessionLog();
        $this->assertCount(1, $entries);
        $this->assertSame('model_error', $entries[0]['status']);
        $this->assertSame(429, $entries[0]['model_error']['status']);
        $this->assertSame(LlmException::RATE_LIMITED, $entries[0]['model_error']['kind']);
        $this->assertStringContainsString('RESOURCE_EXHAUSTED', $entries[0]['model_error']['message']);
        $this->assertSame(0, Visit::count());
    }

    public function test_a_timeout_is_a_handled_result_with_a_session_log_entry(): void
    {
        $timeout = new LlmException('Model request timed out after 1 s: cURL error 28', 0, null, null, LlmException::TIMEOUT);
        $this->app->instance(FunctionCallingClient::class, new Fake($timeout));

        $this->post(route('tools.ask'), ['question' => 'What crops are on UG-KYA-012?'])
            ->assertOk()
            ->assertSee('The model did not respond in time, try again later.');

        $this->assertSame(LlmException::TIMEOUT, $this->sessionLog()[0]['model_error']['kind']);
    }

    public function test_a_429_after_an_approved_write_is_handled_and_both_logs_record_it(): void
    {
        $args = ['farm_id' => 'UG-KYA-012', 'visit_date' => now()->addWeek()->toDateString(), 'purpose' => 'Follow up'];
        $this->app->instance(FunctionCallingClient::class, new Fake(Fake::calls(['schedule_visit', $args]), $this->rateLimited()));

        $held = $this->post(route('tools.ask'), ['question' => 'Book a visit to UG-KYA-012 next week'])->getContent();
        preg_match('/name="token" value="([^"]+)"/', $held, $m);

        $this->post(route('tools.ask.approve'), ['token' => $m[1], 'decision' => 'approve'])
            ->assertOk()
            ->assertSee('The approved action was carried out')
            ->assertSee('The model is rate limited, try again later.');

        $this->assertSame(1, Visit::count());
        $sessions = $this->sessionLog();
        $this->assertSame('model_error', end($sessions)['status']);
        $this->assertSame(429, end($sessions)['model_error']['status']);

        $gate = (new ToolCallLog($this->dir . '/tool-logs'))->entries();
        $last = end($gate);
        $this->assertSame('OK', $last['outcome']);
        $this->assertTrue($last['approved']);
    }
}
