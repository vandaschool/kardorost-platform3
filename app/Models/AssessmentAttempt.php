<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'status' => 'in_progress'];

    protected $hidden = ['answers', 'result'];

    protected function casts(): array
    {
        return ['answers' => 'encrypted:array', 'result' => 'encrypted:array', 'revision' => 'integer', 'completed_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(AssessmentVersion::class, 'assessment_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
