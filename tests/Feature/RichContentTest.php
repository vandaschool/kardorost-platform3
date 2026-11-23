<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\ContentPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RichContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function document(): array
    {
        return ['version' => 1, 'blocks' => [
            ['type' => 'heading', 'level' => 2, 'children' => [['text' => 'تیتر قالب‌دار']]],
            ['type' => 'paragraph', 'children' => [['text' => 'متن '], ['text' => 'پررنگ', 'bold' => true], ['text' => ' و تأکید', 'italic' => true], ['text' => ' لینک', 'href' => 'https://example.test/path?q=1&b=2']]],
            ['type' => 'quote', 'children' => [['text' => 'نقل‌قول نرم‌افزاری']]],
            ['type' => 'bullet_list', 'items' => [[['text' => 'مورد اول']], [['text' => 'مورد دوم']]]],
            ['type' => 'ordered_list', 'items' => [[['text' => 'مرحله اول']], [['text' => 'مرحله دوم']]]],
            ['type' => 'heading', 'level' => 3, 'children' => [['text' => 'زیرتیتر']]],
            ['type' => 'divider'],
            ['type' => 'paragraph', 'children' => [['text' => '<script>window.richXss=true</script>']]],
        ]];
    }

    private function definition(?array $document = null): array
    {
        return [...['title' => 'مقاله صرفاً آزمایشی', 'excerpt' => 'بررسی قالب متن، بدون محتوای واقعی', 'body' => 'نسخه متن ساده قبلی', 'meta_description' => 'بررسی نرم‌افزاری CMS'], ...($document ? ['document' => $document] : [])];
    }

    private function page(?array $document = null): ContentPage
    {
        return app(ContentPublishing::class)->create($this->admin, 'article', 'software-rich-content75', $this->definition($document));
    }

    public function test_rich_document_renders_semantic_escaped_markup_and_derives_plain_body(): void
    {
        $page = $this->page($this->document());
        $this->assertStringContainsString('پررنگ', $page->draft['body']);
        $this->assertStringNotContainsString('نسخه متن ساده قبلی', $page->draft['body']);
        app(ContentPublishing::class)->publish($this->admin, $page, 1);
        $response = $this->get($page->publicPath())->assertOk()->assertSee('data-rich-document', false)->assertSee('<h2 id="content-block-1">', false)->assertSee('<strong>پررنگ</strong>', false)->assertSee('<em> و تأکید</em>', false)->assertSee('<blockquote>', false)->assertSee('<ul>', false)->assertSee('<ol>', false)->assertSee('<h3', false)->assertSee('<hr>', false)->assertSee('rel="noopener noreferrer"', false)->assertSee('target="_blank"', false)->assertDontSee('<script>window.richXss=true</script>', false);
        $response->assertSee('&lt;script&gt;window.richXss=true&lt;/script&gt;', false)->assertSee('href="https://example.test/path?q=1&amp;b=2"', false);
        $this->assertSame($page->draft, ContentPublication::sole()->definition);
        Http::assertNothingSent();
    }

    public function test_http_draft_preserves_inline_whitespace_and_empty_runs(): void
    {
        $page = $this->page();
        $document = ['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'children' => [['text' => 'واژه '], ['text' => 'پررنگ', 'bold' => true], ['text' => ' و لینک '], ['text' => ''], ['text' => 'پایان', 'href' => '/catalog']]],
            ['type' => 'bullet_list', 'items' => [[['text' => ' مورد اول ']], [['text' => ''], ['text' => ' مورد دوم ']]]],
        ]];
        $this->actingAs($this->admin)->patch('/admin/content/'.$page->id.'/draft', ['revision' => $page->revision, 'definition' => $this->definition($document)])->assertSessionHasNoErrors()->assertRedirect();
        $page->refresh();
        $this->assertSame("واژه پررنگ و لینک پایان\n\n مورد اول \n مورد دوم", $page->draft['body']);
        $this->assertSame('واژه ', $page->draft['document']['blocks'][0]['children'][0]['text']);
        $this->assertSame(' مورد دوم ', $page->draft['document']['blocks'][1]['items'][1][0]['text']);
    }

    public function test_legacy_plain_content_and_hash_remain_unchanged_and_document_null_is_ignored(): void
    {
        $page = $this->page();
        $before = $page->draft;
        app(ContentPublishing::class)->publish($this->admin, $page, 1);
        $publication = ContentPublication::sole();
        $this->assertSame($before, $publication->definition);
        $this->assertSame($before, app(ContentPublishing::class)->definition([...$before, 'document' => null]));
        $this->get($page->publicPath())->assertOk()->assertSee('نسخه متن ساده قبلی')->assertDontSee('data-rich-document', false);
        $this->assertSame(hash('sha256', json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $publication->content_hash);
    }

    public function test_draft_format_changes_do_not_rewrite_published_version(): void
    {
        $page = $this->page($this->document());
        $service = app(ContentPublishing::class);
        $publication = $service->publish($this->admin, $page, 1);
        $raw = $publication->fresh()->getAttributes();
        $next = $this->document();
        $next['blocks'][0]['children'][0]['text'] = 'تیتر نسخه بعدی';
        $service->draft($this->admin, $page, $page->fresh()->revision, $this->definition($next));
        $this->get($page->publicPath())->assertSee('تیتر قالب‌دار')->assertDontSee('تیتر نسخه بعدی');
        $service->publish($this->admin, $page, $page->fresh()->revision);
        $this->get($page->publicPath())->assertSee('تیتر نسخه بعدی');
        $this->assertSame($raw, $publication->fresh()->getAttributes());
        $this->assertDatabaseCount('content_publications', 2);
    }

    public function test_format_only_changes_create_distinct_fixed_versions_with_identical_plain_text(): void
    {
        $doc = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'همان متن', 'bold' => false]]]]];
        $page = $this->page($doc);
        $service = app(ContentPublishing::class);
        $first = $service->publish($this->admin, $page, 1);
        $doc['blocks'][0]['children'][0]['bold'] = true;
        $service->draft($this->admin, $page, $page->fresh()->revision, $this->definition($doc));
        $second = $service->publish($this->admin, $page, $page->fresh()->revision);
        $this->assertSame($first->definition['body'], $second->definition['body']);
        $this->assertNotSame($first->content_hash, $second->content_hash);
        $this->get($page->publicPath())->assertSee('<strong>همان متن</strong>', false);
    }

    public function test_semantically_identical_runs_have_one_canonical_hash_and_cannot_be_republished(): void
    {
        $doc = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'یک متن']]]]];
        $page = $this->page($doc);
        $service = app(ContentPublishing::class);
        $first = $service->publish($this->admin, $page, 1);
        $doc['blocks'][0]['children'] = [['text' => 'یک ', 'bold' => false, 'italic' => false, 'href' => null], ['text' => 'متن']];
        $service->draft($this->admin, $page, $page->fresh()->revision, $this->definition($doc));
        $this->assertSame($first->definition, $page->fresh()->draft);
        try {
            $service->publish($this->admin, $page, $page->fresh()->revision);
            $this->fail('Equivalent formatting must not create another version.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
        }
        $this->assertDatabaseCount('content_publications', 1);
    }

    public static function unsafeLinks(): array
    {
        return array_map(fn ($value) => [$value], ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'file:///etc/passwd', '//example.test/path', 'https://user:pass@example.test/', "https://example.test/\nscript", 'https://example.test/white space', 'https://example.test/"onclick="x', 'https://example.test/`x', 'https://example.test\\@other.test', 'mailto:person@example.test', '#bad fragment', 'https://bad..test', 'https://-bad.test', 'https://example.test:0', 'https:///no-host']);
    }

    #[DataProvider('unsafeLinks')]
    public function test_unsafe_links_never_create_content_or_audit_records(string $href): void
    {
        $doc = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'لینک', 'href' => $href]]]]];
        $this->actingAs($this->admin)->post('/admin/content', ['kind' => 'article', 'slug' => 'blocked-link75', 'definition' => $this->definition($doc)])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('content_pages', 0);
        $this->assertDatabaseCount('content_publications', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function safeLinks(): array
    {
        return array_map(fn ($href) => [$href], ['https://example.test/path?x=1&y=2', 'http://localhost:8037/a', '/catalog?q=test', '#content-block-1', 'https://مثال.ایران/مسیر', 'https://[::1]:8443/path', 'https://example.test/فارسی', 'https://example.test.']);
    }

    #[DataProvider('safeLinks')]
    public function test_valid_web_internal_and_fragment_links_are_preserved(string $href): void
    {
        $doc = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'لینک مجاز', 'href' => $href]]]]];
        $page = $this->page($doc);
        app(ContentPublishing::class)->publish($this->admin, $page, 1);
        $this->assertSame($href, ContentPublication::sole()->definition['document']['blocks'][0]['children'][0]['href']);
        $this->get($page->publicPath())->assertOk()->assertSee('href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'"', false);
    }

    public static function invalidDocuments(): array
    {
        $text = ['text' => 'متن'];
        $paragraph = ['type' => 'paragraph', 'children' => [$text]];

        return [
            'version' => [['version' => 2, 'blocks' => [$paragraph]]],
            'version_type' => [['version' => '1', 'blocks' => [$paragraph]]],
            'root_attribute' => [['version' => 1, 'blocks' => [$paragraph], 'html' => '<img>']],
            'empty' => [['version' => 1, 'blocks' => []]],
            'indexed_object' => [['version' => 1, 'blocks' => [1 => $paragraph]]],
            'too_many_blocks' => [['version' => 1, 'blocks' => array_fill(0, 101, $paragraph)]],
            'tag' => [['version' => 1, 'blocks' => [['type' => 'script', 'children' => [$text]]]]],
            'attribute' => [['version' => 1, 'blocks' => [[...$paragraph, 'onclick' => 'alert(1)']]]],
            'heading' => [['version' => 1, 'blocks' => [['type' => 'heading', 'level' => 1, 'children' => [$text]]]]],
            'heading_type' => [['version' => 1, 'blocks' => [['type' => 'heading', 'level' => '2', 'children' => [$text]]]]],
            'child_type' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => 'html']]]],
            'text_type' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => ['not text']]]]]]],
            'style_type' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'متن', 'bold' => 'true']]]]]],
            'inline_attribute' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'متن', 'style' => 'color:red']]]]]],
            'empty_list' => [['version' => 1, 'blocks' => [['type' => 'bullet_list', 'items' => []]]]],
            'oversize_list' => [['version' => 1, 'blocks' => [['type' => 'ordered_list', 'items' => array_fill(0, 51, [$text])]]]],
            'too_many_runs' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => array_fill(0, 201, $text)]]]],
            'too_many_nodes' => [['version' => 1, 'blocks' => array_fill(0, 11, ['type' => 'paragraph', 'children' => array_fill(0, 200, $text)])]],
            'null_character' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => "a\0b"]]]]]],
            'invalid_utf8' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => "\xB1"]]]]]],
            'no_text' => [['version' => 1, 'blocks' => [['type' => 'divider']]]],
            'too_long' => [['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => str_repeat('x', 60001)]]]]]],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_malformed_or_oversized_document_is_rejected_without_storage(array $document): void
    {
        try {
            $this->page($document);
            $this->fail('Document must match the bounded text schema.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $this->assertDatabaseCount('content_pages', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_corrupt_public_rich_structure_falls_back_to_escaped_plain_text(): void
    {
        $page = $this->page();
        $publication = app(ContentPublishing::class)->publish($this->admin, $page, 1);
        $definition = [...$publication->definition, 'document' => ['version' => 1, 'blocks' => [['type' => 'script', 'children' => [['text' => 'alert(1)']]]]]];
        $publication->update(['definition' => $definition, 'content_hash' => app(ContentPublishing::class)->hash($definition)]);
        $this->get($page->publicPath())->assertOk()->assertSee('نسخه متن ساده قبلی')->assertDontSee('data-rich-document', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_rich_scheduled_snapshot_publishes_the_same_format_and_rejects_format_tampering(): void
    {
        $page = $this->page($this->document());
        $service = app(ContentPublishing::class);
        $at = CarbonImmutable::now()->addHour();
        $schedule = $service->schedule($this->admin, $page, 1, $at, true);
        $this->assertSame($page->draft, $schedule->definition);
        $this->travelTo($at->addMinute());
        $this->assertSame('published', $service->processSchedule($schedule));
        $this->get($page->publicPath())->assertOk()->assertSee('<strong>پررنگ</strong>', false);
        $next = $this->document();
        $next['blocks'][0]['children'][0]['text'] = 'نسخه دیگر';
        $service->draft($this->admin, $page, $page->fresh()->revision, $this->definition($next));
        $second = $service->schedule($this->admin, $page, $page->fresh()->revision, CarbonImmutable::now()->addHour(), true);
        $tampered = $second->definition;
        $tampered['document']['blocks'][1]['children'][1]['bold'] = false;
        $second->update(['definition' => $tampered]);
        $this->travelTo(CarbonImmutable::now()->addHours(2));
        $this->assertSame('blocked', $service->processSchedule($second));
        $this->assertSame('snapshot_changed', $second->fresh()->reason);
        $this->assertDatabaseCount('content_publications', 1);
    }

    public function test_normalization_preserves_persian_half_spaces_inline_whitespace_and_line_breaks(): void
    {
        $document = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'children' => [['text' => 'نیم‌فاصله '], ['text' => "و خط\r\nبعدی", 'bold' => true]]]]];
        $this->assertSame("نیم‌فاصله و خط\nبعدی", app(ContentDocument::class)->plain($document));
    }
}
