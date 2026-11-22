<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscussionPost extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'hidden' => false];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'hidden' => 'boolean'];
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(CourseDiscussion::class, 'course_discussion_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DiscussionAttachment::class)->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DiscussionPostRevision::class);
    }
}
