<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'requested', 'version' => 1];

    protected $hidden = ['destination', 'file_path', 'file_hash', 'transfer_key'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'version' => 'integer', 'destination' => 'encrypted', 'transfer_date' => 'date:Y-m-d', 'reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class);
    }
}
