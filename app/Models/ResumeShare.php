<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeShare extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['definition', 'request_key', 'token_hash', 'revocation_reason'];

    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'revocation_reason' => 'encrypted:array', 'include_contacts' => 'boolean', 'duration_days' => 'integer', 'revision' => 'integer', 'consented_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(CareerResume::class, 'career_resume_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ResumeVersion::class, 'resume_version_id');
    }
}
