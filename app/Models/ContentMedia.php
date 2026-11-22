<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentMedia extends Model
{
    use HasFactory;

    protected $table = 'content_media';

    protected $guarded = [];

    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'revision' => 'integer', 'published_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
