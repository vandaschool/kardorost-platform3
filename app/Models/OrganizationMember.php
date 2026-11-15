<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

class OrganizationMember extends Pivot
{
    protected static function booted(): void
    {
        static::creating(function (OrganizationMember $membership) {
            $membership->membership_key ??= (string) Str::uuid();
        });
    }
}
