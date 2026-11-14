<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrganizationInvitation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function latestSms(): HasOne
    {
        return $this->hasOne(InvitationSms::class)->latestOfMany();
    }
}
