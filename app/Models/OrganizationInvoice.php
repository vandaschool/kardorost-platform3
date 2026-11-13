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

    public function allocations(): HasMany
    {
        return $this->hasMany(OrganizationPaymentAllocation::class);
    }

    public function paidAmount(): int
    {
        return array_key_exists('allocations_sum_amount', $this->attributes)
            ? (int) $this->allocations_sum_amount
            : (int) $this->allocations()->sum('amount');
    }

    public function remainingAmount(): int
    {
        return max(0, $this->amount - $this->paidAmount());
    }
}
