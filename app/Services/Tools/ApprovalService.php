<?php

namespace App\Services\Tools;

use App\Models\Followup;
use App\Models\ToolApproval;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Holds a write call for an officer's decision and records that decision.
 *
 * The manual tool path and the assistant path both go through here, so they
 * behave the same way: the officer sees a summary and a preview, the token is
 * single-use and expires, and approve, reject and expiry each leave a
 * tool_approvals row. The row is written before the tool executes and updated
 * with the outcome afterwards, so the decision survives a crash.
 *
 * Identity is configured, not authenticated (config agrivisit.officer).
 */
class ApprovalService
{
    public function __construct(
        private readonly PendingCallStore $store,
        private readonly ApprovalPreview $preview,
        private readonly ToolCallLog $toolLog,
    ) {
    }

    /**
     * Holds a call the gate returned as APPROVAL_REQUIRED.
     *
     * $pending carries at least 'tool' and 'arguments'; anything else (the
     * model turn, the call id) is kept for the executor. The summary and
     * preview are fixed here and stored, so the record holds exactly what the
     * panel showed.
     *
     * @return array{token: string, tool: string, arguments: array, summary: string, preview: list<array>, origin: string, expires_at: int}
     */
    public function hold(array $pending, string $summary, string $origin, ?string $sessionId = null, ?string $request = null): array
    {
        $pending += [
            'summary'      => $summary,
            'preview'      => $this->preview->for($pending['tool'], $pending['arguments']),
            'request'      => $request,
            'gate_call_id' => $this->gateCallId($pending['tool'], $pending['arguments']),
        ];

        $token = $this->store->put($pending, $origin, $sessionId);
        $entry = $this->store->get($token);

        return [
            'token'      => $token,
            'tool'       => $pending['tool'],
            'arguments'  => $pending['arguments'],
            'summary'    => $pending['summary'],
            'preview'    => $pending['preview'],
            'origin'     => $origin,
            'expires_at' => $entry['expires_at'],
        ];
    }

    /**
     * @param string   $decision 'approve' or 'reject' ('cancel' is read as reject)
     * @param callable $execute  fn (array $pending): ToolResult|SessionResult, called only on approval
     */
    public function decide(string $token, string $decision, ?string $reason, callable $execute): ApprovalOutcome
    {
        $entry = $this->store->consume($token);

        if ($entry === null) {
            // A decided token already has its row; an unknown one has nothing to record.
            return new ApprovalOutcome($this->store->wasConsumed($token)
                ? ApprovalOutcome::ALREADY_USED : ApprovalOutcome::UNKNOWN);
        }

        $reason = ($reason = trim((string) $reason)) === '' ? null : $reason;

        if ($entry['expired']) {
            return new ApprovalOutcome(ApprovalOutcome::EXPIRED,
                $this->record($token, $entry, ToolApproval::EXPIRED, $reason), $entry);
        }

        if ($decision !== 'approve') {
            return new ApprovalOutcome(ApprovalOutcome::REJECTED,
                $this->record($token, $entry, ToolApproval::REJECTED, $reason), $entry);
        }

        // Recorded first: if execution dies, the decision is still on file.
        $record   = $this->record($token, $entry, ToolApproval::APPROVED, null);
        $executed = $execute($entry['pending']);
        $result   = $executed instanceof SessionResult ? $executed->toolResult : $executed;

        $ref = $result?->ok ? ($result->data['visit_id'] ?? $result->data['entry_id'] ?? null) : null;

        $record->update([
            'result_code' => $result === null ? null : ($result->code ?? 'OK'),
            'result_ref'  => $ref,
        ]);

        if ($ref !== null) {
            $this->stamp($entry['pending']['tool'], $ref, $record->approval_ref);
        }

        return new ApprovalOutcome(ApprovalOutcome::APPROVED, $record->refresh(), $entry, $executed, $result);
    }

    public function expiryMinutes(): int
    {
        return $this->store->expiryMinutes();
    }

    private function record(string $token, array $entry, string $decision, ?string $reason): ToolApproval
    {
        $pending = $entry['pending'];

        return DB::transaction(function () use ($token, $entry, $pending, $decision, $reason) {
            $record = ToolApproval::create([
                'approval_ref'  => 'pending-' . Str::uuid(),
                'pending_token' => $token,
                'session_id'    => $entry['session_id'],
                'call_id'       => $pending['gate_call_id'] ?? $pending['call_id'] ?? null,
                'tool_name'     => $pending['tool'],
                'arguments'     => $pending['arguments'],
                'summary_shown' => $pending['summary'],
                'preview_shown' => $pending['preview'],
                'origin'        => $entry['origin'],
                'decision'      => $decision,
                'decided_by'    => (string) config('agrivisit.officer.id'),
                'decided_at'    => now(),
                'reject_reason' => $reason,
            ]);

            // Derived from the row id, as visit and follow-up references are.
            $record->update(['approval_ref' => sprintf('A-%04d', $record->id)]);

            return $record;
        });
    }

    /** Points the written row back at the decision that authorised it. */
    private function stamp(string $tool, string $ref, string $approvalRef): void
    {
        $officer = (string) config('agrivisit.officer.id');

        match ($tool) {
            'schedule_visit' => Visit::where('visit_ref', $ref)
                ->update(['approval_ref' => $approvalRef, 'created_by' => $officer]),
            'log_followup'   => Followup::where('entry_ref', $ref)
                ->update(['approval_ref' => $approvalRef]),
            default          => null,
        };
    }

    /**
     * The gate's call id for the proposing call. ToolInvoker writes it only to
     * the tool log, so it is read back from the newest matching entry.
     */
    private function gateCallId(string $tool, array $args): ?string
    {
        foreach (array_reverse($this->toolLog->entries()) as $entry) {
            if (($entry['tool'] ?? null) === $tool
                && ($entry['outcome'] ?? null) === ToolResult::APPROVAL_REQUIRED
                && ($entry['arguments'] ?? null) == $args) {
                return $entry['call_id'] ?? null;
            }
        }

        return null;
    }
}
