<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = ['cohort_id', 'title', 'instructions', 'max_score', 'is_published', 'available_at', 'due_at', 'closes_at'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'available_at' => 'datetime', 'due_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function available(): bool
    {
        return $this->is_published && (! $this->available_at || $this->available_at->lessThanOrEqualTo(now()));
    }

    public function open(): bool
    {
        return $this->available() && (! $this->closes_at || $this->closes_at->isFuture());
    }
}
