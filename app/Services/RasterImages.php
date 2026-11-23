<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class RasterImages
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public function describe(UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => 'required|file|mimes:png,jpg,jpeg|extensions:png,jpg,jpeg|max:4096'])->validate();
        $mime = $file->getMimeType();
        $dimensions = @getimagesize($file->getRealPath());
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ! $dimensions || ($dimensions['mime'] ?? null) !== $mime || min($dimensions[0], $dimensions[1]) < 1 || max($dimensions[0], $dimensions[1]) > 6000 || $dimensions[0] * $dimensions[1] > 12000000) {
            $this->fail('تصویر PNG یا JPEG تا ۶۰۰۰ پیکسل و ۱۲ میلیون پیکسل کل انتخاب کنید.');
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace(chr(92), '/', $file->getClientOriginalName())));
        if (! is_string($name) || $name === '' || mb_strlen($name) > 255) {
            $this->fail('نام فایل تصویر معتبر نیست.');
        }

        return ['file_name' => $name, 'mime' => $mime, 'size' => (int) $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    /** Caller authorizes the resource and verifies its source-bound metadata before opening. */
    public function open(string $path, array $metadata, string $opaqueName): array
    {
        abort_unless(in_array($metadata['mime'] ?? null, ['image/png', 'image/jpeg'], true) && is_int($metadata['size'] ?? null) && $metadata['size'] > 0 && $metadata['size'] <= 4194304 && is_string($metadata['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) && preg_match('/^[a-f0-9-]{36}\.(?:png|jpg)$/D', $opaqueName), 404);
        $stream = Storage::disk('learning')->readStream($path);
        abort_unless(is_resource($stream), 404);
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            abort_unless($size === $metadata['size'] && hash_equals($metadata['sha256'], hash_final($hash)) && rewind($stream), 404);

            return ['stream' => $stream, 'mime' => $metadata['mime'], 'name' => $opaqueName, 'size' => $size];
        } catch (Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }
}
