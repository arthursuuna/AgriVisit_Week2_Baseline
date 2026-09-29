<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Human-readable citation, shown beneath a checklist item. */
    public function citation(): string
    {
        $section = $this->section ? ", {$this->section}" : '';

        return "{$this->document->title}{$section} [{$this->chunk_ref}]";
    }
}
