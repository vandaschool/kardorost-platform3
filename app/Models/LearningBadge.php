<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningBadge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'goal_points' => 'integer', 'points_at_award' => 'integer', 'earned_at' => 'datetime'];
    }
}
