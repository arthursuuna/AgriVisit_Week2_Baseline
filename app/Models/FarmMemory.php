<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One decision the officer made about a farm (table farm_memories).
 * Written only by App\Services\Memory\FarmMemoryRecorder.
 */
class FarmMemory extends Model
{
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const LAPSED   = 'lapsed';

    /** An entry is never edited after it is written (Phase 3 dropped the column). */
    public const UPDATED_AT = null;

    /** The argument that holds the date a proposal was for, per tool, where it has one. */
    public const PROPOSED_DATE = ['schedule_visit' => 'visit_date'];

    protected $guarded = [];

    protected $casts = [
        'details'     => 'array',
        'occurred_at' => 'datetime',
    ];

    /** What the decision was about: the proposed date, not the date it was decided. */
    public function proposed(): ?string
    {
        $key = self::PROPOSED_DATE[$this->details['tool'] ?? ''] ?? null;

        return $key === null ? null : ($this->details['arguments'][$key] ?? null);
    }

    /** Entries the agent can still recall: inside the retention window. */
    public function scopeRecallable($query)
    {
        return $query->where('occurred_at', '>=', self::retentionCutoff());
    }

    public static function retentionCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subMonths((int) config('agrivisit.memory.retention_months'));
    }

    public function isRecallable(): bool
    {
        return $this->occurred_at->greaterThanOrEqualTo(self::retentionCutoff());
    }
}
