<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'integer', 'sessions' => 'integer', 'session_minutes' => 'integer', 'valid_days' => 'integer', 'revision' => 'integer', 'published' => 'boolean', 'manual_payment_enabled' => 'boolean'];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
