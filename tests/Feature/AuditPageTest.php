<?php

namespace Tests\Feature;

use App\Models\ToolApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->record('approved', 'model', 'Schedule a visit to UG-KYA-012 on 2026-11-20.', 'OK', 'V-0003');
        $this->record('rejected', 'manual', 'Schedule a visit to UG-MTY-045 on 2026-11-21.', reason: 'Wrong farm');
        $this->record('expired', 'model', 'Record an ongoing follow-up note against visit V-0002.');
    }

    private function record(string $decision, string $origin, string $summary, ?string $code = null, ?string $ref = null, ?string $reason = null): ToolApproval
    {
        $n = ToolApproval::count() + 1;

        return ToolApproval::create([
            'approval_ref'  => sprintf('A-%04d', $n),
            'pending_token' => "token-{$n}",
            'tool_name'     => str_starts_with($summary, 'Record') ? 'log_followup' : 'schedule_visit',
            'arguments'     => ['farm_id' => 'UG-KYA-012', 'marker' => "args-{$n}"],
            'summary_shown' => $summary,
            'preview_shown' => [['label' => 'Farm', 'value' => "Preview farm row {$n}", 'note' => "Preview note {$n}"]],
            'origin'        => $origin,
            'decision'      => $decision,
            'decided_by'    => 'officer-001',
            'decided_at'    => now()->addMinutes($n),
            'reject_reason' => $reason,
            'result_code'   => $code,
            'result_ref'    => $ref,
        ]);
    }

    public function test_1_the_page_lists_approved_rejected_and_expired_records(): void
    {
        $page = $this->get(route('tools.audit'))
            ->assertOk()
            ->assertSee('<span class="tag ok">approved</span>', false)
            ->assertSee('<span class="tag notice">rejected</span>', false)
            ->assertSee('<span class="tag refused">expired</span>', false)
            ->assertSee('Schedule a visit to UG-KYA-012 on 2026-11-20.')
            ->assertSee('V-0003')
            ->assertSee('Assistant')
            ->assertSee('Manual')
            ->assertSee('officer-001')
            ->assertSee('configured, not authenticated')
            ->getContent();

        // Newest first.
        $this->assertLessThan(strpos($page, 'data-record="A-0001"'), strpos($page, 'data-record="A-0003"'));
    }

    public function test_2_the_decision_filter_narrows_the_list(): void
    {
        $this->get(route('tools.audit', ['decision' => 'rejected']))
            ->assertSee('data-record="A-0002"', false)
            ->assertDontSee('data-record="A-0001"', false)
            ->assertDontSee('data-record="A-0003"', false)
            ->assertSee('Wrong farm');

        $this->get(route('tools.audit', ['decision' => 'expired']))
            ->assertSee('data-record="A-0003"', false)
            ->assertDontSee('data-record="A-0002"', false);

        // An unknown filter shows everything rather than nothing.
        $this->get(route('tools.audit', ['decision' => 'bogus']))
            ->assertSee('data-record="A-0001"', false)
            ->assertSee('data-record="A-0003"', false);
    }

    public function test_3_a_record_expands_to_its_stored_preview_and_arguments(): void
    {
        $page = $this->get(route('tools.audit'))->getContent();

        $start  = strpos($page, '<tr class="detail" id="detail-1"');
        $this->assertNotFalse($start, 'No detail row for the first record');
        $detail = substr($page, $start, strpos($page, '</tr>', strpos($page, 'Raw arguments', $start)) - $start);

        $this->assertStringContainsString('Preview farm row 1', $detail);
        $this->assertStringContainsString('Preview note 1', $detail);
        $this->assertStringContainsString('&quot;marker&quot;: &quot;args-1&quot;', $detail);
        $this->assertStringContainsString('aria-controls="detail-1"', $page);
    }

    public function test_the_console_links_to_the_audit_page(): void
    {
        $this->get(route('tools.index'))->assertSee(route('tools.audit'), false);
    }
}
