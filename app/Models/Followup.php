<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Followup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
