<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cohort extends Model
{
    use HasFactory;

    protected $fillable = ['course_id', 'title', 'format', 'capacity', 'starts_at', 'registration_open', 'installment_enabled', 'manual_payment_enabled'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'registration_open' => 'boolean', 'installment_enabled' => 'boolean', 'manual_payment_enabled' => 'boolean'];
    }

    public function installmentPlan(): HasMany
    {
        return $this->hasMany(CohortInstallment::class)->orderBy('sequence');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
