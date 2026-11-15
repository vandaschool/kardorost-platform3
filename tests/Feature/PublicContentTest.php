<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\OrganizationLicense;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function page(array $changes = []): ContentPage
    {
        return ContentPage::factory()->create(['created_by' => $this->admin->id, ...$changes]);
    }

    private function publish(ContentPage $page): void
    {
        app(ContentPublishing::class)->publish($this->admin, $page, $page->fresh()->revision);
    }

    public function test_public_routes_render_server_content_and_panels_remain_protected(): void
    {
        foreach (['/', '/catalog', '/journal', '/sitemap.xml', '/robots.txt'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/')->assertSee('dir="rtl"', false)->assertSee('خودشناسی شغلی')->assertSee('هنوز دوره‌ای منتشر نشده')->assertDontSee('data-page=', false);
        $this->get('/student')->assertRedirect('/login');
        $this->get('/admin/content')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_drafts_are_absent_from_public_pages_journal_footer_and_sitemap(): void
    {
        $page = $this->page(['slug' => 'private-draft', 'draft' => ['title' => 'عنوان محرمانه پیش‌نویس', 'excerpt' => 'خلاصه', 'body' => 'متن محرمانه', 'meta_description' => '']]);
        $this->get($page->publicPath())->assertNotFound();
        foreach (['/', '/journal', '/sitemap.xml'] as $path) {
            $this->get($path)->assertDontSee('private-draft')->assertDontSee('عنوان محرمانه');
        }
    }

    public function test_publication_exposes_escaped_server_rendered_content_and_metadata(): void
    {
        $page = $this->page(['slug' => 'safe-article', 'draft' => ['title' => 'عنوان منتشرشده', 'excerpt' => 'خلاصه عمومی', 'body' => '<script>window.secret=1</script>'."\n\n".'بند دوم مقاله', 'meta_description' => 'توضیح جست‌وجو " نقل قول']]);
        $this->publish($page);
        $this->get('/journal/safe-article')->assertOk()->assertSee('عنوان منتشرشده')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>window.secret', false)->assertSee('بند دوم مقاله')->assertSee('name="description"', false)->assertSee('rel="canonical"', false)->assertSee('og:type" content="article', false)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/journal')->assertSee('عنوان منتشرشده');
        $this->get('/sitemap.xml')->assertSee('/journal/safe-article');
    }

    public function test_draft_save_does_not_change_published_snapshot_until_next_explicit_publish(): void
    {
        $page = $this->page();
        $this->publish($page);
        $first = $page->publications()->firstOrFail();
        $definition = [...$page->draft, 'title' => 'نسخه بعدی عنوان', 'body' => 'متن تازه منتشرنشده'];
        app(ContentPublishing::class)->draft($this->admin, $page, 2, $definition);
        $this->get($page->publicPath())->assertSee($first->definition['title'])->assertDontSee('متن تازه منتشرنشده');
        $this->publish($page);
        $this->get($page->publicPath())->assertSee('نسخه بعدی عنوان')->assertSee('متن تازه منتشرنشده');
        $this->assertSame(2, $page->publications()->count());
        $this->assertSame($first->definition, $first->fresh()->definition);
    }

    public function test_non_admin_roles_cannot_create_edit_publish_or_withdraw_content(): void
    {
        $page = $this->page();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/content')->assertForbidden();
            $this->get('/admin/content/'.$page->id)->assertForbidden();
            $this->post('/admin/content', ['kind' => 'article', 'slug' => 'forged-article', 'definition' => $page->draft])->assertForbidden();
            $this->patch('/admin/content/'.$page->id.'/draft', ['revision' => 1, 'definition' => $page->draft])->assertForbidden();
            $this->post('/admin/content/'.$page->id.'/publish', ['revision' => 1])->assertForbidden();
            $this->post('/admin/content/'.$page->id.'/availability', ['revision' => 1, 'available' => true])->assertForbidden();
        }
    }

    public function test_admin_creation_starts_private_and_duplicate_slug_is_rejected(): void
    {
        $definition = $this->page()->draft;
        $this->actingAs($this->admin)->post('/admin/content', ['kind' => 'article', 'slug' => 'new-article', 'definition' => $definition])->assertRedirect();
        $this->assertDatabaseHas('content_pages', ['slug' => 'new-article', 'available' => false, 'current_version' => 0]);
        $this->post('/admin/content', ['kind' => 'article', 'slug' => 'new-article', 'definition' => $definition])->assertSessionHasErrors('content');
        $this->assertSame(1, ContentPage::where('slug', 'new-article')->count());
    }

    public function test_stale_draft_publish_and_availability_forms_are_rejected_without_history_changes(): void
    {
        $page = $this->page();
        $this->publish($page);
        $this->actingAs($this->admin)->patch('/admin/content/'.$page->id.'/draft', ['revision' => 1, 'definition' => [...$page->draft, 'title' => 'قدیمی']])->assertSessionHasErrors('content');
        $this->post('/admin/content/'.$page->id.'/publish', ['revision' => 1])->assertSessionHasErrors('content');
        $this->post('/admin/content/'.$page->id.'/availability', ['revision' => 1, 'available' => false])->assertSessionHasErrors('content');
        $this->assertSame(1, ContentPublication::count());
        $this->assertSame(1, AuditEvent::where('action', 'content.published')->count());
        $this->assertTrue($page->fresh()->available);
    }

    public function test_duplicate_publication_is_rejected_and_withdrawal_can_restore_the_same_snapshot(): void
    {
        $page = $this->page();
        $this->publish($page);
        $this->actingAs($this->admin)->post('/admin/content/'.$page->id.'/publish', ['revision' => 2])->assertSessionHasErrors('content');
        $this->post('/admin/content/'.$page->id.'/availability', ['revision' => 2, 'available' => false])->assertRedirect();
        $this->get($page->publicPath())->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee($page->publicPath());
        $this->post('/admin/content/'.$page->id.'/availability', ['revision' => 3, 'available' => true])->assertRedirect();
        $this->get($page->publicPath())->assertOk();
        $this->assertSame(1, ContentPublication::count());
        $this->assertSame(2, AuditEvent::where('action', 'content.availability_changed')->count());
    }

    public function test_display_cannot_be_enabled_before_first_publication(): void
    {
        $page = $this->page();
        $this->actingAs($this->admin)->post('/admin/content/'.$page->id.'/availability', ['revision' => 1, 'available' => true])->assertSessionHasErrors('content');
        $this->assertFalse($page->fresh()->available);
        $this->assertSame(1, $page->fresh()->revision);
    }

    public function test_slug_kind_reserved_home_and_definition_validation(): void
    {
        $definition = $this->page()->draft;
        $this->actingAs($this->admin);
        foreach ([['kind' => 'invented', 'slug' => 'new-content'], ['kind' => 'home', 'slug' => 'other-name'], ['kind' => 'article', 'slug' => 'home'], ['kind' => 'page', 'slug' => '../secret'], ['kind' => 'article', 'slug' => 'invalid slug']] as $bad) {
            $this->post('/admin/content', [...$bad, 'definition' => $definition])->assertSessionHasErrors();
        }
        foreach ([['title' => ''], ['body' => ''], ['meta_description' => str_repeat('a', 161)]] as $bad) {
            $this->post('/admin/content', ['kind' => 'article', 'slug' => 'new-content', 'definition' => [...$definition, ...$bad]])->assertSessionHasErrors();
        }
        $this->assertSame(1, ContentPage::count());
    }

    public function test_home_custom_copy_is_versioned_and_withdrawal_restores_brand_default(): void
    {
        $home = $this->page(['kind' => 'home', 'slug' => 'home', 'draft' => ['title' => 'عنوان اصلی سفارشی', 'excerpt' => 'خلاصه صفحه اصلی سفارشی', 'body' => 'متن اصلی سفارشی', 'meta_description' => '']]);
        $this->get('/')->assertDontSee('عنوان اصلی سفارشی');
        $this->publish($home);
        $this->get('/')->assertSee('عنوان اصلی سفارشی')->assertSee('متن اصلی سفارشی')->assertSee('content="خلاصه صفحه اصلی سفارشی"', false);
        app(ContentPublishing::class)->availability($this->admin, $home, 2, false);
        $this->get('/')->assertDontSee('عنوان اصلی سفارشی')->assertSee('یک قدم روشن‌تر');
    }

    public function test_standalone_pages_are_namespaced_and_footer_uses_published_title_only(): void
    {
        $page = $this->page(['kind' => 'page', 'slug' => 'about-kardorost']);
        $this->publish($page);
        $this->get('/pages/about-kardorost')->assertOk();
        $this->get('/journal/about-kardorost')->assertNotFound();
        $this->get('/')->assertSee('/pages/about-kardorost');
        app(ContentPublishing::class)->draft($this->admin, $page, 2, [...$page->draft, 'title' => 'عنوان خصوصی تازه']);
        $this->get('/')->assertDontSee('عنوان خصوصی تازه');
    }

    public function test_public_course_details_show_real_capacity_but_no_class_links_or_accounts(): void
    {
        $cohort = Cohort::factory()->create(['capacity' => 5, 'starts_at' => now()->addWeek()]);
        $cohort->course->update(['is_published' => true]);
        Enrollment::factory()->create(['cohort_id' => $cohort->id]);
        OrganizationLicense::factory()->create(['cohort_id' => $cohort->id, 'seat_limit' => 2]);
        LiveSession::factory()->create(['cohort_id' => $cohort->id, 'meeting_url' => 'https://meet.google.com/private-secret-link']);
        $this->get('/catalog/'.$cohort->course->slug)->assertOk()->assertSee('۲ ظرفیت آزاد')->assertSee('datetime=', false)->assertDontSee('private-secret-link')->assertDontSee($this->admin->phone);
        $this->get('/catalog')->assertSee($cohort->course->title);
    }

    public function test_unpublished_courses_and_closed_cohorts_are_not_leaked(): void
    {
        $course = Course::factory()->create(['title' => 'دوره پیش‌نویس خصوصی', 'is_published' => false]);
        $this->get('/catalog/'.$course->slug)->assertNotFound();
        foreach (['/', '/catalog', '/sitemap.xml'] as $path) {
            $this->get($path)->assertDontSee($course->slug)->assertDontSee('دوره پیش‌نویس خصوصی');
        }
        $course->update(['is_published' => true]);
        Cohort::factory()->create(['course_id' => $course->id, 'registration_open' => false, 'title' => 'نوبت بسته محرمانه']);
        $this->get('/catalog/'.$course->slug)->assertDontSee('نوبت بسته محرمانه');
    }

    public function test_public_catalog_filters_stage_and_literal_wildcard_titles_and_paginates(): void
    {
        Course::factory()->count(13)->create(['is_published' => true, 'stage' => 1]);
        $target = Course::factory()->create(['is_published' => true, 'stage' => 2, 'title' => 'دوره صددرصد 100%']);
        $this->get('/catalog?stage=2&q=100%25')->assertSee($target->title);
        $this->get('/catalog?q=100%25')->assertViewHas('courses', fn ($courses) => $courses->total() === 1);
        $this->get('/catalog?stage=1')->assertSee('صفحه بعد')->assertViewHas('courses', fn ($courses) => $courses->total() === 13);
        $this->get('/catalog?stage=1&page=2')->assertViewHas('courses', fn ($courses) => $courses->count() === 1);
    }

    public function test_journal_search_and_pagination_only_use_current_publications(): void
    {
        foreach (range(1, 13) as $index) {
            $page = $this->page(['draft' => ['title' => 'یادداشت منتشرشده '.$index, 'excerpt' => 'خلاصه', 'body' => 'متن', 'meta_description' => '']]);
            $this->publish($page);
        }
        $this->get('/journal')->assertSee('صفحه بعد')->assertViewHas('articles', fn ($articles) => $articles->total() === 13);
        $this->get('/journal?q='.urlencode('منتشرشده 13'))->assertViewHas('articles', fn ($articles) => $articles->total() === 1);
        $this->get('/journal?page=2')->assertViewHas('articles', fn ($articles) => $articles->count() === 1);
    }

    public function test_admin_editor_has_only_scoped_publication_history(): void
    {
        $page = $this->page();
        $this->publish($page);
        $other = $this->page();
        $this->publish($other);
        $this->actingAs($this->admin)->get('/admin/content/'.$page->id)->assertInertia(fn (Assert $response) => $response->has('publications.data', 1)->where('publications.data.0.definition', $page->draft)->where('page.public_path', $page->publicPath()));
    }

    public function test_guest_course_entry_resumes_after_password_login_without_accepting_arbitrary_next(): void
    {
        $student = $this->user('student');
        $student->update(['password' => 'Public-Test-2026']);
        $this->get('/courses')->assertRedirect('/login');
        $this->post('/login', ['phone' => $student->phone, 'password' => 'Public-Test-2026', 'next' => 'https://external.invalid/'])->assertRedirect('/courses');
        $this->get('/courses')->assertOk();
    }
}
