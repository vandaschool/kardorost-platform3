<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CoachCredential;
use App\Models\User;
use App\Notifications\CoachCredentialChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CoachCredentials
{
    public const CONSENT = 'فایل و اطلاعات این پرونده فقط در اختیار من و ادمین‌های کاردرست برای بررسی داخلی است. بارگذاری و بررسی داخلی، انتشار عمومی یا تأیید رسمی اعتبار مدرک نیست.';

    private function fail(string $message, string $field = 'credential'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function metadata(CoachCredential $credential): ?array
    {
        try {
            $data = $credential->definition;
            if (! is_array($data) || ($data['credential_id'] ?? null) !== $credential->id || ($data['user_id'] ?? null) !== $credential->user_id) {
                return null;
            }
            foreach (['title', 'issuer', 'note', 'file_name', 'mime', 'sha256'] as $key) {
                if (! is_string($data[$key] ?? null) || ! mb_check_encoding($data[$key], 'UTF-8')) {
                    return null;
                }
            }
            if (! is_int($data['size'] ?? null) || $data['size'] < 0 || $data['size'] > 4194304 || ! preg_match('/^[a-f0-9]{64}$/D', $data['sha256']) || ! in_array($data['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                return null;
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /** Caller keeps these sorted account locks before locking a credential. */
    public function lock(User $actor, ?CoachCredential $credential = null, bool $write = false, bool $adminIndex = false): array
    {
        $ownerId = $credential ? CoachCredential::whereKey($credential->id)->firstOrFail(['id', 'user_id'])->user_id : $actor->id;
        $query = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id');
        $accounts = ($write ? $query->lockForUpdate() : $query->sharedLock())->get()->keyBy('id');
        $actor = $accounts->get($actor->id);
        abort_unless($actor?->is_active, 403);
        $admin = $actor->hasRole('admin');
        abort_if($adminIndex && ! $admin, 403);
        if ($credential) {
            $query = CoachCredential::whereKey($credential->id);
            $credential = ($write ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
            abort_unless($credential->user_id === $ownerId && ($actor->id === $ownerId || $admin), 403);
        }

        return [$actor, $credential, $admin, $accounts->get($ownerId)];
    }

    private function audit(User $actor, CoachCredential $credential, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'coach_credential.'.$action, 'subject_type' => 'coach_credential', 'subject_id' => $credential->id, 'metadata' => ['revision' => $credential->revision, 'status' => $credential->status]]);
    }

    public function submit(User $actor, array $input, UploadedFile $file): CoachCredential
    {
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'issuer' => 'nullable|string|max:160', 'note' => 'nullable|string|max:1500', 'consent' => 'required|accepted', 'request_key' => 'required|uuid', 'file' => 'prohibited'])->validate();
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,png,jpg,jpeg|extensions:pdf,png,jpg,jpeg|max:4096'])->validate();
        $mime = $file->getMimeType();
        $extension = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
        $name = basename(str_replace(chr(92), '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        if (! $extension || ! is_string($name) || $name === '' || mb_strlen($name) > 255) {
            $this->fail('فایل PDF، PNG یا JPEG با نام معتبر انتخاب کنید.', 'file');
        }
        $definition = ['title' => trim($data['title']), 'issuer' => trim($data['issuer'] ?? ''), 'note' => trim($data['note'] ?? ''), 'file_name' => $name, 'mime' => $mime, 'size' => (int) $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
        if ($definition['title'] === '' || ! $definition['sha256']) {
            $this->fail('عنوان و فایل معتبر لازم است.', 'file');
        }
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $data, $definition, $extension, $file, &$path): CoachCredential {
                [$actor] = $this->lock($actor, write: true);
                abort_unless($actor->hasRole('coach'), 403);
                $existing = CoachCredential::where('user_id', $actor->id)->where('request_key', strtolower($data['request_key']))->first();
                if ($existing) {
                    $stored = $this->metadata($existing);
                    if (! $stored || array_intersect_key($stored, $definition) !== $definition) {
                        $this->fail('شناسه ارسال برای پرونده دیگری استفاده شده است؛ فرم تازه باز کنید.', 'request_key');
                    }

                    return $existing;
                }
                $credential = CoachCredential::create(['user_id' => $actor->id, 'request_key' => strtolower($data['request_key']), 'definition' => [...$definition, 'consent_version' => 'private_internal_review_v1', 'consent_text' => self::CONSENT], 'consented_at' => now()]);
                $path = 'coach-credentials/'.$actor->id.'/'.$credential->id.'/'.Str::uuid().'.'.$extension;
                if (! Storage::disk('learning')->putFileAs(dirname($path), $file, basename($path))) {
                    $this->fail('فایل خصوصی ذخیره نشد؛ دوباره تلاش کنید.', 'file');
                }
                $credential->update(['file_path' => $path, 'definition' => [...$credential->definition, 'credential_id' => $credential->id, 'user_id' => $actor->id]]);
                $credential->events()->create(['actor_id' => $actor->id, 'action' => 'submitted', 'to_status' => 'submitted', 'revision' => 1]);
                $this->audit($actor, $credential, 'submitted');

                return $credential;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('learning')->delete($path);
            }
            throw $exception;
        }
    }

    /** @return resource */
    private function verifiedFile(CoachCredential $credential, array $metadata): mixed
    {
        $stream = null;
        try {
            $path = $credential->file_path;
            abort_unless(is_string($path) && str_starts_with($path, 'coach-credentials/'.$credential->user_id.'/'.$credential->id.'/') && ! str_contains($path, '..') && Storage::disk('learning')->exists($path), 404);
            $stream = Storage::disk('learning')->readStream($path);
            abort_unless(is_resource($stream), 404);
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            if ($size !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash_final($hash))) {
                fclose($stream);
                abort(404);
            }
            rewind($stream);

            return $stream;
        } catch (HttpException $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $exception;
        } catch (Throwable) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            abort(404);
        }
    }

    /** @return array{stream: resource, filename: string} */
    public function download(User $actor, CoachCredential $credential): array
    {
        $opened = null;
        try {
            return DB::transaction(function () use ($actor, $credential, &$opened): array {
                [$actor, $credential] = $this->lock($actor, $credential);
                $metadata = $this->metadata($credential);
                abort_unless($metadata, 404);
                $opened = $this->verifiedFile($credential, $metadata);
                $this->audit($actor, $credential, 'file_downloaded');

                return ['stream' => $opened, 'filename' => $metadata['file_name']];
            });
        } catch (Throwable $exception) {
            if (is_resource($opened)) {
                fclose($opened);
            }
            throw $exception;
        }
    }

    public function change(User $actor, CoachCredential $credential, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'action' => 'required|in:accept,reject,withdraw', 'reason' => 'nullable|string|max:2000', 'reviewed' => 'exclude_if:action,withdraw|required|accepted'])->validate();
        $reason = trim($data['reason'] ?? '');
        if (in_array($data['action'], ['reject', 'withdraw'], true) && $reason === '') {
            $this->fail('دلیل این تصمیم را بنویسید.', 'reason');
        }
        DB::transaction(function () use ($actor, $credential, $data, $reason): void {
            [$actor, $credential, $admin, $owner] = $this->lock($actor, $credential, true);
            $withdraw = $data['action'] === 'withdraw';
            abort_unless($withdraw ? $actor->id === $credential->user_id : $admin, 403);
            if ($credential->revision !== (int) $data['revision'] || $credential->status === 'withdrawn') {
                $this->fail('وضعیت پرونده تغییر کرده یا کنار گذاشته شده است؛ صفحه را تازه کنید.', 'revision');
            }
            if ($data['action'] === 'accept') {
                $metadata = $this->metadata($credential);
                if (! $metadata) {
                    $this->fail('اطلاعات پرونده قابل بررسی نیست؛ پذیرش انجام نشد.', 'file');
                }
                try {
                    $stream = $this->verifiedFile($credential, $metadata);
                    fclose($stream);
                } catch (HttpException) {
                    $this->fail('فایل خصوصی موجود یا سالم نیست؛ پذیرش انجام نشد.', 'file');
                }
            }
            $from = $credential->status;
            $status = match ($data['action']) {
                'accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn',
            };
            $credential->update(['status' => $status, 'revision' => $credential->revision + 1]);
            $credential->events()->create(['actor_id' => $actor->id, 'action' => $withdraw ? 'withdrawn' : 'reviewed', 'from_status' => $from, 'to_status' => $status, 'revision' => $credential->revision, 'reason' => ['credential_id' => $credential->id, 'actor_id' => $actor->id, 'revision' => $credential->revision, 'text' => $reason ?: null]]);
            $this->audit($actor, $credential, $withdraw ? 'withdrawn' : 'reviewed');
            if (! $withdraw && $actor->id !== $credential->user_id) {
                $owner->notify(new CoachCredentialChanged($credential->id));
            }
        }, 3);
    }
}
