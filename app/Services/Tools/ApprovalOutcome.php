<?php

namespace App\Services\Tools;

use App\Models\ToolApproval;

/**
 * What happened when an officer submitted a decision on a held call.
 *
 * $executed is whatever the executor returned: a ToolResult for a manual
 * call, a SessionResult for one the assistant proposed.
 */
final class ApprovalOutcome
{
    public const APPROVED     = 'approved';
    public const REJECTED     = 'rejected';
    public const EXPIRED      = 'expired';
    public const ALREADY_USED = 'already_used';
    public const UNKNOWN      = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly ?ToolApproval $record = null,
        public readonly ?array $entry = null,
        public readonly mixed $executed = null,
        public readonly ?ToolResult $toolResult = null,
    ) {
    }

    /** The officer's original request text, for re-asking after expiry. */
    public function request(): ?string
    {
        return $this->entry['pending']['request'] ?? null;
    }

    public function origin(): ?string
    {
        return $this->entry['origin'] ?? null;
    }

    public function tool(): ?string
    {
        return $this->entry['pending']['tool'] ?? null;
    }

    public function arguments(): array
    {
        return $this->entry['pending']['arguments'] ?? [];
    }
}
