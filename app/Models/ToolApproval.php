<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An officer's decision on a held write call: approved, rejected or expired.
 */
class ToolApproval extends Model
{
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EXPIRED  = 'expired';

    public const ORIGIN_MODEL  = 'model';
    public const ORIGIN_MANUAL = 'manual';

    protected $guarded = [];

    protected $casts = [
        'arguments'     => 'array',
        'preview_shown' => 'array',
        'decided_at'    => 'datetime',
    ];
}
