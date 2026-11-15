<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRewardEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'integer', 'lesson_title' => 'encrypted', 'cohort_title' => 'encrypted', 'earned_at' => 'datetime'];
    }
}
