<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'number' => 'integer'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
