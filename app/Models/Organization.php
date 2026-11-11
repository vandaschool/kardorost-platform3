<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'status'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('kind')->withTimestamps();
    }
}
