<?php

namespace App\Services\AgentTask;

use App\Services\Agent\StopReason;
use LogicException;

/**
 * A run was asked to make a move its lifecycle does not allow (RunState), or
 * lost a race for the move to another request. Either way nothing changed:
 * the check runs before any model call, write or trace.
 */
final class IllegalRunTransition extends LogicException
{
    public static function between(string $runId, string $from, string $toStatus, string $why): self
    {
        return new self("Run {$runId} cannot move from {$from} to " . RunState::forStatus($toStatus)
            . (in_array($toStatus, StopReason::all(), true) && $toStatus !== RunState::forStatus($toStatus) ? " ({$toStatus})" : '')
            . ". {$why}");
    }

    /** Another request holds the run, or changed it after this one read it. */
    public static function lostRace(string $runId, string $detail): self
    {
        return new self("Run {$runId}: another request moved it first ({$detail}); this move was refused and nothing changed.");
    }
}
