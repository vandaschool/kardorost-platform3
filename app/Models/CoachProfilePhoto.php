<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoachProfilePhoto extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'withdrawal' => 'encrypted:array', 'revision' => 'integer', 'consented_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
