<?php

namespace App\Models;

use App\Services\Agent\StopReason;
use App\Services\AgentTask\AgentRunner;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored working state of one agent run (table agent_runs).
 *
 * Named ...Record so it is not confused with App\Services\Agent\AgentRun,
 * the in-memory run the loop works on. AgentRunStore converts between them.
 */
class AgentRunRecord extends Model
{
    protected $table = 'agent_runs';

    protected $guarded = [];

    protected $casts = [
        'limits'         => 'array',
        'messages'       => 'array',
        'steps'          => 'array',
        'signatures'     => 'array',
        'pending'        => 'array',
        'declarations'   => 'array',
        'trace_files'    => 'array',
        'approval'       => 'array',
        'active_seconds' => 'float',
        'started_at'     => 'datetime',
        'ended_at'       => 'datetime',
    ];

    /** Closed because the approval window passed, not because the officer declined. */
    public function approvalExpired(): bool
    {
        return $this->stop_reason === StopReason::REJECTED
            && str_starts_with((string) $this->summary, AgentRunner::EXPIRED_PREFIX);
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }
}
