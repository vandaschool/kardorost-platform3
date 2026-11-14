<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'captured_at' => 'datetime', 'attempt_expires_at' => 'datetime'];
    }

    public function packagePurchase(): BelongsTo
    {
        return $this->belongsTo(PackagePurchase::class);
    }

    public function organizationInvoice(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvoice::class);
    }

    public function organizationAllocation(): HasOne
    {
        return $this->hasOne(OrganizationPaymentAllocation::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
