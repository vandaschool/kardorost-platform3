<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachCredentialEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'reason' => 'encrypted:array'];
    }

    public function reasonText(): ?string
    {
        try {
            $reason = $this->reason;
            if ($reason === null) {
                return null;
            }
            if (! is_array($reason) || ($reason['credential_id'] ?? null) !== $this->coach_credential_id || ($reason['actor_id'] ?? null) !== $this->actor_id || ($reason['revision'] ?? null) !== $this->revision || ! (is_string($reason['text'] ?? null) || ($reason['text'] ?? null) === null)) {
                return 'یادداشت این رویداد قابل نمایش نیست.';
            }

            return $reason['text'] ?? null;
        } catch (\Throwable) {
            return 'یادداشت این رویداد قابل نمایش نیست.';
        }
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(CoachCredential::class, 'coach_credential_id');
    }
}
