<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'slug', 'description', 'stage', 'price', 'is_published', 'coach_id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'price' => 'integer'];
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }
}
