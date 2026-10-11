<?php

namespace App\Services\AgentTask;

use App\Models\AgentRunRecord;
use App\Models\ToolApproval;
use App\Services\Agent\AgentRun;
use App\Services\Agent\BoundedAgentLoop;
use App\Services\Agent\StopReason;
use App\Services\Tools\ApprovalOutcome;
use App\Services\Tools\ApprovalService;
use App\Services\Tools\PendingCallStore;
use App\Services\Tools\ToolInvoker;
use Illuminate\Support\Carbon;
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
     * outcome and the updated record; a token that is not this run's, or is
     * already used, changes nothing.
     *
     * @return array{0: ApprovalOutcome, 1: AgentRunRecord}
     */
    public function decide(AgentRunRecord $record, string $token, string $decision, ?string $reason): array
    {
        if ($record->status !== StopReason::AWAITING_APPROVAL || ($record->approval['token'] ?? null) !== $token) {
            return [new ApprovalOutcome(ApprovalOutcome::UNKNOWN), $record];
        }

        $started = hrtime(true);

        // The Week 4 flow: single-use token, expiry, record written first.
        $outcome = $this->approvals->decide($token, $decision, $reason, function (array $pending) {
            return $this->invoker->invoke($pending['tool'], $pending['arguments'], true);
        });

        $ms = round((hrtime(true) - $started) / 1e6, 2);

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
     */
    public function closeIfLapsed(AgentRunRecord $record): AgentRunRecord
    {
        $token     = $record->approval['token'] ?? null;
        $expiresAt = $record->approval['expires_at'] ?? null;

        if ($record->status !== StopReason::AWAITING_APPROVAL || $token === null || $expiresAt === null
            || now()->getTimestamp() <= $expiresAt || $this->pendingCalls->get($token) !== null) {
            return $record;
        }

        // The Week 4 path for a decision on an expired token: it writes the
        // EXPIRED row to tool_approvals and never reaches the executor.
        $outcome = $this->approvals->decide($token, 'reject', null, function () {
            throw new LogicException('A lapsed approval cannot be executed.');
        });

        if ($outcome->status === ApprovalOutcome::ALREADY_USED) {
            // Decided by the officer in the meantime; that path has closed it.
            return $record->fresh();
        }

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
