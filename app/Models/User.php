<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'phone', 'email', 'password', 'phone_verified_at'];

    protected $attributes = ['is_active' => true, 'sms_reminders_enabled' => true];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'phone_verified_at' => 'datetime', 'is_active' => 'boolean', 'sms_reminders_enabled' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->using(OrganizationMember::class)->withPivot('kind', 'membership_key')->withTimestamps();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('key', $role)->exists();
    }

    public function roleKeys(): array
    {
        return $this->roles()->pluck('key')->all();
    }
}
