<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationInvoice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected function casts(): array
    {
        return ['lines' => 'array', 'amount' => 'integer', 'revision' => 'integer', 'due_date' => 'date:Y-m-d', 'issued_at' => 'datetime', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(OrganizationLicense::class, 'organization_license_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
