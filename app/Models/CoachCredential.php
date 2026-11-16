<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachCredential extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['definition', 'file_path', 'request_key'];

    protected $attributes = ['status' => 'submitted', 'revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'file_path' => 'encrypted', 'revision' => 'integer', 'consented_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CoachCredentialEvent::class);
    }
}
