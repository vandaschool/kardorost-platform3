<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'draft', 'revision' => 1, 'recipient_count' => 0];

    protected function casts(): array
    {
        return ['audience' => 'encrypted:array', 'title' => 'encrypted', 'message' => 'encrypted', 'stop_reason' => 'encrypted', 'roles' => 'array', 'revision' => 'integer', 'recipient_count' => 'integer', 'published_at' => 'datetime', 'dispatched_at' => 'datetime', 'finished_at' => 'datetime', 'next_scheduled_for' => 'datetime'];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(AnnouncementSchedule::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }
}
