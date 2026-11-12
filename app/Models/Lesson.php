<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = ['course_section_id', 'title', 'body', 'media_kind', 'media_url', 'duration_seconds', 'position', 'is_published', 'available_at'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'available_at' => 'datetime'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function available(): bool
    {
        return $this->is_published && (! $this->available_at || $this->available_at->lessThanOrEqualTo(now()));
    }
}
