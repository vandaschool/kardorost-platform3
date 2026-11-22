<?php

namespace Tests\Feature;

use App\Http\Controllers\OrganizationController;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationEnrollment;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationWorkflowCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['platform.sms.driver' => 'disabled', 'platform.sms.melipayamak.invitations_enabled' => true, 'platform.zibal.enabled' => false]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function csv(User $candidate): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('software-only-members70.csv', "phone\n".$candidate->phone."\n");
    }

    private function licenseData(int $revision = 0): array
    {
        return ['revision' => $revision, 'seat_limit' => 3, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'SOFTWARE-ONLY-QUOTA70'];
    }

    private function fixture(string $action): array
    {
        $admin = $this->user('admin');
        $manager = $this->user('org_admin');
        $member = $this->user('student');
        $candidate = $this->user('student');
        $backup = $this->user('org_admin');
        $organization = Organization::factory()->create(['status' => 'active']);
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($member->id, ['kind' => 'member']);
        $organization->members()->attach($backup->id, ['kind' => 'manager']);
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['price' => 1000000, 'is_published' => true]), 'capacity' => 10]);
        $invitation = in_array($action, ['revoke', 'renew'], true) ? OrganizationInvitation::factory()->create(['organization_id' => $organization->id, 'created_by' => $manager->id, 'phone' => $candidate->phone, 'status' => $action === 'renew' ? 'revoked' : 'pending']) : null;
        $import = in_array($action, ['commit', 'commit_committed'], true) ? app(OrganizationMembership::class)->preview($manager, $organization, $this->csv($candidate)) : null;
        if ($action === 'commit_committed') {
            app(OrganizationMembership::class)->commit($manager, $import);
        }
        $license = in_array($action, ['license_edit', 'enroll', 'enroll_duplicate'], true) ? app(OrganizationEnrollment::class)->configure($admin, $organization, $cohort, [...$this->licenseData(), 'seat_limit' => 2]) : null;
        if ($action === 'enroll_duplicate') {
            app(OrganizationEnrollment::class)->enroll($manager, $organization, $license, 1, [$member->id]);
        }

        return compact('admin', 'manager', 'member', 'candidate', 'backup', 'organization', 'cohort', 'invitation', 'import', 'license');
    }

    private function actor(string $action, array $fixture): User
    {
        return match ($action) {
            'leave' => $fixture['member'],
            'remove_admin', 'revoke_manager', 'assign_manager', 'assign_existing', 'license_new', 'license_edit', 'create_organization' => $fixture['admin'],
            default => $fixture['manager'],
        };
    }

    private function key(Organization $organization, User $member): string
    {
        return $organization->members()->where('users.id', $member->id)->firstOrFail()->pivot->membership_key;
    }

    private function perform(string $action, array $fixture): mixed
    {
        extract($fixture);
        $actor = $this->actor($action, $fixture);
        $membership = app(OrganizationMembership::class);
        if ($action === 'create_organization') {
            $request = Request::create('/admin/organizations', 'POST', ['name' => 'Software-only created organization70', 'type' => 'company', 'phone' => $candidate->phone]);
            $request->setUserResolver(fn () => $actor);

            return app(OrganizationController::class)->store($request);
        }

        return match ($action) {
            'invite' => $membership->invite($actor, $organization, $candidate->phone),
            'revoke', 'renew' => $membership->change($actor, $invitation, 1, $action),
            'preview' => $membership->preview($actor, $organization, $this->csv($candidate)),
            'commit', 'commit_committed' => $membership->commit($actor, $import),
            'leave' => $membership->endMembership($actor, $organization, $member, $this->key($organization, $member), 'Software-only departure', 'leave'),
            'remove', 'remove_admin' => $membership->endMembership($actor, $organization, $member, $this->key($organization, $member), 'Software-only removal', 'remove'),
            'revoke_manager' => $membership->endMembership($actor, $organization, $manager, $this->key($organization, $manager), 'Software-only manager change', 'revoke_manager'),
            'assign_manager' => $membership->assignManager($actor, $organization, $candidate->phone, null),
            'assign_existing' => $membership->assignManager($actor, $organization, $manager->phone, $this->key($organization, $manager)),
            'license_new', 'license_edit' => app(OrganizationEnrollment::class)->configure($actor, $organization, $cohort, $this->licenseData($license?->revision ?? 0), $license),
            'enroll', 'enroll_duplicate' => app(OrganizationEnrollment::class)->enroll($actor, $organization, $license, 1, [$member->id]),
        };
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['users', 'role_user', 'organizations', 'organization_user', 'organization_invitations', 'organization_invitation_imports', 'invitation_sms', 'organization_licenses', 'organization_seats', 'enrollments', 'installments', 'payments', 'enrollment_consultation_benefits', 'audit_events', 'notifications', 'jobs'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $state[$table] = $rows;
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function actions(): array
    {
        return array_map(fn ($action) => [$action], ['invite', 'revoke', 'renew', 'preview', 'commit', 'commit_committed', 'leave', 'remove', 'remove_admin', 'revoke_manager', 'assign_manager', 'assign_existing', 'license_new', 'license_edit', 'enroll', 'enroll_duplicate', 'create_organization']);
    }

    #[DataProvider('actions')]
    public function test_disabled_actor_cannot_change_organization_workflows_using_stale_activity(string $action): void
    {
        $fixture = $this->fixture($action);
        $actor = $this->actor($action, $fixture);
        User::whereKey($actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active);
        $before = $this->snapshot();
        try {
            $this->perform($action, $fixture);
            $this->fail('Current active authorization is required for organization workflows.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('actions')]
    public function test_current_actor_can_complete_existing_organization_workflow(string $action): void
    {
        $fixture = $this->fixture($action);
        extract($fixture);
        $before = $this->snapshot();
        $result = $this->perform($action, $fixture);
        if ($action === 'invite') {
            $this->assertTrue($result);
            $this->assertDatabaseCount('organization_invitations', 1);
            $this->assertDatabaseCount('invitation_sms', 1);
        } elseif (in_array($action, ['revoke', 'renew'], true)) {
            $this->assertSame($action === 'revoke' ? 'revoked' : 'pending', $invitation->fresh()->status);
            $this->assertSame(2, $invitation->fresh()->revision);
        } elseif ($action === 'preview') {
            $this->assertSame(0, $result->invalid_count);
            $this->assertSame([$candidate->phone], $result->phones);
            $this->assertDatabaseCount('organization_invitations', 0);
        } elseif (in_array($action, ['commit', 'commit_committed'], true)) {
            $this->assertSame(['created' => 1, 'skipped' => 0], $result);
            $this->assertDatabaseCount('organization_invitations', 1);
            $this->assertNotNull($import->fresh()->committed_at);
            if ($action === 'commit_committed') {
                $this->assertSame($before, $this->snapshot());
            }
        } elseif (in_array($action, ['leave', 'remove', 'remove_admin', 'revoke_manager'], true)) {
            $target = $action === 'revoke_manager' ? $manager : $member;
            $this->assertFalse($organization->members()->where('users.id', $target->id)->exists());
        } elseif ($action === 'assign_manager') {
            $this->assertSame('manager', $organization->members()->where('users.id', $candidate->id)->firstOrFail()->pivot->kind);
            $this->assertTrue($candidate->fresh()->hasRole('org_admin'));
            $this->assertSame(2, $candidate->fresh()->access_revision);
        } elseif ($action === 'assign_existing') {
            $this->assertSame($before, $this->snapshot());
        } elseif (in_array($action, ['license_new', 'license_edit'], true)) {
            $this->assertSame(3, $result->seat_limit);
            $this->assertSame($action === 'license_edit' ? 2 : 1, $result->revision);
        } elseif (in_array($action, ['enroll', 'enroll_duplicate'], true)) {
            $this->assertSame($action === 'enroll' ? ['enrolled' => 1, 'skipped' => 0] : ['enrolled' => 0, 'skipped' => 1], $result);
            $this->assertDatabaseCount('organization_seats', 1);
            $this->assertDatabaseHas('enrollments', ['user_id' => $member->id, 'payment_plan' => 'organization', 'status' => 'active', 'amount' => 0]);
            if ($action === 'enroll_duplicate') {
                $this->assertSame($before, $this->snapshot());
            }
        } elseif ($action === 'create_organization') {
            $this->assertDatabaseCount('organizations', 2);
            $this->assertTrue($candidate->fresh()->hasRole('org_admin'));
            $this->assertSame(2, $candidate->fresh()->access_revision);
        }
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_owner_can_leave_after_student_role_loss_and_keep_consumed_seat_and_enrollment_record(): void
    {
        $fixture = $this->fixture('enroll');
        extract($fixture);
        app(OrganizationEnrollment::class)->enroll($manager, $organization, $license, 1, [$member->id]);
        $member->roles()->sync([Role::where('key', 'coach')->value('id')]);
        app(OrganizationMembership::class)->endMembership($member, $organization, $member, $this->key($organization, $member), 'Software-only departure', 'leave');
        $this->assertFalse($organization->members()->where('users.id', $member->id)->exists());
        $this->assertSame('active', Enrollment::sole()->status);
        $this->assertDatabaseCount('organization_seats', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_owner_can_leave_suspended_organization(): void
    {
        $fixture = $this->fixture('leave');
        extract($fixture);
        $organization->update(['status' => 'suspended']);
        app(OrganizationMembership::class)->endMembership($member, $organization, $member, $this->key($organization, $member), 'Software-only departure', 'leave');
        $this->assertFalse($organization->members()->where('users.id', $member->id)->exists());
    }

    public function test_full_admin_can_remove_member_from_suspended_organization(): void
    {
        $fixture = $this->fixture('remove_admin');
        extract($fixture);
        $organization->update(['status' => 'suspended']);
        app(OrganizationMembership::class)->endMembership($admin, $organization, $member, $this->key($organization, $member), 'Software-only removal', 'remove');
        $this->assertFalse($organization->members()->where('users.id', $member->id)->exists());
    }

    public function test_current_manager_with_student_role_can_be_selected_in_the_same_group(): void
    {
        $fixture = $this->fixture('enroll');
        extract($fixture);
        $manager->roles()->syncWithoutDetaching([Role::where('key', 'student')->value('id')]);
        $result = app(OrganizationEnrollment::class)->enroll($manager, $organization, $license, 1, [$manager->id, $member->id]);
        $this->assertSame(['enrolled' => 2, 'skipped' => 0], $result);
        $this->assertDatabaseCount('organization_seats', 2);
        $this->assertDatabaseCount('payments', 0);
    }
}
