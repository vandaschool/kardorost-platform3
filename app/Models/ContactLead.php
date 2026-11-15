<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactLead extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['request_key', 'phone_fingerprint'];

    protected $attributes = ['status' => 'new', 'revision' => 1];

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'phone' => 'encrypted', 'organization_name' => 'encrypted', 'message' => 'encrypted', 'revision' => 'integer', 'consented_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContactLeadEvent::class);
    }
}
