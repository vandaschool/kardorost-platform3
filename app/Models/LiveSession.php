<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveSession extends Model
{
    use HasFactory;

    protected $fillable = ['cohort_id', 'title', 'provider', 'meeting_url', 'starts_at', 'ends_at', 'is_published', 'recording_kind', 'recording_url'];

    protected $hidden = ['meeting_url', 'recording_url'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function joinable(): bool
    {
        return $this->is_published && now()->greaterThanOrEqualTo($this->starts_at->copy()->subMinutes(15)) && now()->lessThanOrEqualTo($this->ends_at);
    }
}
