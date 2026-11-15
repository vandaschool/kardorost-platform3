<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachProfilePublication extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['number' => 'integer', 'definition' => 'encrypted:array', 'consented_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
