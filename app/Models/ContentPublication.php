<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPublication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'number' => 'integer', 'published_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }
}
