<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Models\ToolApproval;
use App\Models\Visit;
use App\Services\Tools\ToolCallLog;
use App\Services\Tools\ToolInvoker;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolResult;
use Illuminate\Console\Command;
use Throwable;

/**
 * Week 4 Phase 4: every failure condition that can be caused deterministically,
 * run through the real ToolInvoker, recorded as evidence.
 *
 *   TOOLS_PROBES_ENABLED=true php artisan agrivisit:evidence
 *
 * Each case records the code expected and returned, the database counts before
 * and after (nothing may change), and the tool-log call_id. Exits non-zero if
 * any case returns the wrong code, changes a count, or cannot run, so it also
 * serves as a regression check.
 *
 * Only the block between the AUTOMATED markers is rewritten; the hand-recorded
 * model-caused sections of the evidence file survive re-runs.
 */
class GatherFailureEvidence extends Command
{
    public const BEGIN = '<!-- BEGIN AUTOMATED: php artisan agrivisit:evidence -->';
    public const END   = '<!-- END AUTOMATED -->';

    protected $signature = 'agrivisit:evidence
                            {--output= : Markdown file to write (default evidence/week4-failure-evidence.md)}';

    protected $description = 'Trigger every deterministic tool failure through the gate and record the evidence';

    public function handle(ToolInvoker $invoker, ToolRegistry $registry, ToolCallLog $log): int
    {
        $rows = array_map(fn (array $case) => $this->runCase($case, $invoker, $registry, $log), $this->cases());

        $this->table(
            ['#', 'Condition', 'Expected', 'Returned', 'DB unchanged', 'call_id', 'Result'],
            array_map(fn (array $r) => [
                $r['case'], $r['condition'], $r['expected'], $r['returned'] ?? '—',
                $r['db_unchanged'] ? 'yes' : 'NO', $r['call_id'] ? substr($r['call_id'], 0, 8) : '—', strtoupper($r['status']),
            ], $rows)
        );

        $path = $this->option('output') ?: base_path('evidence/week4-failure-evidence.md');
        $this->write($path, $rows);
        $this->info("Evidence written to {$path} and " . basename($this->jsonPath($path)));

        $failed = array_filter($rows, fn (array $r) => $r['status'] !== 'pass');

        if ($failed !== []) {
            $this->error(count($failed) . ' case(s) did not pass: ' . implode(', ', array_column($failed, 'case')));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function cases(): array
    {
        $future = now()->addDays(30)->toDateString();
        $past   = now()->subDays(7)->toDateString();

        return [
            ['case' => 1, 'condition' => 'Unlisted tool call', 'expected' => ToolResult::TOOL_NOT_FOUND,
             'tool' => 'delete_farm', 'args' => ['farm_id' => 'UG-KYA-012'],
             'how' => 'Invoked `delete_farm`, a name that is not registered.'],
            ['case' => 2, 'condition' => 'Invalid parameter (format)', 'expected' => ToolResult::INVALID_ARGUMENTS,
             'tool' => 'farm_profile_lookup', 'args' => ['farm_id' => 'KYA12'],
             'how' => '`farm_profile_lookup` with `farm_id` "KYA12", which fails the UG-XXX-000 pattern.'],
            ['case' => 3, 'condition' => 'Missing parameter', 'expected' => ToolResult::INVALID_ARGUMENTS,
             'tool' => 'schedule_visit', 'args' => ['farm_id' => 'UG-KYA-012', 'visit_date' => $future],
             'how' => '`schedule_visit` with no `purpose`.'],
            ['case' => 4, 'condition' => 'Unexpected parameter', 'expected' => ToolResult::INVALID_ARGUMENTS,
             'tool' => 'farm_profile_lookup', 'args' => ['farm_id' => 'UG-KYA-012', 'approved' => true],
             'how' => '`farm_profile_lookup` with an extra `approved: true` field smuggled into the arguments.'],
            ['case' => 5, 'condition' => 'Business rule, approval supplied', 'expected' => ToolResult::INVALID_ARGUMENTS,
             'tool' => 'schedule_visit', 'approved' => true,
             'args' => ['farm_id' => 'UG-KYA-012', 'visit_date' => $past, 'purpose' => 'Evidence: past date with approval'],
             'how' => "`schedule_visit` for {$past} (in the past) **with `approved: true`**: approval is not a bypass."],
            ['case' => 6, 'condition' => 'Subject does not exist', 'expected' => ToolResult::RESOURCE_NOT_FOUND,
             'tool' => 'farm_profile_lookup', 'args' => ['farm_id' => 'UG-ZZZ-999'],
             'how' => '`farm_profile_lookup` for `UG-ZZZ-999`, well-formed but not on record.'],
            ['case' => 7, 'condition' => 'Higher-impact action without approval', 'expected' => ToolResult::APPROVAL_REQUIRED,
             'tool' => 'schedule_visit',
             'args' => ['farm_id' => 'UG-KYA-012', 'visit_date' => $future, 'purpose' => 'Evidence: held for approval'],
             'how' => "A valid `schedule_visit` for {$future}, no approval."],
            ['case' => 8, 'condition' => 'Unauthorized (tool disabled)', 'expected' => ToolResult::UNAUTHORIZED,
             'tool' => 'log_followup', 'disable' => 'log_followup',
             'args' => ['farm_id' => 'UG-KYA-012', 'visit_id' => 'V-0001', 'notes' => 'Evidence: farmer has applied mulch.', 'outcome' => 'ongoing'],
             'how' => '`log_followup` removed from the enabled list for this one call, then restored (try/finally).'],
            ['case' => 9, 'condition' => 'Unavailable service', 'expected' => ToolResult::SERVICE_UNAVAILABLE,
             'tool' => 'faulty_probe', 'probe' => true, 'args' => ['farm_id' => 'UG-KYA-012'],
             'how' => '`faulty_probe` (diagnostic instrumentation) throws as an unreachable dependency would.'],
            ['case' => 10, 'condition' => 'Unexpected tool response', 'expected' => ToolResult::UNEXPECTED_RESPONSE,
             'tool' => 'malformed_probe', 'probe' => true, 'args' => ['farm_id' => 'UG-KYA-012'],
             'how' => '`malformed_probe` (diagnostic instrumentation) returns `{"unexpected": true}` against an output schema requiring `farm_id` and `status`.'],
        ];
    }

    private function runCase(array $case, ToolInvoker $invoker, ToolRegistry $registry, ToolCallLog $log): array
    {
        $row = [
            'case'         => $case['case'],
            'condition'    => $case['condition'],
            'expected'     => $case['expected'],
            'returned'     => null,
            'message'      => null,
            'caller'       => 'person',
            'tool'         => $case['tool'],
            'arguments'    => $case['args'],
            'approved'     => $case['approved'] ?? false,
            'how'          => $case['how'],
            'counts_before' => $this->counts(),
            'counts_after' => null,
            'db_unchanged' => false,
            'data_returned' => null,
            'call_id'      => null,
            'status'       => 'fail',
        ];

        if (! empty($case['probe']) && ! $registry->has($case['tool'])) {
            $row['status']       = 'skipped';
            $row['message']      = 'Probe tools are not registered. Re-run with TOOLS_PROBES_ENABLED=true.';
            $row['counts_after'] = $this->counts();
            $row['db_unchanged'] = $row['counts_after'] === $row['counts_before'];

            return $row;
        }

        $original = config('agrivisit.tools.enabled');

        try {
            if (isset($case['disable'])) {
                config(['agrivisit.tools.enabled' => array_values(array_diff($original, [$case['disable']]))]);
            }

            $result = $invoker->invoke($case['tool'], $case['args'], $row['approved']);

            $row['returned']      = $result->code ?? 'OK';
            $row['message']       = $result->message;
            $row['data_returned'] = $result->data;
            $row['call_id']       = $this->lastCallId($log, $case['tool']);
        } catch (Throwable $e) {
            $row['returned'] = 'EXCEPTION';
            $row['message']  = get_class($e) . ': ' . $e->getMessage();
        } finally {
            // Restored whatever happened above: a failed case must not leave a tool switched off.
            config(['agrivisit.tools.enabled' => $original]);
        }

        $row['counts_after'] = $this->counts();
        $row['db_unchanged'] = $row['counts_after'] === $row['counts_before'];

        // A refusal carries no data; UNEXPECTED_RESPONSE in particular must not leak the malformed payload.
        $noData = $row['data_returned'] === [];

        $row['status'] = $row['returned'] === $case['expected'] && $row['db_unchanged'] && $noData ? 'pass' : 'fail';

        return $row;
    }

    /** @return array{visits: int, followups: int, tool_approvals: int} */
    private function counts(): array
    {
        return [
            'visits'         => Visit::count(),
            'followups'      => Followup::count(),
            'tool_approvals' => ToolApproval::count(),
        ];
    }

    private function lastCallId(ToolCallLog $log, string $tool): ?string
    {
        $entries = $log->entries();
        $last    = end($entries);

        return $last !== false && ($last['tool'] ?? null) === $tool ? ($last['call_id'] ?? null) : null;
    }

    private function write(string $path, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $block = self::BEGIN . "\n" . $this->automated($rows) . "\n" . self::END;

        $existing = is_file($path) ? file_get_contents($path) : null;

        if ($existing !== null && str_contains($existing, self::BEGIN) && str_contains($existing, self::END)) {
            $start   = strpos($existing, self::BEGIN);
            $end     = strpos($existing, self::END) + strlen(self::END);
            $content = substr($existing, 0, $start) . $block . substr($existing, $end);
        } else {
            $content = $this->template($rows, $block);
        }

        file_put_contents($path, $content);
        file_put_contents($this->jsonPath($path), json_encode([
            'generated_at' => now()->toIso8601String(),
            'cases'        => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function jsonPath(string $path): string
    {
        return preg_replace('/\.md$/', '', $path) . '.json';
    }

    private function automated(array $rows): string
    {
        $passed = count(array_filter($rows, fn ($r) => $r['status'] === 'pass'));
        $lines  = [
            '## Person-caused evidence (automated)',
            '',
            sprintf('Generated %s by `php artisan agrivisit:evidence` through the real `ToolInvoker`. **%d of %d cases pass.** Caller for every case: **person** (no model involved).',
                now()->format('Y-m-d H:i T'), $passed, count($rows)),
            '',
            'Counts are `visits` / `followups` / `tool_approvals`, taken immediately before and after each call.',
            '',
            '| # | Condition | Expected | Returned | Approved flag | DB before → after | Tool-log `call_id` | Result |',
            '|---|---|---|---|---|---|---|---|',
        ];

        foreach ($rows as $r) {
            $lines[] = sprintf('| %d | %s | `%s` | `%s` | %s | %s → %s | `%s` | %s |',
                $r['case'], $r['condition'], $r['expected'], $r['returned'] ?? '—',
                $r['approved'] ? 'true' : 'false',
                implode('/', $r['counts_before']), implode('/', $r['counts_after'] ?? []),
                $r['call_id'] ?? '—',
                match ($r['status']) { 'pass' => '✅ pass', 'skipped' => '⏭ skipped', default => '❌ FAIL' });
        }

        $lines[] = '';

        foreach ($rows as $r) {
            $lines[] = "### Case {$r['case']} — {$r['condition']} (`{$r['expected']}`)";
            $lines[] = '';
            $lines[] = "- **Tried:** {$r['how']}";
            $lines[] = '- **Arguments:** `' . json_encode($r['arguments'], JSON_UNESCAPED_SLASHES) . '`' . ($r['approved'] ? ' with `approved: true`' : '');
            $lines[] = '- **Came back:** `' . ($r['returned'] ?? '—') . '`' . ($r['message'] ? ' — ' . str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $r['message']) : '');
            $lines[] = '- **Data returned to the caller:** ' . ($r['data_returned'] === null ? '—' : '`' . json_encode($r['data_returned']) . '`');
            $lines[] = '- **Written:** ' . ($r['db_unchanged'] ? 'nothing' : '**counts changed**') . ' (' . implode('/', $r['counts_before']) . ' → ' . implode('/', $r['counts_after'] ?? []) . ')';
            $lines[] = '- **Log:** ' . ($r['call_id'] ? "`storage/app/tool-logs/" . now()->format('Y-m-d') . ".jsonl`, call_id `{$r['call_id']}`" : 'no gate entry');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** First run only: the hand-maintained sections around the automated block. */
    private function template(array $rows, string $block): string
    {
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[$r['expected']][] = $r['status'] === 'pass';
        }
        $person = fn (string $code) => isset($byCode[$code]) && ! in_array(false, $byCode[$code], true) ? 'Yes' : 'No';

        return <<<MD
        # Week 4 — Failure and Authorization Evidence

        Deliverable for the brief's requirement: *test failure paths: missing parameters, unauthorized
        requests, unavailable services, unexpected tool responses.* Plus two conditions the project
        promised in Week 1: unlisted tool calls (Story 11) and approval for higher-impact actions (AI
        Boundary Matrix).

        ## Summary

        | Condition | Code | Person-caused | Model-caused | Source |
        |---|---|---|---|---|
        | Missing or invalid parameters | `INVALID_ARGUMENTS` | {$person('INVALID_ARGUMENTS')} | _pending_ | Brief |
        | Unauthorized requests | `UNAUTHORIZED` | {$person('UNAUTHORIZED')} | _pending_ | Brief |
        | Unavailable services | `SERVICE_UNAVAILABLE` | {$person('SERVICE_UNAVAILABLE')} | _pending_ | Brief |
        | Unexpected tool responses | `UNEXPECTED_RESPONSE` | {$person('UNEXPECTED_RESPONSE')} | No — see closing note | Brief |
        | Unlisted tool calls | `TOOL_NOT_FOUND` | {$person('TOOL_NOT_FOUND')} | _pending_ | Week 1, Story 11 |
        | Higher-impact action without approval | `APPROVAL_REQUIRED` | {$person('APPROVAL_REQUIRED')} | _pending_ | Week 1, AI Boundary Matrix |
        | Subject does not exist | `RESOURCE_NOT_FOUND` | {$person('RESOURCE_NOT_FOUND')} | No — see closing note | Tool design |

        {$block}

        ## Model-caused evidence (recorded by hand)

        _Run through the Ask panel per section 4 of the Phase 4 brief; record the model's own wording._

        ## Screenshots

        _Under `evidence/screenshots/week4/`._

        ## Test instrumentation: the probe tools

        `faulty_probe` and `malformed_probe` are **test instrumentation, not product behaviour**.

        MD;
    }
}
