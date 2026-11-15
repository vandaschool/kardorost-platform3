<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachProfileSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['profile_revision' => 'integer', 'definition' => 'encrypted:array', 'note' => 'encrypted', 'consented_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
