<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'array', 'revision' => 'integer', 'public_consent_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
