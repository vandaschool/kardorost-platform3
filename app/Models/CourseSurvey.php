<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSurvey extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'current_version' => 0, 'available' => false];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'current_version' => 'integer', 'available' => 'boolean'];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SurveyPublication::class);
    }
}
