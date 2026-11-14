<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentConsultationBenefit extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'waiting'];

    protected function casts(): array
    {
        return ['billing_version' => 'integer', 'definition' => 'array', 'eligible_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
