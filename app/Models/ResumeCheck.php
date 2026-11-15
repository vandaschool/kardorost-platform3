<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeCheck extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['job_text', 'job_hash', 'report'];

    protected function casts(): array
    {
        return ['job_text' => 'encrypted', 'report' => 'encrypted:array'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ResumeVersion::class, 'resume_version_id');
    }
}
