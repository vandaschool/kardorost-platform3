<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CertificatePolicy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['enabled' => true, 'revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'array', 'enabled' => 'boolean', 'revision' => 'integer'];
    }
}
