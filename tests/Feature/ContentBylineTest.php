<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use App\Services\ContentRedirects;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentBylineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['name' => 'نام خصوصی حساب ناشر']);
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function definition(array $changes = []): array
    {
        return [...['title' => 'مقاله عمومی', 'excerpt' => 'خلاصه عمومی', 'body' => 'متن عمومی', 'meta_description' => '', 'tags' => ['رشد'], 'category' => 'حرفه'], ...$changes];
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

    public function test_optional_empty_byline_preserves_legacy_definition_and_hash_with_existing_tags(): void
    {
        $service = app(ContentPublishing::class);
        $original = $service->definition($this->definition());
        foreach ([null, '', '   ', "\u{00a0} "] as $value) {
            $same = $service->definition($this->definition(['byline' => $value]));
            $this->assertSame($original, $same);
            $this->assertArrayNotHasKey('byline', $same);
            $this->assertSame($service->hash($original), $service->hash($same));
        }
        $page = $this->page('legacy-byline');
        $before = $this->snapshot();
        foreach ([$page->publicPath(), '/', '/journal', '/journal/feed.xml'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('data-public-article-byline', false)->assertDontSee('نام خصوصی حساب ناشر');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidBylines(): array
    {
        return [[0], [false], [[]], [['name' => 'byline']], [str_repeat('ن', 81)], ["bad\nbyline"], ["bad\0byline"], ["\xff"], [new \stdClass]];
    }

    #[DataProvider('invalidBylines')]
    public function test_invalid_byline_cannot_create_or_change_content_or_audit(mixed $byline): void
    {
        $page = $this->page('valid-before-byline');
        $before = $this->snapshot();
        foreach ([fn () => app(ContentPublishing::class)->create($this->admin, 'article', 'invalid-byline', $this->definition(['byline' => $byline])), fn () => app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, $this->definition(['byline' => $byline]))] as $operation) {
            try {
                $operation();
                $this->fail('Invalid byline accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('byline', $error->errors());
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_private_edit_republication_and_removal_preserve_old_versions_tags_and_alias(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->page('versioned-byline', ['byline' => 'نویسندهٔ اول']);
        $first = $page->currentPublication->getAttributes();
        $alias = app(ContentRedirects::class)->create($this->admin, $page, 'old-byline');
        $aliasBefore = $alias->fresh()->getAttributes();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'نویسندهٔ خصوصی']);
        foreach ([$page->publicPath(), '/journal', '/'] as $path) {
            $this->get($path)->assertOk()->assertSee('نویسندهٔ اول')->assertDontSee('نویسندهٔ خصوصی');
        }
        $page->refresh();
        $second = $service->publish($this->admin, $page, $page->revision);
        $this->get($page->publicPath())->assertSee('نویسندهٔ خصوصی')->assertDontSee('نویسندهٔ اول');
        $this->assertSame($first, $page->publications()->where('number', 1)->sole()->getAttributes());
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => '']);
        $page->refresh();
        $third = $service->publish($this->admin, $page, $page->revision);
        $this->assertArrayNotHasKey('byline', $third->definition);
        $this->assertSame(['رشد'], $third->definition['tags']);
        $this->assertSame('حرفه', $third->definition['category']);
        $this->assertSame('نویسندهٔ خصوصی', $second->fresh()->definition['byline']);
        $this->assertSame($aliasBefore, $alias->fresh()->getAttributes());
        $this->get('/journal/old-byline')->assertRedirect('/journal/versioned-byline');
        $this->get($page->publicPath())->assertDontSee('data-public-article-byline', false);
    }

    public function test_scheduled_byline_is_frozen_private_until_due_and_later_cancelled_snapshot_keeps_value(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->page('scheduled-byline', ['byline' => 'نویسندهٔ فعلی']);
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'نویسندهٔ زمان‌بندی']);
        $page->refresh();
        $at = CarbonImmutable::now()->addHour();
        $schedule = $service->schedule($this->admin, $page, $page->revision, $at, true);
        $this->get('/journal')->assertDontSee('نویسندهٔ زمان‌بندی')->assertSee('نویسندهٔ فعلی');
        $this->travelTo($at->addMinute());
        $this->assertSame('published', $service->processSchedule($schedule));
        $this->get('/journal')->assertSee('نویسندهٔ زمان‌بندی')->assertDontSee('نویسندهٔ فعلی');
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'برنامهٔ دوم']);
        $page->refresh();
        $next = $service->schedule($this->admin, $page, $page->revision, CarbonImmutable::now()->addHour(), true);
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'پیش‌نویس آخر']);
        $this->assertSame('cancelled', $next->fresh()->status);
        $this->assertSame('برنامهٔ دوم', $next->fresh()->definition['byline']);
        $this->actingAs($this->admin)->get('/admin/content/'.$page->id)->assertInertia(fn (Assert $view) => $view->where('page.draft.byline', 'پیش‌نویس آخر')->where('schedules.data.0.definition.byline', 'برنامهٔ دوم')->where('publications.data.0.definition.byline', 'نویسندهٔ زمان‌بندی'));
        $this->get('/journal')->assertDontSee('پیش‌نویس آخر')->assertDontSee('برنامهٔ دوم');
        $this->travelBack();
    }

    public function test_corrupt_byline_with_valid_hash_is_hidden_from_html_sitemap_alias_and_rss(): void
    {
        $page = $this->page('corrupt-byline', ['byline' => 'نویسندهٔ سالم']);
        app(ContentRedirects::class)->create($this->admin, $page, 'old-corrupt-byline');
        $definition = [...$page->currentPublication->definition, 'byline' => []];
        $page->currentPublication->update(['definition' => $definition, 'content_hash' => app(ContentPublishing::class)->hash($definition)]);
        $before = $this->snapshot();
        $this->get($page->publicPath())->assertNotFound();
        $this->get('/journal/old-corrupt-byline')->assertNotFound();
        foreach (['/', '/journal', '/sitemap.xml', '/journal/feed.xml'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('/journal/corrupt-byline', false)->assertDontSee('نویسندهٔ سالم');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_three_nonadmin_roles_cannot_write_byline_and_legacy_rs_s_does_not_publish_private_byline(): void
    {
        $page = $this->page('private-byline-feed', ['byline' => 'نویسندهٔ عمومی']);
        app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'نویسندهٔ محرمانه']);
        $this->get('/journal/feed.xml')->assertOk()->assertDontSee('نویسندهٔ محرمانه')->assertDontSee('<author>', false)->assertDontSee($this->admin->email);
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('key', $role)->value('id'));
            $before = $this->snapshot();
            $this->actingAs($user)->patchJson('/admin/content/'.$page->id.'/draft', ['revision' => $page->fresh()->revision, 'definition' => $this->definition(['byline' => 'غیرمجاز'])])->assertForbidden();
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_explicit_normalized_name_is_frozen_and_zero_and_eighty_characters_are_supported(): void
    {
        $page = $this->page('normalized-byline', ['byline' => "\u{00a0}نام  نویسنده\u{2003}"]);
        $this->assertSame('نام نویسنده', $page->currentPublication->definition['byline']);
        $this->admin->update(['name' => 'نام تازه خصوصی حساب']);
        foreach ([$page->publicPath(), '/journal', '/'] as $path) {
            $this->get($path)->assertOk()->assertSee('<bdi dir="auto">نام نویسنده</bdi>', false)->assertDontSee('نام تازه خصوصی حساب');
        }
        $zero = $this->page('zero-byline', ['byline' => '0']);
        $this->assertSame('0', $zero->draft['byline']);
        $this->get($zero->publicPath())->assertSee('<bdi dir="auto">0</bdi>', false);
        $maximum = $this->page('maximum-byline', ['byline' => str_repeat('ن', 80)]);
        $this->assertSame(str_repeat('ن', 80), $maximum->currentPublication->definition['byline']);
    }

    public function test_html_name_is_literal_and_pages_do_not_display_article_authorship(): void
    {
        $name = '<img src=x onerror=window.bylineXss=1>';
        $page = $this->page('literal-byline', ['byline' => $name]);
        foreach ([$page->publicPath(), '/', '/journal'] as $path) {
            $this->get($path)->assertOk()->assertSee('&lt;img src=x', false)->assertDontSee($name, false);
        }
        $this->get('/journal/feed.xml')->assertOk()->assertSee('/journal/literal-byline', false)->assertDontSee('<author>', false)->assertDontSee('bylineXss')->assertDontSee($this->admin->email);
        $standalone = $this->page('page-byline', ['byline' => 'نام صفحه خصوصی'], kind: 'page');
        $this->get($standalone->publicPath())->assertOk()->assertDontSee('data-public-article-byline', false)->assertDontSee('نام صفحه خصوصی');
        Http::assertNothingSent();
    }

    public function test_invalid_scheduled_name_with_matching_hash_is_blocked_without_changing_publication(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->page('corrupt-scheduled-byline', ['byline' => 'نویسنده قبلی']);
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'byline' => 'نویسنده آینده']);
        $page->refresh();
        $at = CarbonImmutable::now()->addHour();
        $schedule = $service->schedule($this->admin, $page, $page->revision, $at, true);
        $invalid = [...$schedule->definition, 'byline' => []];
        $page->update(['draft' => $invalid]);
        $schedule->update(['definition' => $invalid, 'definition_hash' => $service->hash($invalid)]);
        $this->travelTo($at->addMinute());
        $this->assertSame('blocked', $service->processSchedule($schedule));
        $this->assertSame('invalid_content', $schedule->fresh()->reason);
        $this->assertSame(1, $page->publications()->count());
        $this->get($page->publicPath())->assertSee('نویسنده قبلی')->assertDontSee('نویسنده آینده');
        $this->travelBack();
    }

    public function test_admin_http_validation_rejects_invalid_name_without_changing_version_or_audit(): void
    {
        $page = $this->page('http-byline', ['byline' => 'نام فعلی']);
        $before = $this->snapshot();
        $this->actingAs($this->admin)->patchJson('/admin/content/'.$page->id.'/draft', ['revision' => $page->revision, 'definition' => $this->definition(['byline' => str_repeat('ن', 81)])])->assertUnprocessable()->assertJsonValidationErrors('byline');
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/admin/content', ['kind' => 'article', 'slug' => 'invalid-http-byline', 'definition' => $this->definition(['byline' => []])])->assertUnprocessable()->assertJsonValidationErrors('byline');
        $this->assertSame($before, $this->snapshot());
    }
}
