<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\ResumeShare;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResumeSharing
{
    public const CONSENT = 'متن پیش‌نمایش همین نسخه را بازبینی کردم و با دسترسی هر فرد دارای لینک تا مهلت انتخاب‌شده یا قطع لینک موافقم. متن آزاد را از نظر اطلاعات شخصی بررسی کردم. گیرنده می‌تواند متن را کپی یا ذخیره کند و قطع لینک، نسخه‌های دریافت‌شده را پس نمی‌گیرد.';

    public function __construct(private ResumeBuilder $builder) {}

    private function fail(string $message, string $field = 'share'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** Caller retains these account and parent locks before a share lock. */
    public function context(User $actor, CareerResume $resume, ?ResumeVersion $version = null, bool $write = false): array
    {
        $ownerId = CareerResume::whereKey($resume->id)->firstOrFail(['id', 'user_id'])->user_id;
        $query = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id');
        $accounts = ($write ? $query->lockForUpdate() : $query->sharedLock())->get()->keyBy('id');
        $actor = $accounts->get($actor->id);
        abort_unless($actor?->is_active && ($actor->id === $ownerId || $actor->hasRole('admin')), 403);
        $query = CareerResume::whereKey($resume->id);
        $resume = ($write ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
        abort_unless($resume->user_id === $ownerId, 403);
        if ($version) {
            $version = ResumeVersion::whereKey($version->id)->sharedLock()->firstOrFail();
            abort_unless($version->career_resume_id === $resume->id, 404);
        }

        return [$actor, $resume, $version];
    }

    public function metadata(ResumeShare $share): ?array
    {
        try {
            $data = $share->definition;
            if (! is_array($data) || ($data['share_id'] ?? null) !== $share->id || ($data['user_id'] ?? null) !== $share->user_id || ($data['resume_id'] ?? null) !== $share->career_resume_id || ($data['version_id'] ?? null) !== $share->resume_version_id
                || ($data['include_contacts'] ?? null) !== $share->include_contacts || ($data['duration_days'] ?? null) !== $share->duration_days || ($data['expires_timestamp'] ?? null) !== $share->expires_at->timestamp || ($data['consented_timestamp'] ?? null) !== $share->consented_at->timestamp
                || ($data['consent_version'] ?? null) !== 'public_resume_link_v1' || ($data['consent_text'] ?? null) !== self::CONSENT || ! is_int($data['version_number'] ?? null) || $data['version_number'] < 1
                || ! is_string($data['source_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $data['source_hash']) || ! is_string($data['token'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $data['token']) || ! hash_equals($share->token_hash, hash('sha256', $data['token'])) || ! is_array($data['snapshot'] ?? null)) {
                return null;
            }
            $snapshot = $this->builder->definition($data['snapshot']);
            if ($snapshot !== $data['snapshot'] || (! $share->include_contacts && ($snapshot['email'] !== '' || $snapshot['phone'] !== '' || $snapshot['links'] !== []))) {
                return null;
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    public function matchesSource(array $data, ResumeVersion $version, array $source): bool
    {
        if (! $data['include_contacts']) {
            $source = [...$source, 'email' => '', 'phone' => '', 'links' => []];
        }

        return $data['version_number'] === $version->number && hash_equals($data['source_hash'], $version->content_hash) && $data['snapshot'] === $source;
    }

    public function reason(ResumeShare $share): ?string
    {
        try {
            $data = $share->revocation_reason;

            return is_array($data) && ($data['share_id'] ?? null) === $share->id && ($data['actor_id'] ?? null) === $share->revoked_by && is_string($data['text'] ?? null) ? $data['text'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function status(ResumeShare $share): string
    {
        return $share->revoked_at ? 'revoked' : ($share->expires_at->isFuture() ? 'active' : 'expired');
    }

    private function audit(User $actor, ResumeShare $share, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'resume_share.'.$action, 'subject_type' => 'resume_share', 'subject_id' => $share->id, 'metadata' => ['resume_id' => $share->career_resume_id, 'version_id' => $share->resume_version_id, 'revision' => $share->revision, 'include_contacts' => $share->include_contacts, 'duration_days' => $share->duration_days]]);
    }

    public function publish(User $actor, CareerResume $resume, ResumeVersion $version, array $input): ResumeShare
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'revision' => 'required|integer|min:1', 'duration_days' => 'required|integer|in:7,30,90', 'include_contacts' => 'required|boolean', 'reviewed' => 'required|accepted', 'consent' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($actor, $resume, $version, $data): ResumeShare {
            [$actor, $resume, $version] = $this->context($actor, $resume, $version, true);
            abort_unless($actor->id === $resume->user_id && $actor->phone_verified_at, 403);
            $snapshot = $this->builder->snapshotDefinition($version);
            $existing = ResumeShare::where('user_id', $actor->id)->where('request_key', strtolower($data['request_key']))->first();
            if ($existing) {
                if ($existing->career_resume_id !== $resume->id || $existing->resume_version_id !== $version->id || $existing->duration_days !== (int) $data['duration_days'] || $existing->include_contacts !== (bool) $data['include_contacts'] || ! $this->metadata($existing)) {
                    $this->fail('این شناسه برای درخواست دیگری استفاده شده است؛ فرم تازه باز کنید.', 'request_key');
                }

                return $existing;
            }
            if ($resume->archived || $resume->revision !== (int) $data['revision']) {
                $this->fail('رزومه تغییر کرده یا بایگانی شده است؛ صفحه را تازه و دوباره بازبینی کنید.', 'revision');
            }
            if (ResumeShare::where('resume_version_id', $version->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                $this->fail('این نسخه لینک فعال دارد؛ برای تغییر تنظیمات ابتدا همان لینک را قطع کنید.');
            }
            if (! $data['include_contacts']) {
                $snapshot = [...$snapshot, 'email' => '', 'phone' => '', 'links' => []];
            }
            $consented = now();
            $expires = $consented->copy()->addDays((int) $data['duration_days']);
            $token = bin2hex(random_bytes(32));
            $share = ResumeShare::create(['user_id' => $actor->id, 'career_resume_id' => $resume->id, 'resume_version_id' => $version->id, 'request_key' => strtolower($data['request_key']), 'token_hash' => hash('sha256', $token), 'definition' => [], 'include_contacts' => (bool) $data['include_contacts'], 'duration_days' => (int) $data['duration_days'], 'consented_at' => $consented, 'expires_at' => $expires]);
            $share->update(['definition' => ['share_id' => $share->id, 'user_id' => $actor->id, 'resume_id' => $resume->id, 'version_id' => $version->id, 'version_number' => $version->number, 'source_hash' => $version->content_hash, 'include_contacts' => $share->include_contacts, 'duration_days' => $share->duration_days, 'consented_timestamp' => $consented->timestamp, 'expires_timestamp' => $expires->timestamp, 'consent_version' => 'public_resume_link_v1', 'consent_text' => self::CONSENT, 'token' => $token, 'snapshot' => $snapshot]]);
            $this->audit($actor, $share, 'published');

            return $share;
        }, 3);
    }

    private function revokeLocked(User $actor, ResumeShare $share, string $reason): void
    {
        $share->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revision' => $share->revision + 1, 'revocation_reason' => ['share_id' => $share->id, 'actor_id' => $actor->id, 'text' => $reason]]);
        $this->audit($actor, $share, 'revoked');
    }

    public function revoke(User $actor, ResumeShare $share, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:1000'])->validate();
        DB::transaction(function () use ($actor, $share, $data): void {
            $resume = CareerResume::findOrFail($share->career_resume_id);
            [$actor, $resume] = $this->context($actor, $resume, write: true);
            $share = ResumeShare::whereKey($share->id)->lockForUpdate()->firstOrFail();
            abort_unless($share->career_resume_id === $resume->id && $share->user_id === $resume->user_id, 403);
            if ($share->revision !== (int) $data['revision'] || $share->revoked_at) {
                $this->fail('وضعیت لینک تغییر کرده است؛ صفحه را تازه کنید.', 'revision');
            }
            $reason = trim($data['reason'] ?? '');
            if ($actor->id !== $share->user_id && $reason === '') {
                $this->fail('دلیل قطع لینک را برای صاحب رزومه بنویسید.', 'reason');
            }
            $this->revokeLocked($actor, $share, $reason ?: 'قطع لینک توسط صاحب رزومه');
        }, 3);
    }

    /** Called only with the parent and current account locks held by ResumeBuilder. */
    public function revokeForArchive(User $actor, CareerResume $resume): void
    {
        foreach (ResumeShare::where('career_resume_id', $resume->id)->whereNull('revoked_at')->orderBy('id')->lockForUpdate()->lazyById(200) as $share) {
            $this->revokeLocked($actor, $share, 'قطع لینک هم‌زمان با بایگانی رزومه');
        }
    }

    public function publicPayload(string $token): array
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $initial = ResumeShare::where('token_hash', hash('sha256', $token))->firstOrFail(['id', 'user_id', 'career_resume_id', 'resume_version_id']);

        return DB::transaction(function () use ($initial, $token): array {
            $owner = User::whereKey($initial->user_id)->sharedLock()->firstOrFail();
            $resume = CareerResume::whereKey($initial->career_resume_id)->sharedLock()->firstOrFail();
            $version = ResumeVersion::whereKey($initial->resume_version_id)->sharedLock()->firstOrFail();
            $share = ResumeShare::whereKey($initial->id)->sharedLock()->firstOrFail();
            abort_unless($owner->is_active && $owner->phone_verified_at && ! $resume->archived && $resume->user_id === $owner->id && $version->career_resume_id === $resume->id
                && $share->user_id === $owner->id && $share->career_resume_id === $resume->id && $share->resume_version_id === $version->id && ! $share->revoked_at && $share->expires_at->isFuture() && hash_equals($share->token_hash, hash('sha256', $token)), 404);
            $data = $this->metadata($share);
            abort_unless($data && hash_equals($data['token'], $token), 404);
            try {
                $source = $this->builder->snapshotDefinition($version);
            } catch (ValidationException) {
                abort(404);
            }
            abort_unless($this->matchesSource($data, $version, $source), 404);

            return ['definition' => $data['snapshot'], 'version' => $data['version_number'], 'expires_at' => $share->expires_at->timezone('Asia/Tehran')->toIso8601String()];
        }, 3);
    }
}
