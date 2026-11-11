<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cohort extends Model
{
    use HasFactory;

    protected $fillable = ['course_id', 'title', 'format', 'capacity', 'starts_at', 'registration_open'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'registration_open' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
