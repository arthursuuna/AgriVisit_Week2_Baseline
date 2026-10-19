<?php

namespace App\Services\AgentTask;

use App\Models\AgentRunRecord;
use App\Models\ToolApproval;
use App\Services\Agent\AgentRun;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\Agent\StopReason;
use App\Services\Memory\FarmMemoryRecorder;
use App\Services\Tools\ApprovalOutcome;
use App\Services\Tools\ApprovalService;
use App\Services\Tools\PendingCallStore;
use App\Services\Tools\ToolInvoker;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Starts agent runs and carries them across the officer's decision.
 *
 * One approval flow: the Week 4 ApprovalService holds the pending call (token,
 * summary, preview, expiry) and records the decision in tool_approvals. On
 * approval it executes the call through ToolInvoker with approval, exactly as
 * the Week 4 console does; this class then hands the result to the loop to
 * continue. Neither the loop nor this class ever approves anything; approval
 * is only ever the officer's decision on a held token.
 */
class AgentRunner
{
    /**
     * Every closure for a lapsed approval opens with this, the two existing
     * expiry-on-decision messages included, so the views can tell an expired
     * run from one the officer declined (both stop as REJECTED).
     */
    public const EXPIRED_PREFIX = 'The approval ';

    public function __construct(
        private readonly AgentToolset $toolset,
        private readonly AgentRunStore $store,
        private readonly ApprovalService $approvals,
        private readonly ToolInvoker $invoker,
        private readonly PendingCallStore $pendingCalls,
        private readonly FarmMemoryRecorder $memory,
    ) {
    }

    public function start(string $goal, ?int $maxIterations = null): AgentRunRecord
    {
        if ($maxIterations !== null) {
            // This process only; the loop is bound, not a singleton, so it is
            // built with the override, and the run carries it across a resume.
            config(['agrivisit.agent.max_iterations' => $maxIterations]);
        }

        $run = app(BoundedAgentLoop::class)->run(
            $goal, $this->toolset->declarations(), $this->toolset->systemPrompt(), $this->toolset->promptVersion()
        );

        return $this->store->save($run, $this->holdIfPending($run));
    }

    /**
     * The officer's decision on the call a run is waiting for. Returns the
     * outcome and the updated record; a token that is not this run's changes
     * nothing.
     *
     * A decision on a run that is not waiting (closed, already decided, or
     * moved by another request since $record was read) throws
     * IllegalRunTransition before the token is touched.
     *
     * @return array{0: ApprovalOutcome, 1: AgentRunRecord}
     */
    public function decide(AgentRunRecord $record, string $token, string $decision, ?string $reason): array
    {
        return $this->exclusively($record, fn (AgentRunRecord $record) => $this->decideHeld($record, $token, $decision, $reason));
    }

    private function decideHeld(AgentRunRecord $record, string $token, string $decision, ?string $reason): array
    {
        RunState::check(RunState::forStatus($record->status),
            $decision === 'approve' ? AgentRun::RUNNING : StopReason::REJECTED, $record->run_id);

        if (($record->approval['token'] ?? null) !== $token) {
            return [new ApprovalOutcome(ApprovalOutcome::UNKNOWN), $record];
        }

        $started = hrtime(true);

        // The Week 4 flow: single-use token, expiry, record written first.
        $outcome = $this->approvals->decide($token, $decision, $reason, function (array $pending) {
            return $this->invoker->invoke($pending['tool'], $pending['arguments'], true);
        });

        $ms = round((hrtime(true) - $started) / 1e6, 2);

        // Week 6: the decision is a fact about the farm, recorded here and
        // nowhere else, from the held call itself. Before the resume, so a
        // run that fails afterwards still leaves the decision remembered.
        $this->memory->decided($outcome, $record->run_id, $record->pending, (int) $record->approval['expires_at']);

        $run  = $this->store->restore($record);
        $loop = app(BoundedAgentLoop::class);

        $run = match ($outcome->status) {
            ApprovalOutcome::APPROVED => $loop->resumeApproved($run, $outcome->toolResult, $outcome->record?->approval_ref, $ms),
            ApprovalOutcome::REJECTED => $loop->reject($run, 'The officer declined the proposed action'
                . ($outcome->record?->reject_reason ? ': ' . rtrim($outcome->record->reject_reason, '.!? ') . '.' : '.')
                . ' The run is closed; a different outcome needs a new run.'),
            ApprovalOutcome::EXPIRED  => $loop->reject($run, 'The approval expired before the officer decided, so nothing was written.'
                . ' The run is closed; start a new run to propose it again.'),
            // The token is this run's but the store no longer has it: the Week 4
            // store keeps held calls for a day, so a decision after that finds
            // nothing. Left alone, the run would wait forever; it is expired.
            ApprovalOutcome::UNKNOWN  => $loop->reject($run, 'The approval is no longer on file (held calls are kept for a day), so it has expired and nothing was written.'
                . ' The run is closed; start a new run to propose it again.'),
            default                   => null,
        };

        if ($run === null) {
            return [$outcome, $record];
        }

        return [$outcome, $this->store->save($run, $this->holdIfPending($run))];
    }

