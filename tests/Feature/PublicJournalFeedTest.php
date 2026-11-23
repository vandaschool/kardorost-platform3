<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicJournalFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['app.url' => 'https://kardorost.example']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function article(string $slug, array $changes = [], bool $publish = true, string $kind = 'article'): ContentPage
    {
        $service = app(ContentPublishing::class);
        $page = $service->create($this->admin, $kind, $slug, [...['title' => 'مقاله عمومی '.$slug, 'excerpt' => 'خلاصه عمومی', 'body' => 'متن کامل عمومی', 'meta_description' => ''], ...$changes]);
        if ($publish) {
            $service->publish($this->admin, $page, $page->revision);
        }

        return $page->fresh();
    }

    private function feed(?string $path = null): DOMXPath
    {
        $response = $this->get($path ?? '/journal/feed.xml');
        $response->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent(), LIBXML_NONET));

        return new DOMXPath($document);
    }

    public function test_empty_feed_is_valid_discoverable_and_does_not_create_content(): void
    {
        $xml = $this->feed();
        $this->assertSame('2.0', $xml->evaluate('string(/rss/@version)'));
        $this->assertSame('fa-IR', $xml->evaluate('string(/rss/channel/language)'));
        $this->assertSame(0, $xml->query('/rss/channel/item')->length);
        $this->assertDatabaseCount('content_pages', 0);
        $this->assertDatabaseCount('content_publications', 0);
        $this->get('/journal')->assertOk()->assertSee('rel="alternate" type="application/rss+xml"', false)->assertSee('https://kardorost.example/journal/feed.xml', false)->assertSee('خوراک مجله (RSS)');
        Http::assertNothingSent();
    }

    public function test_only_current_public_article_snapshot_is_exposed_and_read_is_side_effect_free(): void
    {
        $page = $this->article('safe-article');
        $this->article('private-draft', ['title' => 'عنوان محرمانه'], false);
        $this->article('hidden-article')->update(['available' => false]);
        $this->article('ordinary-page', [], true, 'page');
        $future = $this->article('future-article');
        $future->currentPublication->update(['published_at' => now()->addHour()]);
        app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, [...$page->draft, 'title' => 'عنوان پیش‌نویس بعدی', 'excerpt' => 'خلاصه خصوصی', 'body' => 'متن خصوصی بعدی']);
        $page->refresh();
        app(ContentPublishing::class)->schedule($this->admin, $page, $page->revision, CarbonImmutable::now()->addHours(2), true);
        $before = ['page' => $page->fresh()->getAttributes(), 'publication' => $page->currentPublication->getAttributes(), 'audit' => AuditEvent::count()];
        $xml = $this->feed('/journal/feed.xml?token=private-query');
        $this->assertSame(1, $xml->query('/rss/channel/item')->length);
        $this->assertSame('مقاله عمومی safe-article', $xml->evaluate('string(/rss/channel/item/title)'));
        $this->assertSame('خلاصه عمومی', $xml->evaluate('string(/rss/channel/item/description)'));
        $this->assertStringNotContainsString('خصوصی', $xml->document->saveXML());
        $this->assertStringNotContainsString('private-query', $xml->document->saveXML());
        $this->assertSame($before, ['page' => $page->fresh()->getAttributes(), 'publication' => $page->currentPublication->fresh()->getAttributes(), 'audit' => AuditEvent::count()]);
        Http::assertNothingSent();
    }

    public function test_canonical_links_ignore_request_host_and_query_and_guid_stays_stable_after_republication(): void
    {
        $page = $this->article('canonical-article');
        $xml = $this->feed('https://poison.example/journal/feed.xml?utm_source=secret');
        $url = 'https://kardorost.example/journal/canonical-article';
        $this->assertSame($url, $xml->evaluate('string(/rss/channel/item/link)'));
        $this->assertSame($url, $xml->evaluate('string(/rss/channel/item/guid)'));
        $this->assertSame('true', $xml->evaluate('string(/rss/channel/item/guid/@isPermaLink)'));
        $this->assertStringNotContainsString('poison.example', $xml->document->saveXML());
        $first = $page->currentPublication->getAttributes();
        app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, [...$page->draft, 'title' => 'عنوان عمومی تازه']);
        $page->refresh();
        app(ContentPublishing::class)->publish($this->admin, $page, $page->revision);
        $next = $this->feed();
        $this->assertSame($url, $next->evaluate('string(/rss/channel/item/guid)'));
        $this->assertSame('عنوان عمومی تازه', $next->evaluate('string(/rss/channel/item/title)'));
        $this->assertSame($first, $page->publications()->where('number', 1)->firstOrFail()->getAttributes());
    }

    public function test_xml_characters_and_html_in_plain_excerpt_remain_safe_for_html_feed_readers(): void
    {
        $title = 'فارسی & <عنوان> "آزمایشی"';
        $excerpt = '<img src="https://external.example/x" onerror="alert(1)"> & </description><script>bad()</script>';
        $this->article('safe-markup', ['title' => $title."\0", 'excerpt' => $excerpt."\x01", 'body' => 'متن کامل در خوراک نیست']);
        $xml = $this->feed();
        $this->assertSame($title, $xml->evaluate('string(/rss/channel/item/title)'));
        $this->assertSame(0, $xml->query('//script|//img')->length);
        $description = $xml->evaluate('string(/rss/channel/item/description)');
        $html = new DOMDocument;
        $this->assertTrue($html->loadHTML('<!doctype html><html><body><div>'.$description.'</div></body></html>', LIBXML_NONET));
        $this->assertSame(0, $html->getElementsByTagName('script')->length);
        $this->assertSame(0, $html->getElementsByTagName('img')->length);
        $this->assertSame($excerpt, $html->getElementsByTagName('div')->item(0)->textContent);
        $this->assertStringNotContainsString('متن کامل در خوراک نیست', $xml->document->saveXML());
        Http::assertNothingSent();
    }

    public function test_rich_snapshot_exports_only_its_signed_excerpt_without_embedded_body_or_links(): void
    {
        $page = $this->article('rich-article', ['excerpt' => 'خلاصه مستقل منتشرشده', 'document' => ['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'children' => [['text' => 'متن کامل فقط در مقاله', 'href' => 'https://external.example/body']]],
        ]]]);
        $before = $page->currentPublication->getAttributes();
        $xml = $this->feed();
        $this->assertSame('خلاصه مستقل منتشرشده', $xml->evaluate('string(/rss/channel/item/description)'));
        $this->assertStringNotContainsString('external.example', $xml->document->saveXML());
        $this->assertStringNotContainsString('متن کامل فقط در مقاله', $xml->document->saveXML());
        $this->assertSame($before, $page->currentPublication->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_corrupt_or_mismatched_current_snapshots_fail_closed(): void
    {
        $badHash = $this->article('bad-hash');
        $badHash->currentPublication->update(['content_hash' => str_repeat('0', 64)]);
        $wrongVersion = $this->article('wrong-version');
        $wrongVersion->update(['current_version' => 99]);
        $badDefinition = $this->article('bad-definition');
        $definition = [...$badDefinition->draft, 'excerpt' => ['unexpected']];
        $badDefinition->currentPublication->update(['definition' => $definition, 'content_hash' => app(ContentPublishing::class)->hash($definition)]);
        $zeroVersion = $this->article('zero-version');
        $zeroVersion->currentPublication->update(['number' => 0]);
        $zeroVersion->update(['current_version' => 0]);
        $badSlug = $this->article('bad-slug');
        DB::table('content_pages')->where('id', $badSlug->id)->update(['slug' => 'invalid/path']);
        $xml = $this->feed();
        $this->assertSame(0, $xml->query('/rss/channel/item')->length);
    }

    public function test_latest_fifty_publications_have_stable_order_and_draft_edits_do_not_move_items(): void
    {
        $this->travelTo(now()->startOfSecond());
        for ($i = 1; $i <= 52; $i++) {
            $this->article('article-'.$i);
        }
        $oldest = ContentPage::where('slug', 'article-1')->firstOrFail();
        app(ContentPublishing::class)->draft($this->admin, $oldest, $oldest->revision, [...$oldest->draft, 'title' => 'ویرایش خصوصی قدیمی']);
        $xml = $this->feed();
        $this->assertSame(50, $xml->query('/rss/channel/item')->length);
        $this->assertSame('مقاله عمومی article-52', $xml->evaluate('string(/rss/channel/item[1]/title)'));
        $this->assertSame('مقاله عمومی article-3', $xml->evaluate('string(/rss/channel/item[last()]/title)'));
        $this->assertSame($xml->evaluate('string(/rss/channel/item[1]/pubDate)'), $xml->evaluate('string(/rss/channel/lastBuildDate)'));
        $this->assertSame(now()->utc()->toRfc2822String(), $xml->evaluate('string(/rss/channel/lastBuildDate)'));
        $this->travelBack();
    }

    public function test_withdrawal_removes_item_immediately_and_feed_limit_has_its_own_bucket(): void
    {
        $page = $this->article('withdrawn-article');
        $this->assertSame(1, $this->feed()->query('/rss/channel/item')->length);
        app(ContentPublishing::class)->availability($this->admin, $page, $page->revision, false);
        $this->assertSame(0, $this->feed()->query('/rss/channel/item')->length);
        for ($i = 0; $i < 58; $i++) {
            $this->get('/journal/feed.xml')->assertOk();
        }
        $this->get('/journal/feed.xml')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->get('/journal')->assertOk();
    }
}
