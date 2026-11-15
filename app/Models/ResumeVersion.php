<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResumeVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['definition', 'content_hash'];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'number' => 'integer'];
    }

    public function resume(): BelongsTo
    {
        return $this->belongsTo(CareerResume::class, 'career_resume_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ResumeCheck::class);
    }
}
