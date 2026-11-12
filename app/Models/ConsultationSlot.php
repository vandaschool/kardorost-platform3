<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultationSlot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['published' => true, 'booked' => false, 'price' => 0];

    protected $hidden = ['meeting_url'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'published' => 'boolean', 'booked' => 'boolean'];
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
