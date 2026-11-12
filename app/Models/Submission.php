<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SubmissionReview::class)->orderByDesc('revision');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
