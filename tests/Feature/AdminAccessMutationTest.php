<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminUserController;
use App\Models\AuditEvent;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminAccessMutationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function request(User $actor, array $data): Request
    {
        $request = Request::create('/admin/synthetic', 'PATCH', $data);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    private function rejectsStaleActor(bool $assignment, bool $deactivate): void
    {
        $admin = $this->user('admin');
        $actor = $admin->fresh();
        $student = $this->user('student');
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => null]);
        if ($deactivate) {
            $admin->forceFill(['is_active' => false])->save();
        } else {
            $admin->roles()->detach();
        }
        $request = $this->request($actor, $assignment ? ['coach_id' => $coach->id] : ['revision' => $student->access_revision, 'roles' => ['student', 'coach'], 'is_active' => true]);
        try {
            $controller = app(AdminUserController::class);
            if ($assignment) {
                $controller->assignCoach($request, $course);
            } else {
                $controller->update($request, $student);
            }
            $this->fail('Stale actor access must not authorize a mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertFalse($student->hasRole('coach'));
        $this->assertNull($course->fresh()->coach_id);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_user_access_mutation_rejects_stale_actor_after_role_loss(): void
    {
        $this->rejectsStaleActor(false, false);
    }

    public function test_user_access_mutation_rejects_stale_active_actor_snapshot(): void
    {
        $this->rejectsStaleActor(false, true);
    }

    public function test_course_assignment_rejects_stale_actor_after_role_loss(): void
    {
        $this->rejectsStaleActor(true, false);
    }

    public function test_course_assignment_rejects_stale_active_actor_snapshot(): void
    {
        $this->rejectsStaleActor(true, true);
    }

    public function test_missing_coach_field_cannot_accidentally_clear_existing_assignment(): void
    {
        $admin = $this->user('admin');
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => $coach->id]);
        $this->actingAs($admin)->patchJson('/admin/courses/'.$course->id.'/coach', [])->assertUnprocessable();
        $this->assertSame($coach->id, $course->fresh()->coach_id);
        $this->patch('/admin/courses/'.$course->id.'/coach', ['coach_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($course->fresh()->coach_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'course.coach_assigned', 'subject_id' => $course->id]);
    }

    public function test_failed_coach_assignment_audit_rolls_back_course_change(): void
    {
        $admin = $this->user('admin');
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => null]);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "audit_events"')) {
                throw new \RuntimeException('Synthetic audit persistence failure');
            }
        });
        try {
            app(AdminUserController::class)->assignCoach($this->request($admin, ['coach_id' => $coach->id]), $course);
            $this->fail('The synthetic database failure must abort the assignment.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit persistence failure', $exception->getMessage());
        }
        $this->assertNull($course->fresh()->coach_id);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_multi_role_admin_can_assign_itself_and_keep_admin_access(): void
    {
        $admin = $this->user('admin');
        $admin->roles()->attach(Role::where('key', 'coach')->value('id'));
        $course = Course::factory()->create(['coach_id' => null]);
        $this->actingAs($admin)->patch('/admin/courses/'.$course->id.'/coach', ['coach_id' => $admin->id])->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $course->fresh()->coach_id);
        $this->assertTrue($admin->hasRole('admin'));
    }
}
