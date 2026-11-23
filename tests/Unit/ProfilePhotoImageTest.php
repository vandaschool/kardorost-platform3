<?php

namespace Tests\Unit;

use App\Services\ProfilePhotoImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePhotoImageTest extends TestCase
{
    private static function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)).$type.$bytes.hash('crc32b', $type.$bytes, true);
    }

    private static function image(string $before = '', ?string $pixels = null, ?string $header = null, string $after = ''): string
    {
        return "\x89PNG\r\n\x1a\n".self::chunk('IHDR', $header ?? pack('NNCCCCC', 2, 1, 8, 6, 0, 0, 0)).$before.self::chunk('IDAT', gzcompress($pixels ?? "\0\x10\x20\x30\xff\x40\x50\x60\xff", 1)).self::chunk('IEND', '').$after;
    }

    private function prepare(string $bytes): array
    {
        return app(ProfilePhotoImages::class)->prepare(UploadedFile::fake()->createWithContent('software-profile.png', $bytes));
    }

    public function test_prepared_copy_has_valid_pixel_data_and_no_uploaded_text_or_exif_metadata(): void
    {
        $base = $this->prepare(self::image());
        $withMetadata = self::image(self::chunk('tEXt', "GPS\0SOFTWARE-ONLY-PRIVATE-METADATA").self::chunk('eXIf', 'SOFTWARE-ONLY-EXIF-METADATA'));
        $prepared = $this->prepare($withMetadata);
        $this->assertSame($base, $prepared);
        $this->assertStringNotContainsString('PRIVATE-METADATA', $prepared['bytes']);
        $this->assertStringNotContainsString('EXIF-METADATA', $prepared['bytes']);
        $this->assertSame([2, 1], array_slice(getimagesizefromstring($prepared['bytes']), 0, 2));
        $this->assertSame('image/png', $prepared['metadata']['mime']);
        $this->assertSame(strlen($prepared['bytes']), $prepared['metadata']['size']);
        $this->assertSame(hash('sha256', $prepared['bytes']), $prepared['metadata']['sha256']);
        $offset = 8;
        $types = [];
        $pixels = null;
        while ($offset < strlen($prepared['bytes'])) {
            $length = unpack('N', substr($prepared['bytes'], $offset, 4))[1];
            $type = substr($prepared['bytes'], $offset + 4, 4);
            $data = substr($prepared['bytes'], $offset + 8, $length);
            $this->assertSame(hash('crc32b', $type.$data, true), substr($prepared['bytes'], $offset + 8 + $length, 4));
            $types[] = $type;
            if ($type === 'IDAT') {
                $pixels = zlib_decode($data);
            }
            $offset += 12 + $length;
        }
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], $types);
        $this->assertSame("\0\x10\x20\x30\xff\x40\x50\x60\xff", $pixels);
    }

    public function test_extra_compressed_stream_payload_is_not_kept_in_prepared_copy(): void
    {
        $header = self::chunk('IHDR', pack('NNCCCCC', 2, 1, 8, 6, 0, 0, 0));
        $pixels = "\0\x10\x20\x30\xff\x40\x50\x60\xff";
        $input = "\x89PNG\r\n\x1a\n".$header.self::chunk('IDAT', gzcompress($pixels).'SOFTWARE-ONLY-TRAILING-PRIVATE-DATA').self::chunk('IEND', '');
        $this->assertSame($this->prepare(self::image())['bytes'], $this->prepare($input)['bytes']);
    }

    public function test_truecolor_rgb_copy_preserves_dimensions_and_rows(): void
    {
        $pixels = "\0\x10\x20\x30\x40\x50\x60";
        $prepared = $this->prepare(self::image(pixels: $pixels, header: pack('NNCCCCC', 2, 1, 8, 2, 0, 0, 0)));
        $this->assertSame(2, $prepared['metadata']['width']);
        $this->assertSame(1, $prepared['metadata']['height']);
        $this->assertSame('image/png', getimagesizefromstring($prepared['bytes'])['mime']);
    }

    public static function invalidImages(): array
    {
        $base = self::image();
        $badCrc = $base;
        $badCrc[32] = chr(ord($badCrc[32]) ^ 1);

        return [
            'wrong signature' => [str_replace("\x89PNG", 'nope', $base)],
            'bad header checksum' => [$badCrc],
            'truncated footer' => [substr($base, 0, -3)],
            'trailing data after footer' => [$base.'SOFTWARE-ONLY-TRAILER'],
            'missing footer' => [substr($base, 0, -12)],
            'unexpected critical chunk' => [self::image(self::chunk('ABCD', 'software-only'))],
            'second header' => [self::image(self::chunk('IHDR', pack('NNCCCCC', 2, 1, 8, 6, 0, 0, 0)))],
            'indexed color unsupported' => [self::image(header: pack('NNCCCCC', 2, 1, 8, 3, 0, 0, 0))],
            'interlacing unsupported' => [self::image(header: pack('NNCCCCC', 2, 1, 8, 6, 0, 0, 1))],
            'too many chunks' => [self::image(str_repeat(self::chunk('tEXt', ''), 256))],
            'inflated data too long' => [self::image(pixels: str_repeat('x', 65536))],
            'inflated data too short' => [self::image(pixels: "\0\x10")],
            'invalid row filter' => [self::image(pixels: "\5\x10\x20\x30\xff\x40\x50\x60\xff")],
            'profile dimensions too large' => [self::image(header: pack('NNCCCCC', 1025, 1, 8, 6, 0, 0, 0))],
        ];
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_or_unbounded_images_are_refused(string $bytes): void
    {
        $this->expectException(ValidationException::class);
        $this->prepare($bytes);
    }
}
