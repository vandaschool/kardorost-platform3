<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRedirect extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'enabled' => true];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'enabled' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    public function sourcePath(): string
    {
        return match ($this->kind) {
            'article' => '/journal/'.$this->from_slug,
            'page' => '/pages/'.$this->from_slug,
            default => '',
        };
    }
}
