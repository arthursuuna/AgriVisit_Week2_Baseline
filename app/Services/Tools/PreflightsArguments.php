<?php

namespace App\Services\Tools;

/**
 * Checks a JSON schema cannot express: a date in the past, a subject that does
 * not exist.
 *
 * ToolInvoker runs this before the approval step, so an officer is never asked
 * to approve a call that is bound to fail. Tools run the same check again
 * inside execute(), because an approved call may be replayed later.
 */
interface PreflightsArguments
{
    /** Null when the call may proceed, otherwise the failure to return. */
    public function preflight(array $args): ?ToolResult;
}
