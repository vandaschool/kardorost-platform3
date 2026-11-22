<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ContentMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContentMediaLibrary
{
    public const RIGHTS = 'مجوز استفاده از این تصویر در سایت کاردرست را دارم. بارگذاری، تصویر را عمومی نمی‌کند؛ انتشار عمومی باید جداگانه تأیید شود.';

    private function fail(string $message, string $field = 'media'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function admin(User $actor, bool $lock = false): User
    {
        $query = User::whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($current->is_active && $current->hasRole('admin'), 403);

        return $current;
    }

    public function metadata(ContentMedia $media): ?array
    {
        try {
            $m = $media->definition;
            if (! is_array($m) || ($m['id'] ?? null) !== $media->id || ($m['owner_id'] ?? null) !== $media->owner_id || ($m['public_key'] ?? null) !== $media->public_key || ($m['request_key'] ?? null) !== $media->request_key || ($m['path'] ?? null) !== $media->file_path || ($m['rights_version'] ?? null) !== 'content_image_v1' || ($m['rights_text'] ?? null) !== self::RIGHTS) {
                return null;
            }
            foreach (['title', 'file_name', 'sha256', 'mime', 'path'] as $field) {
                if (! is_string($m[$field] ?? null) || ! mb_check_encoding($m[$field], 'UTF-8')) {
                    return null;
                }
            }
            if (! preg_match('/^[a-f0-9]{64}$/D', $m['sha256']) || ! in_array($m['mime'], ['image/png', 'image/jpeg'], true) || ! is_int($m['size'] ?? null) || $m['size'] < 1 || $m['size'] > 4194304 || ! is_int($m['width'] ?? null) || ! is_int($m['height'] ?? null) || min($m['width'], $m['height']) < 1 || max($m['width'], $m['height']) > 6000 || $m['width'] * $m['height'] > 12000000 || ! str_starts_with($m['path'], 'content-media/'.$media->id.'/') || str_contains($m['path'], '..')) {
                return null;
            }

            return $m;
        } catch (Throwable) {
            return null;
        }
    }

    private function audit(User $actor, ContentMedia $media, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'content_media.'.$action, 'subject_type' => 'content_media', 'subject_id' => $media->id, 'metadata' => ['revision' => $media->revision]]);
    }

    public function upload(User $actor, array $input, UploadedFile $file): ContentMedia
    {
        $this->admin($actor);
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'rights_confirmed' => 'required|accepted', 'request_key' => 'required|uuid'])->validate();
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:png,jpg,jpeg|extensions:png,jpg,jpeg|max:4096'])->validate();
        $mime = $file->getMimeType();
        $dimensions = @getimagesize($file->getRealPath());
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ! $dimensions || ($dimensions['mime'] ?? null) !== $mime || min($dimensions[0], $dimensions[1]) < 1 || max($dimensions[0], $dimensions[1]) > 6000 || $dimensions[0] * $dimensions[1] > 12000000) {
            $this->fail('تصویر PNG یا JPEG تا ۶۰۰۰ پیکسل و ۱۲ میلیون پیکسل کل انتخاب کنید.', 'file');
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace(chr(92), '/', $file->getClientOriginalName())));
        if (! is_string($name) || $name === '' || mb_strlen($name) > 255 || trim($data['title']) === '') {
            $this->fail('نام فایل و عنوان معتبر لازم است.', 'file');
        }
        $attributes = ['title' => trim($data['title']), 'file_name' => $name, 'mime' => $mime, 'size' => (int) $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'width' => $dimensions[0], 'height' => $dimensions[1]];
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $data, $attributes, $file, &$path): ContentMedia {
                $actor = $this->admin($actor, true);
                $existing = ContentMedia::where('owner_id', $actor->id)->where('request_key', strtolower($data['request_key']))->first();
                if ($existing) {
                    $m = $this->metadata($existing);
                    if (! $m || array_intersect_key($m, $attributes) !== $attributes) {
                        $this->fail('این شناسه برای تصویر دیگری استفاده شده؛ فرم تازه باز کنید.', 'request_key');
                    }

                    return $existing;
                }
                if (ContentMedia::where('owner_id', $actor->id)->count() >= 500) {
                    $this->fail('سقف ۵۰۰ تصویر ثبت‌شده برای این حساب رسیده است.');
                }
                $media = ContentMedia::create(['owner_id' => $actor->id, 'public_key' => (string) Str::uuid(), 'request_key' => strtolower($data['request_key'])]);
                $path = 'content-media/'.$media->id.'/'.Str::uuid().($attributes['mime'] === 'image/png' ? '.png' : '.jpg');
                if (! Storage::disk('learning')->putFileAs(dirname($path), $file, basename($path))) {
                    $this->fail('تصویر ذخیره نشد؛ دوباره تلاش کنید.', 'file');
                }
                $media->update(['file_path' => $path, 'definition' => [...$attributes, 'id' => $media->id, 'owner_id' => $actor->id, 'public_key' => $media->public_key, 'request_key' => $media->request_key, 'path' => $path, 'rights_version' => 'content_image_v1', 'rights_text' => self::RIGHTS]]);
                $this->audit($actor, $media, 'uploaded');

                return $media;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk('learning')->delete($path);
            }
            throw $e;
        }
    }

    /** The returned stream is the same handle whose bytes were checked. Caller closes it. */
    public function stream(ContentMedia $media): array
    {
        $m = $this->metadata($media);
        abort_unless($m, 404);
        $stream = Storage::disk('learning')->readStream($media->file_path);
        abort_unless(is_resource($stream), 404);
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            abort_unless($size === $m['size'] && hash_equals($m['sha256'], hash_final($hash)) && rewind($stream), 404);

            return ['stream' => $stream, 'mime' => $m['mime'], 'name' => $media->public_key.($m['mime'] === 'image/png' ? '.png' : '.jpg')];
        } catch (Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    public function decide(User $actor, ContentMedia $input, int $revision, bool $publish, bool $reviewed): void
    {
        DB::transaction(function () use ($actor, $input, $revision, $publish, $reviewed): void {
            $actor = $this->admin($actor, true);
            $media = ContentMedia::lockForUpdate()->findOrFail($input->id);
            if ($media->revision !== $revision || $media->withdrawn_at) {
                $this->fail('تصویر تغییر کرده یا برداشته شده؛ صفحه را تازه کنید.');
            }
            if ($publish) {
                if (! $reviewed || $media->published_at) {
                    $this->fail('تصویر ثبت‌شده را پیش از انتشار عمومی تأیید کنید.');
                }
                try {
                    $prepared = $this->stream($media);
                    fclose($prepared['stream']);
                } catch (Throwable) {
                    $this->fail('فایل تصویر معتبر یا در دسترس نیست؛ تصویر دیگری ثبت کنید.');
                }
                $media->update(['published_at' => now(), 'published_by' => $actor->id, 'revision' => $media->revision + 1]);
            } else {
                $media->update(['withdrawn_at' => now(), 'withdrawn_by' => $actor->id, 'revision' => $media->revision + 1]);
            }
            $this->audit($actor, $media, $publish ? 'published' : 'withdrawn');
        });
    }

    public function image(string $key, string $sha, bool $public = true): ?array
    {
        $media = ContentMedia::where('public_key', $key)->first();
        $m = $media ? $this->metadata($media) : null;
        if (! $media || ! $m || $media->withdrawn_at || ($public && ! $media->published_at) || ! hash_equals($m['sha256'], $sha)) {
            return null;
        }

        return ['key' => $key, 'sha256' => $sha, 'width' => $m['width'], 'height' => $m['height'], 'url' => route('public.media', ['key' => $key, 'sha' => $sha])];
    }

    public function validateDocument(array $definition, bool $public): void
    {
        $images = collect($definition['document']['blocks'] ?? [])->where('type', 'image')->values();
        if ($images->isEmpty()) {
            return;
        }
        $keys = $images->pluck('media')->unique()->sort()->values();
        $rows = ContentMedia::whereIn('public_key', $keys)->orderBy('id')->lockForUpdate()->get()->keyBy('public_key');
        foreach ($images as $block) {
            $media = $rows->get($block['media']);
            $m = $media ? $this->metadata($media) : null;
            if (! $media || ! $m || $media->withdrawn_at || ($public && ! $media->published_at) || ! hash_equals($m['sha256'], $block['sha256'])) {
                $this->fail('تصویر انتخاب‌شده در دسترس نیست؛ تصویر منتشرشدهٔ کتابخانه را انتخاب کنید.', 'document');
            }
        }
        if ($public) {
            foreach ($rows as $media) {
                try {
                    $prepared = $this->stream($media);
                    fclose($prepared['stream']);
                } catch (Throwable) {
                    $this->fail('فایل تصویر معتبر یا در دسترس نیست؛ تصویر دیگری انتخاب کنید.', 'document');
                }
            }
        }
    }
}
