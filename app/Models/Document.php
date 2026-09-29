<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $guarded = [];

    protected $casts = [
        'crops'            => 'array',
        'removed_headings' => 'array',
        'retrieved_on'     => 'date',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
