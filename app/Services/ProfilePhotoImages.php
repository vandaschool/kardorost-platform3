<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ProfilePhotoImages
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['file' => 'نسخه عکس برای ثبت آماده نیست؛ فایل را دوباره در فرم انتخاب کنید.']);
    }

    private function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)).$type.$bytes.hash('crc32b', $type.$bytes, true);
    }

    /** Accepts bounded RGB/RGBA 8-bit PNG; stores only header and freshly compressed pixel rows. */
    public function prepare(UploadedFile $file): array
    {
        $metadata = app(RasterImages::class)->describe($file);
        if ($metadata['mime'] !== 'image/png' || $metadata['width'] > 1024 || $metadata['height'] > 1024) {
            $this->fail();
        }
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $this->fail();
        }
        $offset = 8;
        $count = 0;
        $header = null;
        $compressed = '';
        $ended = false;
        while ($offset < strlen($bytes)) {
            if (++$count > 256 || $offset + 12 > strlen($bytes)) {
                $this->fail();
            }
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($length > 4194304 || $offset + 12 + $length > strlen($bytes)) {
                $this->fail();
            }
            $type = substr($bytes, $offset + 4, 4);
            $payload = substr($bytes, $offset + 8, $length);
            $crc = substr($bytes, $offset + 8 + $length, 4);
            if (! preg_match('/^[A-Za-z]{4}$/D', $type) || ! hash_equals(hash('crc32b', $type.$payload, true), $crc)) {
                $this->fail();
            }
            $offset += 12 + $length;
            if ($type === 'IHDR') {
                if ($count !== 1 || $length !== 13 || $header !== null) {
                    $this->fail();
                }
                $info = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $payload);
                if ($info['width'] !== $metadata['width'] || $info['height'] !== $metadata['height'] || $info['depth'] !== 8 || ! in_array($info['color'], [2, 6], true) || $info['compression'] !== 0 || $info['filter'] !== 0 || $info['interlace'] !== 0) {
                    $this->fail();
                }
                $header = $payload;
            } elseif ($header === null) {
                $this->fail();
            } elseif ($type === 'IDAT') {
                $compressed .= $payload;
            } elseif ($type === 'IEND') {
                if ($length !== 0 || $offset !== strlen($bytes)) {
                    $this->fail();
                }
                $ended = true;
                break;
            } elseif (! ctype_lower($type[0])) {
                $this->fail();
            }
        }
        if (! $ended || $compressed === '' || $header === null) {
            $this->fail();
        }
        $channels = ord($header[9]) === 6 ? 4 : 3;
        $row = 1 + $metadata['width'] * $channels;
        $expected = $row * $metadata['height'];
        $pixels = @zlib_decode($compressed, $expected + 1);
        if (! is_string($pixels) || strlen($pixels) !== $expected) {
            $this->fail();
        }
        for ($line = 0; $line < $metadata['height']; $line++) {
            if (ord($pixels[$line * $row]) > 4) {
                $this->fail();
            }
        }
        $encoded = gzcompress($pixels, 9);
        if (! is_string($encoded)) {
            $this->fail();
        }
        $prepared = "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', $header).$this->chunk('IDAT', $encoded).$this->chunk('IEND', '');
        if (strlen($prepared) > 4194304) {
            $this->fail();
        }

        return ['bytes' => $prepared, 'metadata' => [...$metadata, 'size' => strlen($prepared), 'sha256' => hash('sha256', $prepared)]];
    }
}
