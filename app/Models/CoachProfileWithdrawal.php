<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachProfileWithdrawal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['profile_revision' => 'integer', 'reason' => 'encrypted'];
    }
}
