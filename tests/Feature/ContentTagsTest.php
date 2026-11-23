<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use App\Services\ContentTags;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContentTagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->actor('admin');
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function definition(array $changes = []): array
    {
        return [...['title' => 'مقاله عمومی', 'excerpt' => 'خلاصه عمومی', 'body' => 'متن مقاله', 'meta_description' => ''], ...$changes];
    }

    private function article(string $slug, array $changes = [], bool $publish = true): ContentPage
    {
        $service = app(ContentPublishing::class);
        $page = $service->create($this->admin, 'article', $slug, $this->definition($changes));
        if ($publish) {
            $service->publish($this->admin, $page, $page->revision);
        }

        return $page->fresh();
    }

    public function test_legacy_missing_null_and_empty_tags_keep_the_exact_definition_and_hash(): void
    {
        $service = app(ContentPublishing::class);
        $definition = $service->definition($this->definition());
        foreach ([[], ['tags' => null], ['tags' => []]] as $extra) {
            $same = $service->definition($this->definition($extra));
            $this->assertSame($definition, $same);
            $this->assertArrayNotHasKey('tags', $same);
            $this->assertSame($service->hash($definition), $service->hash($same));
        }
        $page = $this->article('legacy-article');
        $before = $page->currentPublication->getAttributes();
        $this->get($page->publicPath())->assertOk()->assertDontSee('data-public-article-tags', false);
        $this->assertSame($before, $page->currentPublication->fresh()->getAttributes());
    }

    public function test_labels_are_bounded_plain_text_and_numeric_labels_stay_strings_in_public_facets(): void
    {
        $page = $this->article('numeric-tags', ['tags' => ["\u{00a0}رشد  شغلی\u{00a0}", 'C', '0']]);
        $this->assertSame(['رشد شغلی', 'C', '0'], $page->draft['tags']);
        $this->assertSame($page->draft['tags'], $page->currentPublication->definition['tags']);
        $facets = app(ContentTags::class)->facets(ContentPage::where('kind', 'article')->with('currentPublication'));
        $this->assertContains('0', $facets);
        $this->assertNotContains(0, $facets);
        $this->get('/journal?tag=0')->assertOk()->assertSee('numeric-tags');
        Http::assertNothingSent();
    }

    public static function invalidTags(): array
    {
        return [
            'scalar' => ['رشد'], 'object' => [['label' => 'رشد']], 'not-list' => [[2 => 'رشد']],
            'too-many' => [range('a', 'i')], 'empty' => [['']], 'spaces' => [['  ']],
            'integer' => [[1]], 'nested' => [[['label' => 'رشد']]], 'long' => [[str_repeat('ف', 41)]],
            'duplicate' => [['رشد', ' رشد ']], 'invalid-utf8' => [["\xff"]], 'control' => [["رشد\0شغلی"]],
        ];
    }

    #[DataProvider('invalidTags')]
    public function test_invalid_tags_never_write_draft_publication_or_audit(mixed $tags): void
    {
        try {
            $this->article('invalid-tags', ['tags' => $tags]);
            $this->fail('Invalid metadata must not be stored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['tags'], array_keys($exception->errors()));
        }
        $this->assertDatabaseCount('content_pages', 0);
        $this->assertDatabaseCount('content_publications', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_saved_private_tags_do_not_change_public_tags_until_republication_and_removal_is_versioned(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->article('versioned-tags', ['tags' => ['انتشار اول']]);
        $first = $page->currentPublication->getAttributes();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'tags' => ['برچسب خصوصی']]);
        $this->get('/journal')->assertSee('انتشار اول')->assertDontSee('برچسب خصوصی');
        $this->get($page->publicPath())->assertSee('انتشار اول')->assertDontSee('برچسب خصوصی');
        $this->get('/journal?tag='.rawurlencode('برچسب خصوصی'))->assertDontSee('href="/journal/versioned-tags"', false);
        $page->refresh();
        $second = $service->publish($this->admin, $page, $page->revision);
        $this->get($page->publicPath())->assertSee('برچسب خصوصی')->assertDontSee('انتشار اول');
        $this->assertSame($first, $page->publications()->where('number', 1)->firstOrFail()->getAttributes());
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'tags' => []]);
        $page->refresh();
        $third = $service->publish($this->admin, $page, $page->revision);
        $this->assertArrayNotHasKey('tags', $third->definition);
        $this->assertSame(['برچسب خصوصی'], $second->fresh()->definition['tags']);
        $this->get($page->publicPath())->assertDontSee('data-public-article-tags', false);
    }

    public function test_public_filter_intersects_title_and_exact_tag_and_never_uses_private_page_or_future_tags(): void
    {
        $this->article('one-article', ['title' => 'یادگیری مهارت', 'tags' => ['مهارت', '%_!']]);
        $this->article('two-article', ['title' => 'تمرین مهارت', 'tags' => ['تمرین']]);
        $this->article('private-article', ['tags' => ['محرمانه پیش‌نویس']], false);
        $hidden = $this->article('hidden-article', ['tags' => ['محرمانه غیرفعال']]);
        $hidden->update(['available' => false]);
        $future = $this->article('future-article', ['tags' => ['محرمانه آینده']]);
        $future->currentPublication->update(['published_at' => now()->addHour()]);
        $service = app(ContentPublishing::class);
        $page = $service->create($this->admin, 'page', 'ordinary-page', $this->definition(['tags' => ['برچسب صفحه']]));
        $service->publish($this->admin, $page, $page->revision);
        $this->get('/journal')->assertOk()->assertSee('مهارت')->assertSee('تمرین')->assertDontSee('محرمانه')->assertDontSee('برچسب صفحه');
        $this->get('/journal?tag='.rawurlencode('%_!').'&q='.rawurlencode('یادگیری'))->assertOk()->assertSee('/journal/one-article', false)->assertDontSee('/journal/two-article', false)->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('/journal?tag='.rawurlencode('%'))->assertOk()->assertDontSee('href="/journal/one-article"', false);
    }

    public function test_scheduled_tag_snapshot_is_private_until_due_and_draft_edits_cancel_it(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->article('scheduled-tags', ['tags' => ['نسخه فعلی']]);
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'tags' => ['نسخه زمان‌بندی']]);
        $page->refresh();
        $at = CarbonImmutable::now()->addHour();
        $schedule = $service->schedule($this->admin, $page, $page->revision, $at, true);
        $this->get('/journal')->assertSee('نسخه فعلی')->assertDontSee('نسخه زمان‌بندی');
        $this->travelTo($at->addMinute());
        $this->assertSame('published', $service->processSchedule($schedule));
        $this->get('/journal')->assertSee('نسخه زمان‌بندی')->assertDontSee('نسخه فعلی');
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'tags' => ['برنامه دوم']]);
        $page->refresh();
        $next = $service->schedule($this->admin, $page, $page->revision, CarbonImmutable::now()->addHour(), true);
        $page->refresh();
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'tags' => ['پیش‌نویس آخر']]);
        $this->assertSame('cancelled', $next->fresh()->status);
        $this->assertSame(['برنامه دوم'], $next->fresh()->definition['tags']);
        $this->get('/journal')->assertSee('نسخه زمان‌بندی')->assertDontSee('پیش‌نویس آخر')->assertDontSee('برنامه دوم');
        $this->travelBack();
    }

    public function test_withdrawal_and_integrity_failures_remove_tags_cards_footer_and_sitemap_paths(): void
    {
        $service = app(ContentPublishing::class);
        $page = $this->article('withdraw-tags', ['tags' => ['برچسب قابل برداشت']]);
        $service->availability($this->admin, $page, $page->revision, false);
        $this->get('/journal')->assertDontSee('برچسب قابل برداشت');
        $bad = $this->article('corrupt-tags', ['title' => 'عنوان ناسازگار', 'tags' => ['برچسب ناسازگار']]);
        $bad->currentPublication->update(['content_hash' => str_repeat('0', 64)]);
        $this->get($bad->publicPath())->assertNotFound();
        foreach (['/', '/journal', '/sitemap.xml'] as $path) {
            $this->get($path)->assertDontSee('برچسب ناسازگار')->assertDontSee('عنوان ناسازگار')->assertDontSee('/journal/corrupt-tags', false);
        }
        $wrong = $this->article('wrong-version', ['tags' => ['نسخه ناسازگار']]);
        $wrong->update(['current_version' => 99]);
        $this->get($wrong->publicPath())->assertNotFound();
        $this->get('/journal')->assertDontSee('نسخه ناسازگار');
    }

    public function test_html_labels_are_plain_text_links_and_filtered_pagination_drops_unknown_query_values(): void
    {
        $tag = '<b>برچسب</b>';
        for ($i = 1; $i <= 13; $i++) {
            $this->article('paged-'.$i, ['tags' => [$tag]]);
        }
        $this->get('/journal/paged-1')->assertOk()->assertSee('&lt;b&gt;برچسب&lt;/b&gt;', false)->assertDontSee('<b>برچسب</b>', false)->assertSee('tag=%3Cb%3E', false);
        $this->get('/journal?tag='.rawurlencode($tag).'&token=private-tracking')->assertOk()->assertSee('page=2', false)->assertDontSee('private-tracking');
        $this->get('/journal?tag='.rawurlencode($tag).'&page=2')->assertOk()->assertSee('paged-1');
        Http::assertNothingSent();
    }

    public function test_private_tag_edits_do_not_reorder_the_public_journal(): void
    {
        $old = $this->article('old-public', ['title' => 'مقاله عمومی قدیمی', 'tags' => ['نسخه قدیمی']]);
        $this->travel(2)->minutes();
        $this->article('new-public', ['title' => 'مقاله عمومی جدید', 'tags' => ['نسخه جدید']]);
        $this->travel(2)->minutes();
        app(ContentPublishing::class)->draft($this->admin, $old, $old->revision, [...$old->draft, 'tags' => ['برچسب خصوصی تازه']]);
        $this->get('/journal')->assertSeeInOrder(['مقاله عمومی جدید', 'مقاله عمومی قدیمی'])->assertDontSee('برچسب خصوصی تازه');
        $this->travelBack();
    }

    public function test_non_admin_or_stale_admin_role_cannot_save_tagged_content(): void
    {
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->actor($role))->postJson('/admin/content', ['kind' => 'article', 'slug' => 'role-'.$role, 'definition' => $this->definition(['tags' => ['محرمانه']])])->assertForbidden();
        }
        $this->admin->roles()->detach();
        try {
            app(ContentPublishing::class)->create($this->admin, 'article', 'stale-admin', $this->definition(['tags' => ['محرمانه']]));
            $this->fail('Current role is required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('content_pages', 0);
    }

    public function test_invalid_public_tag_filter_is_rejected_without_writing_records(): void
    {
        $this->getJson('/journal?tag%5B%5D=unexpected')->assertUnprocessable()->assertJsonValidationErrors('tag');
        $this->getJson('/journal?tag='.rawurlencode(str_repeat('ف', 41)))->assertUnprocessable()->assertJsonValidationErrors('tag');
        $this->getJson('/journal?tag='.rawurlencode("رشد\0شغلی"))->assertUnprocessable()->assertJsonValidationErrors('tag');
        $this->getJson('/journal?tag=%FF')->assertUnprocessable()->assertJsonValidationErrors('tag');
        $this->getJson('/journal?q=%FF')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->assertDatabaseCount('content_pages', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
