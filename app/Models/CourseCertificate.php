<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCertificate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'issued', 'revision' => 1, 'public_enabled' => false];

    protected function casts(): array
    {
        return ['public_enabled' => 'boolean', 'public_consent_at' => 'datetime', 'public_withdrawn_at' => 'datetime', 'definition' => 'array', 'evidence' => 'array', 'revision' => 'integer', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
