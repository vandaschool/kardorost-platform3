<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 0, 'current_version' => 0, 'available' => false];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'current_version' => 'integer', 'available' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssessmentVersion::class);
    }
}
