<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentReceipt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['file_path', 'file_hash'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d', 'reviewed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function claim(): HasOne
    {
        return $this->hasOne(ReceiptClaim::class);
    }
}
