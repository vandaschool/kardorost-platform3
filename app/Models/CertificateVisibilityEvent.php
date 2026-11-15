<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateVisibilityEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'revision' => 'integer', 'consented_at' => 'datetime'];
    }
}
