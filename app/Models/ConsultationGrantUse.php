<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationGrantUse extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }
}
