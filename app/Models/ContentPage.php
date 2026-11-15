<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentPage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 1, 'current_version' => 0, 'available' => false];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'current_version' => 'integer', 'available' => 'boolean'];
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ContentPublication::class);
    }

    public function currentPublication(): HasOne
    {
        return $this->hasOne(ContentPublication::class)->latestOfMany('number');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ContentSchedule::class);
    }

    public function pendingSchedule(): HasOne
    {
        return $this->hasOne(ContentSchedule::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', 'pending'));
    }

    public function publicPath(): string
    {
        return match ($this->kind) {
            'home' => '/',
            'article' => '/journal/'.$this->slug,
            default => '/pages/'.$this->slug,
        };
    }
}
