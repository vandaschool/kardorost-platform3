<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationInvitationImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rows' => 'array', 'phones' => 'array', 'result' => 'array', 'invalid_count' => 'integer', 'duplicate_count' => 'integer', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
