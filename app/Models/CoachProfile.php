<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachProfile extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'state' => 'draft', 'public_enabled' => false];

    protected function casts(): array
    {
        return ['draft' => 'encrypted:array', 'revision' => 'integer', 'public_enabled' => 'boolean', 'public_published_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function currentPublication(): BelongsTo
    {
        return $this->belongsTo(CoachProfilePublication::class, 'current_publication_id');
    }

    public function latestSubmission(): BelongsTo
    {
        return $this->belongsTo(CoachProfileSubmission::class, 'latest_submission_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CoachProfileSubmission::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(CoachProfilePublication::class);
    }
}
