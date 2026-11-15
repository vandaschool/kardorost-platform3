<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRewardVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'definition' => 'array'];
    }
}
