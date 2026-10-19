<?php

namespace App\Services\Memory;

use App\Models\FarmMemory;
use App\Services\Tools\ApprovalOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records what the officer decided about a farm. The only writer of
 * farm_memories, called only by AgentRunner when a decision on a held call
 * resolves. There is no tool that writes memory: the model never chooses what
 * the system remembers.
 *
 * Every entry is built from the decision itself: the held call's own
 * arguments, the tool_approvals record and the officer's reason. The summary
 * is the gate's approval summary (system text, not model text); the reason is
 * stored exactly as the officer wrote it and is never edited.
 */
class FarmMemoryRecorder
{
    /**
     * The arguments memory keeps, per tool: schema-constrained values only
     * (IDs, a date, an enum). Free text the model composed (a visit's
     * purpose, a follow-up's notes) is left out, so it is never replayed into
     * a later run. The full call stays in tool_approvals, the approval audit.
     */
    public const KEPT_ARGUMENTS = [
        'schedule_visit' => ['farm_id', 'visit_date'],
        'log_followup'   => ['farm_id', 'visit_id', 'outcome'],
    ];

    /**
     * An approval, a rejection or an expiry reported by the Week 4 flow.
     * ALREADY_USED records nothing: someone else's decision already did.
     *
     * @param array $pending   the run's held call: tool, arguments, summary
     * @param int   $expiresAt when the approval window closed (for a lapse)
     */
    public function decided(ApprovalOutcome $outcome, string $runId, array $pending, int $expiresAt): ?FarmMemory
    {
        $record = $outcome->record;

        return match ($outcome->status) {
            ApprovalOutcome::APPROVED => $this->write(FarmMemory::APPROVED, $runId, $pending, $record->decided_at, [
                'approval_ref' => $record->approval_ref,
                'result_code'  => $record->result_code,
                'result_ref'   => $record->result_ref,
            ], $record->result_ref !== null
                ? "Approved: {$this->what($pending)} Created {$record->result_ref} ({$record->approval_ref})."
                : "Approved: {$this->what($pending)} It failed when executed ({$record->result_code}), so nothing was written ({$record->approval_ref})."),
            ApprovalOutcome::REJECTED => $this->write(FarmMemory::DECLINED, $runId, $pending, $record->decided_at, [
                'approval_ref'   => $record->approval_ref,
                'officer_reason' => $record->reject_reason,
            ], "Declined: {$this->what($pending)} Nothing was written ({$record->approval_ref})."),
            ApprovalOutcome::EXPIRED, ApprovalOutcome::UNKNOWN => $this->lapsed($runId, $pending, $expiresAt, $record?->approval_ref),
            default => null,
        };
    }

    /** A held call whose window closed without a decision. */
    public function lapsed(string $runId, array $pending, int $expiresAt, ?string $approvalRef = null): FarmMemory
    {
        return $this->write(FarmMemory::LAPSED, $runId, $pending, Carbon::createFromTimestamp($expiresAt, config('app.timezone')),
            ['approval_ref' => $approvalRef],
            "Lapsed: {$this->what($pending)} The officer never decided, so nothing was written.");
    }

    private function write(string $kind, string $runId, array $pending, Carbon $occurredAt, array $details, string $summary): FarmMemory
    {
        return DB::transaction(function () use ($kind, $runId, $pending, $occurredAt, $details, $summary) {
            $memory = FarmMemory::create([
                'memory_ref'  => 'pending-' . $runId . '-' . uniqid(),
                'farm_id'     => (string) $pending['arguments']['farm_id'],
                'officer_id'  => (string) config('agrivisit.officer.id'),
                'run_id'      => $runId,
                'kind'        => $kind,
                'summary'     => $summary,
                'details'     => ['tool' => $pending['tool'], 'arguments' => array_intersect_key($pending['arguments'],
                    array_flip(self::KEPT_ARGUMENTS[$pending['tool']] ?? ['farm_id']))] + $details,
                'occurred_at' => $occurredAt,
            ]);

            // Derived from the row id, as approval, visit and follow-up references are.
            $memory->update(['memory_ref' => sprintf('M-%04d', $memory->id)]);

            return $memory;
        });
    }

    /** "Schedule a visit to UG-KYA-012 on 2026-10-20." — the gate's own summary of the call. */
    private function what(array $pending): string
    {
        return rtrim((string) $pending['summary'], '. ') . '.';
    }
}
