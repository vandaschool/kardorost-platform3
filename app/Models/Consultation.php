<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'confirmed', 'version' => 1];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'listed_amount' => 'integer', 'expires_at' => 'immutable_datetime', 'version' => 'integer', 'cancelled_at' => 'datetime'];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ConsultationSlot::class, 'consultation_slot_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(ConsultationGrant::class, 'consultation_grant_id');
    }
}
