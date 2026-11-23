<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentCategories;
use App\Services\ContentPublishing;
use App\Services\ContentRedirects;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentCategoryTest extends TestCase
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

    private function definition(array $changes = []): array
    {
        return [...['title' => 'مقاله عمومی', 'excerpt' => 'خلاصه عمومی', 'body' => 'متن عمومی', 'meta_description' => '', 'tags' => ['رشد']], ...$changes];
    }

    private function page(string $slug, array $changes = [], bool $publish = true, string $kind = 'article'): ContentPage
    {
        $service = app(ContentPublishing::class);
        $page = $service->create($this->admin, $kind, $slug, $this->definition($changes));
        if ($publish) {
            $service->publish($this->admin, $page, 1);
        }

        return $page->fresh();
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (['content_pages', 'content_publications', 'content_schedules', 'content_redirects', 'audit_events'] as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $all;
    }

    public function test_optional_empty_category_preserves_legacy_definition_and_hash_with_existing_tags(): void
    {
        $service = app(ContentPublishing::class);
        $original = $service->definition($this->definition());
        foreach ([null, '', '   ', "\u{00a0} "] as $value) {
            $same = $service->definition($this->definition(['category' => $value]));
            $this->assertSame($original, $same);
            $this->assertArrayNotHasKey('category', $same);
            $this->assertSame($service->hash($original), $service->hash($same));
        }
        $page = $this->page('legacy-category');
        $before = $this->snapshot();
        $this->get($page->publicPath())->assertOk()->assertDontSee('data-public-article-category', false);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_normalized_category_and_numeric_zero_remain_strings_and_filter_exactly(): void
    {
        $first = $this->page('normalized-category', ['category' => "\u{00a0}رشد  حرفه‌ای\u{00a0}"]);
        $zero = $this->page('zero-category', ['category' => '0']);
        $this->page('double-zero-category', ['category' => '00']);
        $this->assertSame('رشد حرفه‌ای', $first->draft['category']);
        $this->assertSame('0', $zero->currentPublication->definition['category']);
        $facets = app(ContentCategories::class)->facets(ContentPage::where('kind', 'article'));
        $this->assertContains('0', $facets);
        $this->assertNotContains(0, $facets);
        $this->get('/journal?category=0')->assertOk()->assertSee('href="/journal/zero-category"', false)->assertDontSee('href="/journal/double-zero-category"', false);
    }

    public static function invalidCategories(): array
    {
        return [[0], [false], [[]], [['name' => 'category']], [str_repeat('الف', 21)], ["bad\ncategory"], ["bad\0category"], ["\xff"], [new \stdClass]];
    }

    #[DataProvider('invalidCategories')]
    public function test_invalid_category_cannot_create_or_change_content_or_audit(mixed $category): void
    {
        $page = $this->page('valid-before-category');
        $before = $this->snapshot();
        foreach ([fn () => app(ContentPublishing::class)->create($this->admin, 'article', 'invalid-category', $this->definition(['category' => $category])), fn () => app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, $this->definition(['category' => $category]))] as $operation) {
            try {
                $operation();
                $this->fail('Invalid category accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('category', $error->errors());
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_private_edit_republication_and_removal_preserve_old_versions_tags_and_alias(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->page('versioned-category', ['category' => 'دستهٔ اول']);
        $first = $page->currentPublication->getAttributes();
        $alias = app(ContentRedirects::class)->create($this->admin, $page, 'old-category');
        $aliasBefore = $alias->fresh()->getAttributes();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => 'دستهٔ خصوصی']);
        $this->get('/journal')->assertSee('دستهٔ اول')->assertDontSee('دستهٔ خصوصی');
        $this->get('/journal?category='.rawurlencode('دستهٔ خصوصی'))->assertDontSee('href="/journal/versioned-category"', false);
        $page->refresh();
        $second = $service->publish($this->admin, $page, $page->revision);
        $this->get($page->publicPath())->assertSee('دستهٔ خصوصی')->assertDontSee('دستهٔ اول');
        $this->assertSame($first, $page->publications()->where('number', 1)->sole()->getAttributes());
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => '']);
        $page->refresh();
        $third = $service->publish($this->admin, $page, $page->revision);
        $this->assertArrayNotHasKey('category', $third->definition);
        $this->assertSame(['رشد'], $third->definition['tags']);
        $this->assertSame('دستهٔ خصوصی', $second->fresh()->definition['category']);
        $this->assertSame($aliasBefore, $alias->fresh()->getAttributes());
        $this->get('/journal/old-category')->assertRedirect('/journal/versioned-category');
        $this->get($page->publicPath())->assertDontSee('data-public-article-category', false);
    }

    public function test_filters_intersect_category_title_and_tag_and_category_wildcards_are_literal(): void
    {
        $category = '%_!';
        $this->page('matching-category', ['title' => 'یادگیری مهارت', 'category' => $category]);
        $this->page('wrong-tag-category', ['title' => 'یادگیری مهارت', 'category' => $category, 'tags' => ['دیگر']]);
        $this->page('wrong-title-category', ['title' => 'مدیریت زمان', 'category' => $category]);
        $this->page('wrong-category', ['title' => 'یادگیری مهارت', 'category' => 'رشد']);
        $query = http_build_query(['q' => 'یادگیری', 'tag' => 'رشد', 'category' => $category]);
        $response = $this->get('/journal?'.$query)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->assertSee('href="/journal/matching-category"', false);
        foreach (['wrong-tag-category', 'wrong-title-category', 'wrong-category'] as $slug) {
            $response->assertDontSee('href="/journal/'.$slug.'"', false);
        }
    }

    public function test_facets_only_include_valid_current_due_public_articles_and_are_bounded_ranked(): void
    {
        $this->page('private-category', ['category' => 'دستهٔ خصوصی'], false);
        $closed = $this->page('closed-category', ['category' => 'دستهٔ بسته']);
        app(ContentPublishing::class)->availability($this->admin, $closed, $closed->revision, false);
        $future = $this->page('future-category', ['category' => 'دستهٔ آینده']);
        $future->currentPublication->update(['published_at' => now()->addDay()]);
        $this->page('nonarticle-category', ['category' => 'دستهٔ صفحه'], kind: 'page');
        for ($i = 0; $i < 51; $i++) {
            $this->page('category-'.$i, ['category' => 'Category-'.$i]);
        }
        foreach (['a', 'b'] as $suffix) {
            $this->page('popular-category-'.$suffix, ['category' => 'Category-0']);
        }
        $facets = app(ContentCategories::class)->facets(ContentPage::where('kind', 'article'));
        $this->assertCount(50, $facets);
        $this->assertSame('Category-0', $facets[0]);
        foreach (['دستهٔ خصوصی', 'دستهٔ بسته', 'دستهٔ آینده', 'دستهٔ صفحه'] as $label) {
            $this->assertNotContains($label, $facets);
            $this->get('/journal')->assertDontSee($label);
        }
    }

    public function test_scheduled_category_is_frozen_private_until_due_and_later_cancelled_snapshot_keeps_value(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->page('scheduled-category', ['category' => 'دستهٔ فعلی']);
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => 'دستهٔ زمان‌بندی']);
        $page->refresh();
        $at = CarbonImmutable::now()->addHour();
        $schedule = $service->schedule($this->admin, $page, $page->revision, $at, true);
        $this->get('/journal')->assertDontSee('دستهٔ زمان‌بندی')->assertSee('دستهٔ فعلی');
        $this->travelTo($at->addMinute());
        $this->assertSame('published', $service->processSchedule($schedule));
        $this->get('/journal')->assertSee('دستهٔ زمان‌بندی')->assertDontSee('دستهٔ فعلی');
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => 'برنامهٔ دوم']);
        $page->refresh();
        $next = $service->schedule($this->admin, $page, $page->revision, CarbonImmutable::now()->addHour(), true);
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => 'پیش‌نویس آخر']);
        $this->assertSame('cancelled', $next->fresh()->status);
        $this->assertSame('برنامهٔ دوم', $next->fresh()->definition['category']);
        $this->get('/journal')->assertDontSee('پیش‌نویس آخر')->assertDontSee('برنامهٔ دوم');
        $this->travelBack();
    }

    public function test_corrupt_category_with_valid_hash_is_hidden_from_html_sitemap_alias_and_rss(): void
    {
        $page = $this->page('corrupt-category', ['category' => 'دستهٔ سالم']);
        app(ContentRedirects::class)->create($this->admin, $page, 'old-corrupt-category');
        $definition = [...$page->currentPublication->definition, 'category' => []];
        $page->currentPublication->update(['definition' => $definition, 'content_hash' => app(ContentPublishing::class)->hash($definition)]);
        $before = $this->snapshot();
        $this->get($page->publicPath())->assertNotFound();
        $this->get('/journal/old-corrupt-category')->assertNotFound();
        foreach (['/', '/journal', '/sitemap.xml', '/journal/feed.xml'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('/journal/corrupt-category', false)->assertDontSee('دستهٔ سالم');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_plain_html_category_and_pagination_preserve_only_validated_filters(): void
    {
        $category = '<img src=x onerror=window.categoryXss=1>';
        for ($i = 1; $i <= 13; $i++) {
            $this->page('paged-category-'.$i, ['category' => $category]);
        }
        $this->get('/journal/paged-category-1')->assertOk()->assertSee('&lt;img src=x', false)->assertDontSee($category, false)->assertSee('category=%3Cimg', false);
        $query = http_build_query(['category' => $category, 'tag' => 'رشد', 'q' => 'عمومی', 'token' => 'private-tracking']);
        $this->get('/journal?'.$query)->assertOk()->assertSee('page=2', false)->assertSee('category=', false)->assertSee('tag=', false)->assertSee('q=', false)->assertDontSee('private-tracking');
        $this->get('/journal?'.http_build_query(['category' => $category, 'tag' => 'رشد', 'q' => 'عمومی', 'page' => 2]))->assertOk()->assertSee('href="/journal/paged-category-1"', false);
        $this->getJson('/journal?category[]=bad')->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->getJson('/journal?category='.str_repeat('a', 41))->assertUnprocessable()->assertJsonValidationErrors('category');
        Http::assertNothingSent();
    }

    public function test_three_nonadmin_roles_cannot_write_category_and_legacy_rs_s_does_not_publish_private_category(): void
    {
        $page = $this->page('private-category-feed', ['category' => 'دستهٔ عمومی']);
        app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, [...$page->draft, 'category' => 'دستهٔ محرمانه']);
        $this->get('/journal/feed.xml')->assertOk()->assertDontSee('دستهٔ محرمانه')->assertDontSee('<category>', false);
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('key', $role)->value('id'));
            $before = $this->snapshot();
            $this->actingAs($user)->patchJson('/admin/content/'.$page->id.'/draft', ['revision' => $page->fresh()->revision, 'definition' => $this->definition(['category' => 'غیرمجاز'])])->assertForbidden();
            $this->assertSame($before, $this->snapshot());
        }
    }
}
