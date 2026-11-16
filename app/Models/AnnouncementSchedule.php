<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementSchedule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'source_revision' => 'integer', 'announcement_revision' => 'integer', 'scheduled_for' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
