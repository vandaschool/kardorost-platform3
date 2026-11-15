<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsDeliveryAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['lease_token'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'code' => 'integer', 'finished_at' => 'datetime'];
    }
}
