<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['answers'];

    protected $attributes = ['revision' => 1, 'status' => 'in_progress'];

    protected function casts(): array
    {
        return ['answers' => 'encrypted:array', 'revision' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(SurveyPublication::class, 'survey_publication_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
