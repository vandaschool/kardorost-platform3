<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareerResume extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['draft', 'title'];

    protected $attributes = ['revision' => 1, 'current_version' => 0, 'archived' => false];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'draft' => 'encrypted:array', 'revision' => 'integer', 'current_version' => 'integer', 'archived' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ResumeVersion::class);
    }
}
