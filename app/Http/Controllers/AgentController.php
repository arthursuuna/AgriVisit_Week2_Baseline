<?php

namespace App\Http\Controllers;

use App\Models\AgentRunRecord;
use App\Services\AgentTask\AgentRunner;
use App\Services\Tools\ApprovalOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Browser front end for the Week 5 agent.
 *
 * Runs are synchronous: the page posts a goal, the run finishes (10-25 s),
 * and the run page renders it. Approval reuses the Week 4 approval panel and
 * ApprovalService, and nothing here grants approval: the Approve button posts
 * the held token, exactly as on the Week 4 console, and proposing and
 * executing never happen in the same request.
 */
class AgentController extends Controller
{
    public function __construct(private readonly AgentRunner $runner)
    {
    }

    public function index(): View
    {
        $this->runner->closeLapsed();

        return view('agent.index', [
            'recent' => AgentRunRecord::orderByDesc('id')->limit(5)->get(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $goal = trim((string) $request->input('goal', ''));

        if ($goal === '') {
            return redirect()->route('agent.index')->withErrors(['goal' => 'Type a goal first.']);
        }

        // A run can use its whole runtime budget plus one slow model call
        // (the budget is checked between iterations), so the request must
        // outlive that; the HTTP client still times out first on each call.
        set_time_limit((int) config('agrivisit.agent.max_runtime_seconds') + 2 * (int) config('agrivisit.llm.timeout') + 10);

        $record = $this->runner->start(mb_substr($goal, 0, 1000));

        return redirect()->route('agent.runs.show', $record->run_id);
    }

    public function show(AgentRunRecord $run): View
    {
        return view('agent.run', [
            'run'    => $this->runner->closeIfLapsed($run),
            'expiry' => (int) config('agrivisit.tools.approval_expiry_minutes'),
        ]);
    }

    public function runs(): View
    {
        $this->runner->closeLapsed();

        return view('agent.runs', [
            'runs' => AgentRunRecord::orderByDesc('id')->paginate(50),
        ]);
    }

    /** The officer's decision, posted from the reused Week 4 approval panel. */
    public function decide(Request $request, AgentRunRecord $run): RedirectResponse
    {
        set_time_limit((int) config('agrivisit.agent.max_runtime_seconds') + 2 * (int) config('agrivisit.llm.timeout') + 10);

        [$outcome, $record] = $this->runner->decide(
            $run,
            (string) $request->input('token', ''),
            $request->input('decision') === 'approve' ? 'approve' : 'reject',
            $request->input('reason'),
        );

        return redirect()->route('agent.runs.show', $record->run_id)->with('decision', [
            'status'       => $outcome->status,
            'approval_ref' => $outcome->record?->approval_ref,
            'result_code'  => $outcome->record?->result_code,
            'result_ref'   => $outcome->record?->result_ref,
            'reason'       => $outcome->record?->reject_reason,
        ]);
    }

    /** A trace file of this run, by name; only names the run recorded are served. */
    public function trace(AgentRunRecord $run, string $file): BinaryFileResponse
    {
        abort_unless(in_array($file, $run->trace_files ?? [], true), 404);

        $path = rtrim(config('agrivisit.agent.trace_path'), '/\\') . DIRECTORY_SEPARATOR . $file;
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/json']);
    }

    /** Words for a decision banner. */
    public static function decisionText(array $d): array
    {
        return match ($d['status']) {
            ApprovalOutcome::APPROVED => ['ok', "Approved — recorded as {$d['approval_ref']}."
                . ($d['result_ref'] ? " Created {$d['result_ref']}." : (($d['result_code'] ?? 'OK') !== 'OK'
                    ? " The tool did not complete ({$d['result_code']}); nothing was written." : ''))
                . ' The run continued from where it paused.'],
            ApprovalOutcome::REJECTED => ['notice', "Rejected — recorded as {$d['approval_ref']}. Nothing was written and the run is closed."],
            ApprovalOutcome::EXPIRED  => ['refused', "The approval expired — recorded as {$d['approval_ref']}. Nothing was written and the run is closed."],
            default                   => ['notice', 'That approval was already used or does not belong to this run. Nothing changed.'],
        };
    }
}
