<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseDiscussion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['kind' => 'question', 'revision' => 1, 'locked' => false, 'hidden' => false, 'pinned' => false];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'locked' => 'boolean', 'hidden' => 'boolean', 'pinned' => 'boolean', 'last_post_at' => 'datetime'];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(DiscussionPost::class);
    }
}
