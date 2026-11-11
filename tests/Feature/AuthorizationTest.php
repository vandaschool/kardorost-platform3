<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    public function test_student_cannot_enter_privileged_panels_or_switch_to_them(): void
    {
        $this->actingAs($this->user('student'));
        foreach (['/admin', '/admin/users', '/coach', '/organization'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/panel/role', ['role' => 'admin'])->assertForbidden();
        $this->post('/admin/courses', ['title' => 'هک'])->assertForbidden();
    }

    public function test_guest_is_redirected_and_student_home_renders(): void
    {
        $this->get('/student')->assertRedirect('/login');
        $this->actingAs($this->user('student'))->get('/student')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard')->where('role', 'student')->has('enrollments', 0));
    }

    public function test_organization_manager_cannot_read_other_organization_member(): void
    {
        $manager = $this->user('org_admin');
        $member = $this->user('student');
        $outsider = $this->user('student');
        $org = Organization::create(['name' => 'دانشگاه یک', 'type' => 'university']);
        $other = Organization::create(['name' => 'شرکت دو', 'type' => 'company']);
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $org->members()->attach($member->id);
        $other->members()->attach($outsider->id);
        $this->actingAs($manager)->get('/organization/members/'.$outsider->id)->assertForbidden();
        $this->get('/organization/members/'.$member->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Member')->where('member.id', $member->id));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $manager->id, 'action' => 'organization.report_viewed', 'subject_id' => $member->id]);
        $this->post('/organization/switch', ['organization_id' => $other->id])->assertForbidden();
    }

    public function test_removing_membership_revokes_report_access_immediately(): void
    {
        $manager = $this->user('org_admin');
        $member = $this->user('student');
        $org = Organization::create(['name' => 'الف', 'type' => 'company']);
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $org->members()->attach($member->id);
        $this->actingAs($manager)->get('/organization/members/'.$member->id)->assertOk();
        $org->members()->detach($member->id);
        $this->get('/organization/members/'.$member->id)->assertForbidden();
    }

    public function test_suspended_organization_has_no_member_access(): void
    {
        $manager = $this->user('org_admin');
        $org = Organization::create(['name' => 'الف', 'type' => 'company', 'status' => 'suspended']);
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $this->actingAs($manager)->get('/organization/members/'.$manager->id)->assertForbidden();
    }

    public function test_admin_can_create_course_and_price_is_converted_from_toman_to_rial(): void
    {
        $this->actingAs($this->user('admin'))->post('/admin/courses', ['title' => 'خودشناسی', 'description' => 'مسیر آموزش', 'price' => 2500000, 'capacity' => 20, 'format' => 'hybrid', 'stage' => 1, 'is_published' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('courses', ['title' => 'خودشناسی', 'price' => 25000000]);
        $this->assertDatabaseHas('cohorts', ['capacity' => 20, 'format' => 'hybrid']);
    }

    public function test_coach_dashboard_only_receives_assigned_courses(): void
    {
        $coach = $this->user('coach');
        $other = $this->user('coach');
        foreach ([[$coach, 'دوره من'], [$other, 'دوره دیگر']] as [$u,$title]) {
            Course::create(['title' => $title, 'slug' => 'course-'.$u->id, 'description' => 'توضیح', 'coach_id' => $u->id]);
        }
        $this->actingAs($coach)->get('/coach')->assertInertia(fn (Assert $p) => $p->has('courses', 1)->where('courses.0.title', 'دوره من'));
    }
}
