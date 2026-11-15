<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionPostRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'hidden' => 'boolean'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
