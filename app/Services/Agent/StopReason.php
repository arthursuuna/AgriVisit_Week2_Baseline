<?php

namespace App\Services\Agent;

/**
 * Every way the bounded loop can stop. There is no exit from the loop that is
 * not one of these.
 */
final class StopReason
{
    /** The model answered without calling a tool. The task is done. */
    public const GOAL_REACHED = 'GOAL_REACHED';

    /** max_iterations reached. Partial results are handed to the officer. */
    public const ITERATION_CAP = 'ITERATION_CAP';

    /** The same tool was called with the same arguments twice. */
    public const NO_PROGRESS = 'NO_PROGRESS';

    /** max_consecutive_failures tool failures in a row; the agent is not recovering. */
    public const REPEATED_FAILURE = 'REPEATED_FAILURE';

    /** A write tool needs the officer. The run suspends here, nothing is written, and it resumes on approval. */
    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    /** Active-time budget exhausted: model and tool time only; time waiting for the officer is not counted. */
    public const RUNTIME_BUDGET = 'RUNTIME_BUDGET';

    /** The restricted-topic guard refused the goal before anything ran, or a query the agent composed. A refusal ends the run; it is never routed around. */
    public const GUARD_REFUSED = 'GUARD_REFUSED';

    /** The model could not be reached or returned an unusable reply. */
    public const MODEL_ERROR = 'MODEL_ERROR';

    /** The officer declined the proposed action, or the approval expired. Final: no re-proposal within the run. */
    public const REJECTED = 'REJECTED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::GOAL_REACHED, self::ITERATION_CAP, self::NO_PROGRESS, self::REPEATED_FAILURE,
            self::AWAITING_APPROVAL, self::RUNTIME_BUDGET, self::GUARD_REFUSED, self::MODEL_ERROR,
            self::REJECTED,
        ];
    }
}
