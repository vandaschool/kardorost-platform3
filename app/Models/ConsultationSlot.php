<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationSlot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['published' => true, 'booked' => false];

    protected $hidden = ['meeting_url'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'published' => 'boolean', 'booked' => 'boolean'];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