    /**
     * Closes a run whose approval window passed with no decision. Expiry is
     * otherwise only evaluated when a decision is submitted, so an abandoned
     * run would show as waiting forever; this runs whenever a run is read for
     * display. The closure is written, not just displayed: the run is over,
     * and the record, the trace and the approval audit should all say so.
     *
     * Two page loads, or a page load and the officer's decision, can try to
     * move the same run at once. The loser's IllegalRunTransition is logged
     * as a warning and the page shows the run as the winner left it.
     */
    public function closeIfLapsed(AgentRunRecord $record): AgentRunRecord
    {
        $token     = $record->approval['token'] ?? null;
        $expiresAt = $record->approval['expires_at'] ?? null;

        if ($record->status !== StopReason::AWAITING_APPROVAL || $token === null || $expiresAt === null
            || now()->getTimestamp() <= $expiresAt || $this->pendingCalls->get($token) !== null) {
            return $record;
        }

        try {
            return $this->exclusively($record, fn (AgentRunRecord $record) => $this->closeLapsedHeld($record, $token, $expiresAt));
        } catch (IllegalRunTransition $e) {
            Log::warning('Lazy expiry lost a race: ' . $e->getMessage(), ['run_id' => $record->run_id]);

            return $record->fresh();
        }
    }

    private function closeLapsedHeld(AgentRunRecord $record, string $token, int $expiresAt): AgentRunRecord
    {
        RunState::check(RunState::forStatus($record->status), StopReason::REJECTED, $record->run_id);

        // The Week 4 path for a decision on an expired token: it writes the
        // EXPIRED row to tool_approvals and never reaches the executor.
        $outcome = $this->approvals->decide($token, 'reject', null, function () {
            throw new LogicException('A lapsed approval cannot be executed.');
        });

        if ($outcome->status === ApprovalOutcome::ALREADY_USED) {
            // Consumed outside this run while it held the lock: decided on
            // the Week 4 console, or by a resume that crashed after consuming
            // it. Nothing here can tell which, so the run is left as it is.
            return $record->fresh();
        }

        $this->memory->decided($outcome, $record->run_id, $record->pending, $expiresAt);

        $closed = Carbon::createFromTimestamp($expiresAt)->timezone(config('app.timezone'))->format('j M Y H:i');

        $run = app(BoundedAgentLoop::class)->reject($this->store->restore($record),
            self::EXPIRED_PREFIX . "expired at {$closed} without a decision: the request was never decided and nothing was written."
            . ' The run is closed; start a new run to propose it again.');

        return $this->store->save($run, null);
    }

    /** closeIfLapsed() for every run still marked as waiting. */
    public function closeLapsed(): void
    {
        AgentRunRecord::where('status', StopReason::AWAITING_APPROVAL)->get()->each(
            fn (AgentRunRecord $record) => $this->closeIfLapsed($record)
        );
    }

    /**
     * Runs $move with this run to itself. The lock makes one request the
     * winner; the run is re-read under it, so a request working from a copy
     * read before another request moved the run loses too. Either way the
     * loser throws before it has consumed a token, called the model or
     * written a trace.
     *
     * @template T
     * @param Closure(AgentRunRecord): T $move
     * @return T
     */
    private function exclusively(AgentRunRecord $record, Closure $move): mixed
    {
        // As long as the longest request may live (AgentController's time limit).
        $seconds = (int) config('agrivisit.agent.max_runtime_seconds') + 2 * (int) config('agrivisit.llm.timeout') + 10;
        $lock    = Cache::lock("agent-run:{$record->run_id}", $seconds);

        if (! $lock->get()) {
            throw IllegalRunTransition::lostRace($record->run_id, 'it is being moved by a request still in progress');
        }

        try {
            $fresh = $record->fresh() ?? throw IllegalRunTransition::lostRace($record->run_id, 'it is no longer stored');

            if ($fresh->status !== $record->status || (int) $fresh->segment !== (int) $record->segment) {
                throw IllegalRunTransition::lostRace($record->run_id,
                    "read as {$record->status} at segment {$record->segment}, now {$fresh->status} at segment {$fresh->segment}");
            }

            return $move($fresh);
        } finally {
            $lock->release();
        }
    }

    /** A run that stopped on a write is held in the Week 4 flow for the officer. */
    private function holdIfPending(AgentRun $run): ?array
    {
        if ($run->status !== StopReason::AWAITING_APPROVAL || $run->pending === null) {
            return null;
        }

        return $this->approvals->hold(
            $run->pending + ['run_id' => $run->runId],
            (string) $run->pending['summary'],
            ToolApproval::ORIGIN_MODEL,
            $run->runId,
            $run->goal,
        );
    }
}
