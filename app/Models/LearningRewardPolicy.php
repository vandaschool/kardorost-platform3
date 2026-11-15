<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningRewardPolicy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['revision' => 0, 'enabled' => false];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'enabled' => 'boolean'];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(LearningRewardVersion::class, 'current_version_id');
    }
}
