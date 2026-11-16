<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
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

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    public function test_only_current_active_admin_can_search_private_user_directory(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/users')->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/admin/users')->assertOk();
        $this->admin->roles()->detach();
        $this->get('/admin/users')->assertForbidden();
        $inactive = $this->user('admin');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get('/admin/users')->assertRedirect('/login');
    }

    public function test_twenty_five_users_per_page_and_older_accounts_remain_accessible(): void
    {
        $users = User::factory()->count(31)->create(['name' => 'Directory fixture']);
        $this->actingAs($this->admin)->get('/admin/users?q=Directory')->assertInertia(fn (Assert $page) => $page->component('AdminUsers')->where('users.total', 31)->has('users.data', 25)->where('users.data.0.id', $users->last()->id)->where('users.data.24.id', $users[6]->id));
        $this->get('/admin/users?q=Directory&page=2')->assertInertia(fn (Assert $page) => $page->has('users.data', 6)->where('users.data.5.id', $users->first()->id));
    }

    public function test_combined_name_role_activity_and_verification_filters_keep_multi_role_account_once(): void
    {
        $wanted = $this->user('student', ['name' => 'Directory Alice']);
        $wanted->roles()->attach(Role::where('key', 'coach')->value('id'));
        $this->user('coach', ['name' => 'Directory Alice']);
        $this->user('student', ['name' => 'Other name']);
        $inactive = $this->user('student', ['name' => 'Directory Alice']);
        $inactive->forceFill(['is_active' => false])->save();
        $this->user('student', ['name' => 'Directory Alice', 'phone_verified_at' => null]);
        $this->actingAs($this->admin)->get('/admin/users?q=directory%20alice&role=student&status=active&verification=verified')->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $wanted->id)->has('users.data.0.roles', 2));
        $this->get('/admin/users?q=Directory&status=inactive')->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $inactive->id));
        $this->get('/admin/users?q=Directory&verification=unverified')->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.phone_verified', false));
    }

    public function test_exact_phone_normalizes_local_international_and_persian_digits(): void
    {
        $wanted = $this->user('student', ['phone' => '989121234567']);
        $this->user('student', ['phone' => '989121234568']);
        foreach (['09121234567', '+98 912 123 4567', '00989121234567', '۰۹۱۲۱۲۳۴۵۶۷'] as $phone) {
            $this->actingAs($this->admin)->get('/admin/users?'.http_build_query(['phone' => $phone]))->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $wanted->id)->where('filters.phone', '989121234567'));
        }
    }

    public function test_name_percent_underscore_and_escape_are_literal_characters(): void
    {
        $wanted = $this->user('student', ['name' => 'Literal_100%!']);
        $this->user('student', ['name' => 'LiteralX100AB']);
        $this->actingAs($this->admin)->get('/admin/users?'.http_build_query(['q' => 'Literal_100%!']))->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $wanted->id));
    }

    public function test_exact_account_id_and_no_match_do_not_fall_back_to_other_users(): void
    {
        $wanted = $this->user('student');
        $this->actingAs($this->admin)->get('/admin/users?user_id='.$wanted->id)->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $wanted->id));
        $this->get('/admin/users?user_id='.$wanted->id.'&role=admin')->assertInertia(fn (Assert $page) => $page->where('users.total', 0)->has('users.data', 0));
        $this->get('/admin/users?page=200')->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
    }

    public function test_pagination_preserves_valid_filters_without_unknown_query_fields(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $this->user('student', ['name' => 'Directory fixture']);
        }
        $response = $this->actingAs($this->admin)->get('/admin/users?q=Directory&role=student&status=active&verification=verified&unknown=IGNORED')->assertInertia(fn (Assert $page) => $page->where('users.total', 26)->missing('filters.unknown'));
        $url = $response->viewData('page')['props']['users']['next_page_url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEquals(['q' => 'Directory', 'role' => 'student', 'status' => 'active', 'verification' => 'verified', 'page' => '2'], $query);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('users.current_page', 2)->has('users.data', 1));
    }

    public function test_rows_expose_only_directory_identity_and_role_fields_and_read_does_not_mutate(): void
    {
        $wanted = $this->user('student', ['name' => '<script>PRIVATE-DIRECTORY-NAME</script>', 'email' => 'PRIVATE-DIRECTORY-EMAIL@example.test', 'remember_token' => 'PRIVATE-DIRECTORY-TOKEN']);
        $before = $wanted->fresh()->getAttributes();
        $this->actingAs($this->admin)->get('/admin/users?user_id='.$wanted->id)->assertInertia(fn (Assert $page) => $page->where('users.data.0.name', $wanted->name)->where('users.data.0.phone', $wanted->phone)->where('users.data.0.phone_verified', true)->missing('users.data.0.password')->missing('users.data.0.remember_token')->missing('users.data.0.email')->missing('users.data.0.roles.0.pivot'))->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PRIVATE-DIRECTORY-EMAIL')->assertDontSee('PRIVATE-DIRECTORY-TOKEN');
        $this->assertSame($before, $wanted->fresh()->getAttributes());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_existing_access_edit_returns_to_filter_and_deactivated_user_leaves_active_results(): void
    {
        $wanted = $this->user('student', ['name' => 'Directory edit']);
        DB::table('sessions')->insert(['id' => 'directory-user-session', 'user_id' => $wanted->id, 'payload' => 'synthetic', 'last_activity' => time()]);
        $url = '/admin/users?q=Directory&role=student&status=active';
        $this->actingAs($this->admin)->from($url)->patch('/admin/users/'.$wanted->id, ['revision' => $wanted->access_revision, 'roles' => ['student', 'coach'], 'is_active' => false])->assertRedirect($url);
        $this->assertFalse($wanted->fresh()->is_active);
        $this->assertTrue($wanted->hasRole('coach'));
        $this->assertDatabaseMissing('sessions', ['id' => 'directory-user-session']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('users.total', 0));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->admin->id, 'subject_id' => $wanted->id, 'action' => 'user.access_updated']);
    }

    public function test_invalid_queries_are_rejected_and_sql_like_text_is_not_executed(): void
    {
        foreach (['q[]=x', 'q='.str_repeat('x', 151), 'phone=0', 'phone=0912', 'user_id=0', 'user_id[]=1', 'status=deleted', 'role=owner', 'verification=yes', 'page=-1', 'page=1000001'] as $query) {
            $this->actingAs($this->admin)->getJson('/admin/users?'.$query)->assertUnprocessable();
        }
        $this->get('/admin/users?q='.urlencode("' OR 1=1 --"))->assertInertia(fn (Assert $page) => $page->where('users.total', 0));
    }
}
