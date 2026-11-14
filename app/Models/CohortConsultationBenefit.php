<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CohortConsultationBenefit extends Model
{
    protected $guarded = [];

    protected $attributes = ['enabled' => true, 'revision' => 1];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'revision' => 'integer', 'sessions' => 'integer', 'session_minutes' => 'integer', 'valid_days' => 'integer'];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
