<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Installment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_date' => 'date:Y-m-d', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(PaymentAllocation::class);
    }

    public function overdue(): bool
    {
        return ! $this->paid_at && ! $this->voided_at && $this->due_date->copy()->endOfDay()->isPast();
    }
}
