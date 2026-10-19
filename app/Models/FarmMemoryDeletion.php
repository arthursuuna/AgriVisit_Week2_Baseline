<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The audit of one deleted memory entry (table farm_memory_deletions): which
 * entry, which farm, who deleted it and when. Never what it said.
 */
class FarmMemoryDeletion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];
}
