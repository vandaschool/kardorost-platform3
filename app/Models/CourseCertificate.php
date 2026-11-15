<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCertificate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'issued', 'revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'array', 'evidence' => 'array', 'revision' => 'integer', 'issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
