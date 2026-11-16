<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountAccessRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
        $this->target = $this->user('student');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function change(int $revision, array $roles = ['student', 'coach'], bool $active = true): array
    {
        return ['revision' => $revision, 'roles' => $roles, 'is_active' => $active];
    }

    private function url(): string
    {
        return '/admin/users/'.$this->target->id;
    }

    public function test_directory_exposes_revision_and_successful_write_advances_it_once(): void
    {
        $this->assertSame(1, $this->target->access_revision);
        $this->actingAs($this->admin)->get('/admin/users?user_id='.$this->target->id)->assertInertia(fn (Assert $page) => $page->where('users.data.0.access_revision', 1));
        $this->patch($this->url(), $this->change(1))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->get('/admin/users?user_id='.$this->target->id)->assertInertia(fn (Assert $page) => $page->where('users.data.0.access_revision', 2));
        $this->assertSame(2, AuditEvent::where('action', 'user.access_updated')->sole()->metadata['access_revision']);
    }

    public function test_another_admin_stale_form_cannot_replace_new_roles_or_delete_sessions(): void
    {
        $other = $this->user('admin');
        DB::table('sessions')->insert(['id' => 'revision-target-session', 'user_id' => $this->target->id, 'payload' => 'synthetic', 'last_activity' => time()]);
        $this->actingAs($this->admin)->patch($this->url(), $this->change(1))->assertSessionHasNoErrors();
        $this->actingAs($other)->patchJson($this->url(), $this->change(1, ['student'], false))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertTrue($this->target->hasRole('coach'));
        $this->assertTrue($this->target->fresh()->is_active);
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->assertDatabaseHas('sessions', ['id' => 'revision-target-session']);
        $this->assertSame(1, AuditEvent::where('action', 'user.access_updated')->count());
    }

    public function test_returning_to_same_visible_state_does_not_make_original_form_current_again(): void
    {
        $this->actingAs($this->admin)->patch($this->url(), $this->change(1))->assertSessionHasNoErrors();
        $this->patch($this->url(), $this->change(2, ['student']))->assertSessionHasNoErrors();
        $this->assertFalse($this->target->hasRole('coach'));
        $this->assertSame(3, $this->target->fresh()->access_revision);
        $this->patchJson($this->url(), $this->change(1, ['student'], false))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertTrue($this->target->fresh()->is_active);
    }

    public function test_missing_invalid_and_invented_revisions_cannot_mutate_access(): void
    {
        foreach ([null, 0, -1, 'bad', [], 999] as $revision) {
            $data = $this->change(1);
            if ($revision === null) {
                unset($data['revision']);
            } else {
                $data['revision'] = $revision;
            }
            $this->actingAs($this->admin)->patchJson($this->url(), $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        }
        $this->assertSame(1, $this->target->fresh()->access_revision);
        $this->assertFalse($this->target->hasRole('coach'));
        $this->assertSame(0, AuditEvent::where('action', 'user.access_updated')->count());
    }

    public function test_rejected_self_lockout_does_not_advance_revision(): void
    {
        $this->actingAs($this->admin)->patchJson('/admin/users/'.$this->admin->id, $this->change(1, ['student'], false))->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->assertSame(1, $this->admin->fresh()->access_revision);
        $this->patch('/admin/users/'.$this->admin->id, $this->change(1, ['admin', 'coach']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->admin->fresh()->access_revision);
    }

    public function test_duplicate_accepted_save_with_original_revision_is_not_applied_twice(): void
    {
        $same = $this->change(1, ['student']);
        $this->actingAs($this->admin)->patch($this->url(), $same)->assertSessionHasNoErrors();
        $this->patchJson($this->url(), $same)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->assertSame(1, AuditEvent::where('action', 'user.access_updated')->count());
    }

    public function test_organization_creation_role_grant_invalidates_older_access_form(): void
    {
        $this->actingAs($this->admin)->post('/admin/organizations', ['name' => 'Synthetic new company', 'type' => 'company', 'phone' => $this->target->phone])->assertSessionHasNoErrors();
        $this->assertTrue($this->target->hasRole('org_admin'));
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->patchJson($this->url(), $this->change(1, ['student']))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertTrue($this->target->hasRole('org_admin'));
    }

    public function test_assign_manager_role_grant_advances_revision_only_when_account_role_changes(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $organization->members()->attach($this->target->id, ['kind' => 'member']);
        $key = $organization->members()->where('users.id', $this->target->id)->firstOrFail()->pivot->membership_key;
        app(OrganizationMembership::class)->assignManager($this->admin, $organization, $this->target->phone, $key);
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $other = Organization::factory()->create(['status' => 'active']);
        app(OrganizationMembership::class)->assignManager($this->admin, $other, $this->target->phone, null);
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->actingAs($this->admin)->patchJson($this->url(), $this->change(1))->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_console_role_grant_invalidates_old_form_but_duplicate_grant_does_not_advance(): void
    {
        $this->artisan('platform:grant-role', ['phone' => $this->target->phone, 'role' => 'coach'])->assertExitCode(0);
        $this->assertTrue($this->target->hasRole('coach'));
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->artisan('platform:grant-role', ['phone' => $this->target->phone, 'role' => 'coach'])->assertExitCode(0);
        $this->assertSame(2, $this->target->fresh()->access_revision);
        $this->actingAs($this->admin)->patchJson($this->url(), $this->change(1))->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_password_change_does_not_invalidate_unrelated_access_form(): void
    {
        $this->target->update(['password' => 'Current-password-2026']);
        $this->actingAs($this->target)->patch('/account/password', ['current_password' => 'Current-password-2026', 'password' => 'Changed-password-2026', 'password_confirmation' => 'Changed-password-2026'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->target->fresh()->access_revision);
        $this->flushSession();
        $this->actingAs($this->admin)->patch($this->url(), $this->change(1))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->target->fresh()->access_revision);
    }
}
