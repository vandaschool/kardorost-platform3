<?php

namespace Tests\Unit;

use App\Services\CoachProfileLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachProfileLinkTest extends TestCase
{
    public function test_optional_links_and_whitespace_normalization_preserve_plain_labels_without_external_requests(): void
    {
        Http::preventStrayRequests();
        $service = app(CoachProfileLinks::class);
        $this->assertSame([], $service->normalize(null));
        $this->assertSame([], $service->normalize([]));
        $this->assertSame([['label' => 'وب‌سایت آزمایشی', 'url' => 'https://example.test/path?x=1&y=2']], $service->normalize([['label' => '  وب‌سایت آزمایشی  ', 'url' => ' https://example.test/path?x=1&y=2 ']]));
        $this->assertSame('<b>عنوان متنی</b>', $service->normalize([['label' => '<b>عنوان متنی</b>', 'url' => 'http://example.test/']])[0]['label']);
        Http::assertNothingSent();
    }

    public static function allowedUrls(): array
    {
        return array_map(fn ($url) => [$url], ['https://example.test/', 'http://example.test/path', 'HTTPS://Example.test:8443/path#section', 'https://xn--mgbh0fb.xn--mgba3a4f16a/path', 'https://مثال.ایران/مسیر', 'https://example.test/path?x=%3Cscript%3E']);
    }

    #[DataProvider('allowedUrls')]
    public function test_absolute_web_links_use_existing_cms_url_rules(string $url): void
    {
        $this->assertSame($url, app(CoachProfileLinks::class)->normalize([['label' => 'پیوند آزمایشی', 'url' => $url]])[0]['url']);
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['javascript:alert(1)', 'data:text/html,<svg>', '//example.test/path', '/internal/path', '#section', 'mailto:private@example.test', 'ftp://example.test/', 'https://user:secret@example.test/', 'https://user@example.test/', 'https://example.test/a b', "https://example.test/a\nb", 'https://example.test/a\\b', 'https://bad_host.test/', 'https:///missing', 'https://example.test:0/', 'https://example.test/"onclick=1', 'https://example.test/<img>', 'https://example.test/'.str_repeat('a', 2048), ''] as $url) {
            $cases[] = [[['label' => 'پیوند آزمایشی', 'url' => $url]]];
        }
        foreach (['', 'ab', str_repeat('x', 81), "bad\0label", "bad\nlabel", "bad\xfflabel"] as $label) {
            $cases[] = [[['label' => $label, 'url' => 'https://example.test/']]];
        }
        $cases[] = ['https://example.test'];
        $cases[] = [['label' => 'پیوند آزمایشی', 'url' => 'https://example.test/']];
        $cases[] = [[['label' => 'پیوند آزمایشی', 'url' => 'https://example.test/', 'verified' => true]]];
        $cases[] = [[['label' => 'پیوند آزمایشی']]];
        $cases[] = [[['url' => 'https://example.test/']]];
        $cases[] = [[['label' => [], 'url' => 'https://example.test/']]];
        $cases[] = [[['label' => 'پیوند آزمایشی', 'url' => 123]]];
        $cases[] = [array_fill(0, 6, ['label' => 'پیوند آزمایشی', 'url' => 'https://example.test/'])];
        $cases[] = [[['label' => 'عنوان اول', 'url' => 'https://example.test/'], ['label' => 'عنوان دوم', 'url' => ' https://example.test/ ']]];

        return $cases;
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_shape_url_or_label_is_rejected_and_display_fails_closed(mixed $input): void
    {
        $service = app(CoachProfileLinks::class);
        $this->assertSame([], $service->display($input));
        try {
            $service->normalize($input);
            $this->fail('Unsafe or malformed profile links must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['links'], array_keys($e->errors()));
        }
    }

    public function test_five_distinct_links_fit_the_bound(): void
    {
        $input = array_map(fn ($i) => ['label' => 'عنوان آزمایشی '.$i, 'url' => 'https://example.test/'.$i], range(1, 5));
        $this->assertSame($input, app(CoachProfileLinks::class)->normalize($input));
    }
}
