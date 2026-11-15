<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyPublication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'accepting' => true];

    protected function casts(): array
    {
        return ['definition' => 'array', 'number' => 'integer', 'revision' => 'integer', 'accepting' => 'boolean', 'opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(CourseSurvey::class, 'course_survey_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function receiving(): bool
    {
        return $this->accepting && (! $this->opens_at || $this->opens_at->lte(now())) && (! $this->closes_at || $this->closes_at->gt(now()));
    }
}
