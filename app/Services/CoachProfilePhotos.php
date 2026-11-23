<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CoachProfile;
use App\Models\CoachProfilePhoto;
use App\Models\User;
use App\Notifications\CoachProfilePhotoWithdrawn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CoachProfilePhotos
{
    public const CONSENT = 'این عکس متعلق به من است و مجوز استفاده از آن را دارم. نسخهٔ آمادهٔ پروفایل به‌صورت خصوصی ثبت می‌شود. نمایش عمومی فقط با انتخاب عکس در پروفایل، رضایت من برای همان نسخه و بررسی ادمین انجام می‌شود.';

    private function fail(string $message, string $field = 'photo'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function reference(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (! is_array($input) || array_diff(array_keys($input), ['key', 'sha256']) !== [] || ! is_string($input['key'] ?? null) || ! Str::isUuid($input['key']) || ! is_string($input['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $input['sha256'])) {
            $this->fail('عکس انتخاب‌شده معتبر نیست؛ از عکس‌های خودتان انتخاب کنید.');
        }

        return ['key' => strtolower($input['key']), 'sha256' => $input['sha256']];
    }

    public function metadata(CoachProfilePhoto $photo): ?array
    {
        try {
            $m = $photo->definition;
            if (! is_array($m) || ($m['purpose'] ?? null) !== 'coach_profile_photo' || ($m['photo_id'] ?? null) !== $photo->id || ($m['user_id'] ?? null) !== $photo->user_id || ($m['key'] ?? null) !== $photo->public_key || ($m['request_key'] ?? null) !== $photo->request_key || ($m['path'] ?? null) !== $photo->file_path || ($m['consent_version'] ?? null) !== 'coach_profile_photo_v1' || ($m['consent_text'] ?? null) !== self::CONSENT || ! $photo->consented_at || ($m['consented_at'] ?? null) !== $photo->consented_at->toISOString() || ($m['processing'] ?? null) !== 'bounded_rgb_png_v1') {
                return null;
            }
            foreach (['title', 'file_name', 'sha256', 'path'] as $field) {
                if (! is_string($m[$field] ?? null) || ! mb_check_encoding($m[$field], 'UTF-8')) {
                    return null;
                }
            }
            if (($m['mime'] ?? null) !== 'image/png' || ! preg_match('/^[a-f0-9]{64}$/D', $m['sha256']) || ! is_int($m['size'] ?? null) || $m['size'] < 1 || $m['size'] > 4194304 || ! is_int($m['width'] ?? null) || ! is_int($m['height'] ?? null) || min($m['width'], $m['height']) < 1 || max($m['width'], $m['height']) > 1024 || ! preg_match('~^coach-profile-photos/'.$photo->id.'/[a-f0-9-]{36}\.png$~D', $m['path'])) {
                return null;
            }

            return $m;
        } catch (Throwable) {
            return null;
        }
    }

    public function withdrawal(CoachProfilePhoto $photo): ?array
    {
        try {
            $w = $photo->withdrawal;
            if (! $photo->withdrawn_at || ! is_array($w) || ($w['photo_id'] ?? null) !== $photo->id || ($w['user_id'] ?? null) !== $photo->user_id || ($w['actor_id'] ?? null) !== $photo->withdrawn_by || ($w['revision'] ?? null) !== $photo->revision || ($w['at'] ?? null) !== $photo->withdrawn_at->toISOString() || ! is_string($w['reason'] ?? null) || ! mb_check_encoding($w['reason'], 'UTF-8')) {
                return null;
            }

            return $w;
        } catch (Throwable) {
            return null;
        }
    }

    private function audit(User $actor, CoachProfilePhoto $photo, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'coach_profile_photo.'.$action, 'subject_type' => 'coach_profile_photo', 'subject_id' => $photo->id, 'metadata' => ['revision' => $photo->revision]]);
    }

    /** Accounts are locked by ID before a profile or photo row. */
    private function accounts(User $actor, int $ownerId, bool $write): array
    {
        $query = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id');
        $users = ($write ? $query->lockForUpdate() : $query->sharedLock())->get()->keyBy('id');
        $current = $users->get($actor->id);
        abort_unless($current?->is_active && ($current->id === $ownerId || $current->hasRole('admin')), 403);
        $owner = $users->get($ownerId);
        abort_unless($owner, 404);

        return [$current, $owner];
    }

    public function upload(User $actor, array $input, UploadedFile $file): CoachProfilePhoto
    {
        abort_unless(app(CoachProfiles::class)->eligible(User::findOrFail($actor->id)), 403);
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'consent' => 'required|accepted', 'request_key' => 'required|uuid'])->validate();
        if (trim($data['title']) === '' || ! mb_check_encoding($data['title'], 'UTF-8') || str_contains($data['title'], "\0")) {
            $this->fail('عنوان معتبر برای عکس لازم است.', 'title');
        }
        $prepared = app(ProfilePhotoImages::class)->prepare($file);
        $attributes = ['title' => trim($data['title']), ...$prepared['metadata']];
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $data, $prepared, $attributes, &$path): CoachProfilePhoto {
                [$actor] = $this->accounts($actor, $actor->id, true);
                abort_unless(app(CoachProfiles::class)->eligible($actor), 403);
                $existing = CoachProfilePhoto::where('user_id', $actor->id)->where('request_key', strtolower($data['request_key']))->first();
                if ($existing) {
                    $m = $this->metadata($existing);
                    if (! $m || array_intersect_key($m, $attributes) !== $attributes) {
                        $this->fail('این شناسه برای عکس دیگری استفاده شده است؛ فرم تازه باز کنید.', 'request_key');
                    }

                    return $existing;
                }
                if (CoachProfilePhoto::where('user_id', $actor->id)->count() >= 20) {
                    $this->fail('سقف ۲۰ عکس در سابقهٔ این حساب رسیده است.');
                }
                $stamp = now()->startOfSecond();
                $photo = CoachProfilePhoto::create(['user_id' => $actor->id, 'public_key' => (string) Str::uuid(), 'request_key' => strtolower($data['request_key']), 'consented_at' => $stamp]);
                $path = 'coach-profile-photos/'.$photo->id.'/'.Str::uuid().'.png';
                if (! Storage::disk('learning')->put($path, $prepared['bytes'])) {
                    $this->fail('نسخهٔ عکس ذخیره نشد؛ دوباره تلاش کنید.', 'file');
                }
                $photo->update(['file_path' => $path, 'definition' => [...$attributes, 'purpose' => 'coach_profile_photo', 'photo_id' => $photo->id, 'user_id' => $actor->id, 'key' => $photo->public_key, 'request_key' => $photo->request_key, 'path' => $path, 'consent_version' => 'coach_profile_photo_v1', 'consent_text' => self::CONSENT, 'consented_at' => $stamp->toISOString(), 'processing' => 'bounded_rgb_png_v1']]);
                $this->audit($actor, $photo, 'uploaded');

                return $photo;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk('learning')->delete($path);
            }
            throw $e;
        }
    }

    /** Profile callers already hold current owner and optional admin account locks. */
    public function validateReference(User $owner, mixed $input, bool $checkBytes = false): void
    {
        $ref = $this->reference($input);
        if ($ref === null) {
            return;
        }
        $photo = CoachProfilePhoto::where('public_key', $ref['key'])->lockForUpdate()->first();
        $m = $photo ? $this->metadata($photo) : null;
        if (! $photo || ! $m || $photo->user_id !== $owner->id || $photo->withdrawn_at || ! hash_equals($m['sha256'], $ref['sha256'])) {
            $this->fail('عکس در دسترس نیست؛ یک عکس فعال از حساب خودتان انتخاب کنید.');
        }
        if ($checkBytes) {
            try {
                $prepared = app(RasterImages::class)->open($photo->file_path, $m, $photo->public_key.'.png');
                fclose($prepared['stream']);
            } catch (Throwable) {
                $this->fail('نسخهٔ عکس معتبر یا در دسترس نیست؛ عکس دیگری انتخاب کنید.');
            }
        }
    }

    public function withdraw(User $actor, CoachProfilePhoto $input, int $revision, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $input, $revision, $reason): void {
            $ownerId = CoachProfilePhoto::whereKey($input->id)->firstOrFail()->user_id;
            [$actor, $owner] = $this->accounts($actor, $ownerId, true);
            $photo = CoachProfilePhoto::whereKey($input->id)->lockForUpdate()->firstOrFail();
            abort_unless($photo->user_id === $ownerId, 404);
            if ($photo->revision !== $revision || $photo->withdrawn_at) {
                $this->fail('عکس تغییر کرده یا قبلاً برداشته شده است؛ صفحه را تازه کنید.');
            }
            $owns = $actor->id === $ownerId;
            $data = Validator::make(['reason' => is_string($reason) ? trim($reason) : $reason], ['reason' => ($owns ? 'nullable' : 'required').'|string|min:3|max:2000'])->validate();
            $note = trim($data['reason'] ?? '') ?: 'انصراف صاحب عکس از استفاده.';
            if (! mb_check_encoding($note, 'UTF-8')) {
                $this->fail('دلیل معتبر وارد کنید.', 'reason');
            }
            $stamp = now()->startOfSecond();
            $photo->update(['withdrawn_at' => $stamp, 'withdrawn_by' => $actor->id, 'revision' => $photo->revision + 1, 'withdrawal' => ['photo_id' => $photo->id, 'user_id' => $ownerId, 'actor_id' => $actor->id, 'revision' => $photo->revision + 1, 'at' => $stamp->toISOString(), 'reason' => $note]]);
            $this->audit($actor, $photo, 'withdrawn');
            if (! $owns) {
                $owner->notify(new CoachProfilePhotoWithdrawn);
            }
        });
    }

    public function privateFile(User $actor, string $key): array
    {
        return DB::transaction(function () use ($actor, $key): array {
            $ownerId = CoachProfilePhoto::where('public_key', $key)->firstOrFail()->user_id;
            $this->accounts($actor, $ownerId, false);
            $photo = CoachProfilePhoto::where('public_key', $key)->sharedLock()->firstOrFail();
            abort_unless($photo->user_id === $ownerId && ! $photo->withdrawn_at, 404);
            $m = $this->metadata($photo);
            abort_unless($m, 404);

            try {
                return app(RasterImages::class)->open($photo->file_path, $m, $photo->public_key.'.png');
            } catch (Throwable) {
                abort(404);
            }
        });
    }

    public function publicImage(CoachProfile $profile): ?array
    {
        $definition = app(CoachProfiles::class)->publishedDefinition($profile);
        if (! isset($definition['photo']) || ! $profile->public_enabled || ! app(CoachProfiles::class)->eligible(User::findOrFail($profile->user_id))) {
            return null;
        }
        try {
            $ref = $this->reference($definition['photo']);
            $photo = $ref ? CoachProfilePhoto::where('public_key', $ref['key'])->first() : null;
            $m = $photo ? $this->metadata($photo) : null;
            if (! $photo || ! $m || $photo->user_id !== $profile->user_id || $photo->withdrawn_at || ! hash_equals($m['sha256'], $ref['sha256'])) {
                return null;
            }

            return ['url' => route('public.mentor-photo', ['slug' => $profile->slug, 'key' => $photo->public_key, 'sha' => $m['sha256']]), 'width' => $m['width'], 'height' => $m['height']];
        } catch (Throwable) {
            return null;
        }
    }

    public function publicFile(string $slug, string $key, string $sha): array
    {
        return DB::transaction(function () use ($slug, $key, $sha): array {
            $initial = CoachProfile::where('slug', $slug)->firstOrFail();
            $owner = User::whereKey($initial->user_id)->sharedLock()->firstOrFail();
            abort_unless(app(CoachProfiles::class)->eligible($owner), 404);
            $profile = CoachProfile::whereKey($initial->id)->sharedLock()->firstOrFail();
            abort_unless($profile->user_id === $owner->id && $profile->slug === $slug && $profile->public_enabled, 404);
            $definition = app(CoachProfiles::class)->publishedDefinition($profile);
            try {
                $ref = $this->reference($definition['photo'] ?? null);
            } catch (ValidationException) {
                abort(404);
            }
            abort_unless($ref && $ref['key'] === $key && hash_equals($ref['sha256'], $sha), 404);
            $photo = CoachProfilePhoto::where('public_key', $key)->sharedLock()->firstOrFail();
            $m = $this->metadata($photo);
            abort_unless($m && $photo->user_id === $owner->id && ! $photo->withdrawn_at && hash_equals($m['sha256'], $sha), 404);

            try {
                return app(RasterImages::class)->open($photo->file_path, $m, $photo->public_key.'.png');
            } catch (Throwable) {
                abort(404);
            }
        });
    }

    public function listing(User $actor, int $ownerId): array
    {
        return DB::transaction(function () use ($actor, $ownerId): array {
            $this->accounts($actor, $ownerId, false);
            $profile = CoachProfile::where('user_id', $ownerId)->with('currentPublication')->first();
            $public = $profile ? $this->publicImage($profile) : null;
            $publicRef = $public ? $profile->currentPublication->definition['photo'] : null;

            return CoachProfilePhoto::where('user_id', $ownerId)->latest('id')->get()->map(function (CoachProfilePhoto $photo) use ($public, $publicRef): array {
                $m = $this->metadata($photo);

                return [...$photo->only('id', 'public_key', 'revision', 'consented_at', 'withdrawn_at'), 'metadata' => $m ? array_intersect_key($m, array_flip(['title', 'file_name', 'mime', 'size', 'sha256', 'width', 'height'])) : null, 'withdrawal' => $this->withdrawal($photo), 'public_url' => $publicRef && $publicRef['key'] === $photo->public_key ? $public['url'] : null];
            })->all();
        });
    }
}
