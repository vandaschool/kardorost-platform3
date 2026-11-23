<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\ContentRedirect;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use App\Services\ContentRedirects;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContentRedirectTest extends TestCase
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

    private function page(string $slug = 'current-article', string $kind = 'article', bool $publish = true): ContentPage
    {
        $service = app(ContentPublishing::class);
        $page = $service->create($this->admin, $kind, $slug, ['title' => 'عنوان عمومی '.$slug, 'excerpt' => 'خلاصه عمومی', 'body' => 'متن عمومی', 'meta_description' => '']);
        if ($publish) {
            $service->publish($this->admin, $page, 1);
        }

        return $page->fresh();
    }

    private function fingerprint(): array
    {
        $state = [];
        foreach (['content_pages', 'content_publications', 'content_schedules', 'content_redirects', 'audit_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    public function test_actual_admin_create_and_anonymous_head_get_use_relative_location_drop_query_and_do_not_write(): void
    {
        $page = $this->page();
        $this->actingAs($this->admin)->from('/admin/content/'.$page->id)->post('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'previous-article'])->assertRedirect('/admin/content/'.$page->id);
        $redirect = ContentRedirect::sole();
        $this->assertSame('/journal/previous-article', $redirect->sourcePath());
        $this->assertSame($this->admin->id, $redirect->created_by);
        $before = $this->fingerprint();
        auth()->logout();
        $this->withHeader('Host', 'untrusted.example')->get('/journal/previous-article?next=https://evil.test&token=private')->assertStatus(302)->assertHeader('Location', '/journal/current-article')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, follow')->assertDontSee('token=private');
        $this->head('/journal/previous-article')->assertStatus(302)->assertHeader('Location', '/journal/current-article')->assertContent('');
        $this->post('/journal/previous-article')->assertStatus(405);
        $this->assertSame($before, $this->fingerprint());
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('previous-article');
        $this->get('/journal/feed.xml')->assertOk()->assertDontSee('previous-article');
    }

    public function test_redirect_creation_does_not_publish_private_draft_cancel_schedule_or_change_parent_revision(): void
    {
        $page = $this->page();
        $service = app(ContentPublishing::class);
        $service->draft($this->admin, $page, $page->revision, [...$page->draft, 'body' => 'متن خصوصی تازه']);
        $page->refresh();
        $service->schedule($this->admin, $page, $page->revision, CarbonImmutable::now()->addDays(2), true);
        $page->refresh();
        $attributes = $page->getAttributes();
        $publication = $page->currentPublication->getAttributes();
        $schedule = $page->schedules()->sole()->getAttributes();
        app(ContentRedirects::class)->create($this->admin, $page, 'legacy-draft');
        $this->get('/journal/legacy-draft')->assertRedirect('/journal/current-article');
        $this->get('/journal/current-article')->assertOk()->assertDontSee('متن خصوصی تازه');
        $this->assertSame($attributes, $page->fresh()->getAttributes());
        $this->assertSame($publication, $page->currentPublication->fresh()->getAttributes());
        $this->assertSame($schedule, $page->schedules()->sole()->getAttributes());
    }

    public function test_toggle_preserves_history_checks_revision_and_same_value_is_a_noop(): void
    {
        $page = $this->page();
        $service = app(ContentRedirects::class);
        $redirect = $service->create($this->admin, $page, 'old-toggle');
        $url = '/admin/content/'.$page->id.'/redirects/'.$redirect->id;
        $this->actingAs($this->admin)->post($url, ['revision' => 1, 'enabled' => false])->assertRedirect();
        $this->get('/journal/old-toggle')->assertNotFound();
        $before = $this->fingerprint();
        $this->postJson($url, ['revision' => 1, 'enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('from_slug');
        $service->change($this->admin, $page, $redirect, 2, false);
        $this->assertSame($before, $this->fingerprint());
        $service->change($this->admin, $page, $redirect, 2, true);
        $this->get('/journal/old-toggle')->assertRedirect('/journal/current-article');
        $this->assertSame(3, $redirect->fresh()->revision);
        $this->assertSame(3, AuditEvent::where('subject_type', 'content_redirect')->count());
        $this->assertDatabaseCount('content_redirects', 1);
    }

    public static function invalidSlugs(): array
    {
        return array_map(fn ($value) => [$value], ['', 'ab', 'https://evil.test', '//evil.test', '/journal/old', 'old?next=x', 'old#fragment', 'old.name', 'old%2fpath', 'Old-Article', 'مقاله', "old\narticle", '-old', 'old-', 'old--article', str_repeat('a', 81)]);
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_external_or_malformed_sources_never_write(string $slug): void
    {
        $page = $this->page();
        $before = $this->fingerprint();
        try {
            app(ContentRedirects::class)->create($this->admin, $page, $slug);
            $this->fail('Invalid source accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('from_slug', $error->errors());
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_source_unique_within_kind_boundaries_and_cross_page_mutation_are_enforced(): void
    {
        $article = $this->page();
        $other = $this->page('another-article');
        $page = $this->page('current-page', 'page');
        $service = app(ContentRedirects::class);
        $redirect = $service->create($this->admin, $article, 'old-shared');
        $service->create($this->admin, $page, 'old-shared');
        $service->create($this->admin, $article, str_repeat('a', 80));
        $service->create($this->admin, $article, 'abc');
        $this->get('/journal/old-shared')->assertRedirect('/journal/current-article');
        $this->get('/pages/old-shared')->assertRedirect('/pages/current-page');
        $before = $this->fingerprint();
        $this->actingAs($this->admin)->postJson('/admin/content/'.$other->id.'/redirects', ['from_slug' => 'old-shared'])->assertUnprocessable();
        $this->postJson('/admin/content/'.$other->id.'/redirects/'.$redirect->id, ['revision' => 1, 'enabled' => false])->assertNotFound();
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_canonical_content_including_private_and_unavailable_always_takes_priority(): void
    {
        $page = $this->page();
        $service = app(ContentRedirects::class);
        $redirect = $service->create($this->admin, $page, 'later-canonical');
        $canonical = $this->page('later-canonical', publish: false);
        $this->get('/journal/later-canonical')->assertNotFound();
        app(ContentPublishing::class)->publish($this->admin, $canonical, 1);
        $this->get('/journal/later-canonical')->assertOk()->assertSee('عنوان عمومی later-canonical');
        $canonical->refresh();
        app(ContentPublishing::class)->availability($this->admin, $canonical, $canonical->revision, false);
        $this->get('/journal/later-canonical')->assertNotFound();
        $before = $this->fingerprint();
        $this->actingAs($this->admin)->postJson('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'later-canonical'])->assertUnprocessable();
        $this->postJson('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'current-article'])->assertUnprocessable();
        $service->change($this->admin, $page, $redirect, 1, false);
        $disabled = $this->fingerprint();
        $this->postJson('/admin/content/'.$page->id.'/redirects/'.$redirect->id, ['revision' => 2, 'enabled' => true])->assertUnprocessable();
        $this->assertSame($disabled, $this->fingerprint());
        $this->assertCount(count($before['content_redirects']), $disabled['content_redirects']);
    }

    public static function unavailableTargets(): array
    {
        return array_map(fn ($state) => [$state], ['unavailable', 'future', 'wrong-version', 'bad-hash', 'wrong-kind', 'invalid-tags']);
    }

    #[DataProvider('unavailableTargets')]
    public function test_current_invalid_or_hidden_targets_stop_redirect_without_rewriting_history(string $state): void
    {
        $page = $this->page();
        $redirect = app(ContentRedirects::class)->create($this->admin, $page, 'old-unavailable');
        match ($state) {
            'unavailable' => $page->update(['available' => false]),
            'future' => $page->currentPublication->update(['published_at' => now()->addDay()]),
            'wrong-version' => $page->update(['current_version' => 99]),
            'bad-hash' => $page->currentPublication->update(['content_hash' => str_repeat('0', 64)]),
            'wrong-kind' => $page->update(['kind' => 'page']),
            'invalid-tags' => $page->currentPublication->update(['definition' => $bad = [...$page->currentPublication->definition, 'tags' => ['']], 'content_hash' => app(ContentPublishing::class)->hash($bad)]),
        };
        $before = $this->fingerprint();
        $this->get('/journal/old-unavailable')->assertNotFound();
        $this->assertSame($before, $this->fingerprint());
        app(ContentRedirects::class)->change($this->admin, $page, $redirect, 1, false);
        $disabled = $this->fingerprint();
        $this->actingAs($this->admin)->postJson('/admin/content/'.$page->id.'/redirects/'.$redirect->id, ['revision' => 2, 'enabled' => true])->assertUnprocessable();
        $this->assertSame($disabled, $this->fingerprint());
    }

    public function test_unpublished_and_home_targets_cannot_register_redirects(): void
    {
        foreach ([$this->page('private-target', publish: false), $this->page('home', 'home')] as $page) {
            $before = $this->fingerprint();
            $this->actingAs($this->admin)->postJson('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'old-private'])->assertUnprocessable();
            $this->assertSame($before, $this->fingerprint());
        }
    }

    public function test_twenty_five_retained_sources_include_disabled_entries_and_reject_overflow(): void
    {
        $page = $this->page();
        ContentRedirect::factory()->count(24)->create(['content_page_id' => $page->id, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'enabled' => false]);
        app(ContentRedirects::class)->create($this->admin, $page, 'last-allowed');
        $before = $this->fingerprint();
        $this->actingAs($this->admin)->postJson('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'over-limit'])->assertUnprocessable();
        $this->assertSame($before, $this->fingerprint());
        $this->assertDatabaseCount('content_redirects', 25);
    }

    public function test_nonadmin_routes_and_stale_actor_activity_or_role_are_refused_without_writes(): void
    {
        $page = $this->page();
        $redirect = app(ContentRedirects::class)->create($this->admin, $page, 'old-private-admin');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $actor = $this->actor($role);
            $before = $this->fingerprint();
            $this->actingAs($actor)->get('/admin/content/'.$page->id)->assertForbidden();
            $this->postJson('/admin/content/'.$page->id.'/redirects', ['from_slug' => 'unauthorized-old'])->assertForbidden();
            $this->postJson('/admin/content/'.$page->id.'/redirects/'.$redirect->id, ['revision' => 1, 'enabled' => false])->assertForbidden();
            $this->assertSame($before, $this->fingerprint());
        }
        foreach (['inactive', 'lost-role'] as $state) {
            $actor = $this->actor('admin');
            $actor->load('roles');
            if ($state === 'inactive') {
                User::whereKey($actor->id)->update(['is_active' => false]);
            } else {
                $actor->roles()->detach();
            }
            $before = $this->fingerprint();
            foreach ([fn () => app(ContentRedirects::class)->create($actor, $page, 'stale-admin'), fn () => app(ContentRedirects::class)->change($actor, $page, $redirect, 1, false)] as $operation) {
                try {
                    $operation();
                    $this->fail('Stale admin accepted.');
                } catch (HttpException $error) {
                    $this->assertSame(403, $error->getStatusCode());
                }
            }
            $this->assertSame($before, $this->fingerprint());
        }
    }

    public function test_failed_audit_rolls_back_alias_creation_and_state_change(): void
    {
        $page = $this->page();
        $redirect = app(ContentRedirects::class)->create($this->admin, $page, 'old-audit');
        $before = $this->fingerprint();
        AuditEvent::creating(function ($event) {
            if ($event->subject_type === 'content_redirect') {
                throw new \RuntimeException('Synthetic audit failure.');
            }
        });
        try {
            foreach ([fn () => app(ContentRedirects::class)->create($this->admin, $page, 'new-audit'), fn () => app(ContentRedirects::class)->change($this->admin, $page, $redirect, 1, false)] as $operation) {
                try {
                    $operation();
                    $this->fail('Audit failure ignored.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('Synthetic audit failure.', $error->getMessage());
                }
                $this->assertSame($before, $this->fingerprint());
            }
        } finally {
            AuditEvent::flushEventListeners();
        }
    }
}
