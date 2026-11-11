<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    public function test_admin_can_grant_multiple_roles_and_cannot_lock_itself_out(): void
    {
        $admin = $this->user('admin');
        $user = $this->user('student');
        $this->actingAs($admin)->patch('/admin/users/'.$user->id, ['roles' => ['student', 'coach'], 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($user->hasRole('coach'));
        $this->patch('/admin/users/'.$admin->id, ['roles' => ['student'], 'is_active' => false])->assertSessionHasErrors('roles');
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_student_cannot_grant_itself_admin_role(): void
    {
        $student = $this->user('student');
        $this->actingAs($student)->patch('/admin/users/'.$student->id, ['roles' => ['admin'], 'is_active' => true])->assertForbidden();
        $this->assertFalse($student->hasRole('admin'));
    }

    public function test_inactive_existing_session_cannot_access_profile(): void
    {
        $user = $this->user('student');
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user)->get('/account')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_cannot_assign_student_as_coach(): void
    {
        $student = $this->user('student');
        $coach = $this->user('coach');
        $course = Course::create(['title' => 'الف', 'slug' => 'one', 'description' => 'توضیح']);
        $this->actingAs($this->user('admin'))->patch('/admin/courses/'.$course->id.'/coach', ['coach_id' => $student->id])->assertSessionHasErrors('coach_id');
        $this->assertNull($course->fresh()->coach_id);
        $this->patch('/admin/courses/'.$course->id.'/coach', ['coach_id' => $coach->id])->assertSessionHasNoErrors();
        $this->assertSame($coach->id, $course->fresh()->coach_id);
    }
}
