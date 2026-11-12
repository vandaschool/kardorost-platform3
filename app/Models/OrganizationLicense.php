<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationLicense extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['active' => true, 'revision' => 1];

    protected function casts(): array
    {
        return ['seat_limit' => 'integer', 'revision' => 'integer', 'active' => 'boolean', 'expires_on' => 'date:Y-m-d'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(OrganizationSeat::class);
    }
}
