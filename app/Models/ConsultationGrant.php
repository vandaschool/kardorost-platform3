<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConsultationGrant extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['active' => true, 'revision' => 1, 'cancellation_policy' => 'before_start_full_credit_v1'];

    public function packagePurchase(): HasOne
    {
        return $this->hasOne(PackagePurchase::class);
    }

    public function courseBenefit(): HasOne
    {
        return $this->hasOne(EnrollmentConsultationBenefit::class);
    }

    protected function casts(): array
    {
        return ['total_sessions' => 'integer', 'session_minutes' => 'integer', 'revision' => 'integer', 'active' => 'boolean', 'valid_until' => 'date:Y-m-d'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(ConsultationGrantUse::class);
    }

    public function remaining(): int
    {
        $spent = array_key_exists('spent_count', $this->attributes) ? (int) $this->spent_count : $this->uses()->whereNull('released_at')->count();

        return max(0, $this->total_sessions - $spent);
    }
}
