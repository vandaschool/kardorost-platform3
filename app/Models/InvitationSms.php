<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationSms extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['invitation_revision' => 'integer', 'dispatched_at' => 'datetime', 'attempted_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class, 'organization_invitation_id');
    }
}
