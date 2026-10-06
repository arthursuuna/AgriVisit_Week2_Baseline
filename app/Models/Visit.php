<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'visit_date' => 'date',
    ];

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }
}
