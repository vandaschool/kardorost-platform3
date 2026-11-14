<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagePurchase extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending_payment'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'definition' => 'array', 'expires_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
