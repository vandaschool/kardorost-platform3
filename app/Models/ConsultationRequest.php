<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'version' => 1];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'resolved_at' => 'datetime'];
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
