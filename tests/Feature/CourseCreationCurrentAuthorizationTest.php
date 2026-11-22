<?php

namespace Tests\Feature;

use App\Http\Controllers\CourseController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CourseCreationCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public static function accessChanges(): array
    {
        return [['activity'], ['role'], ['never_admin']];
    }

    #[DataProvider('accessChanges')]
    public function test_course_creation_requires_current_active_admin_even_with_previously_loaded_actor(string $change): void
    {
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('key', $change === 'never_admin' ? 'student' : 'admin')->value('id'));
        if ($change === 'activity') {
            User::whereKey($actor->id)->update(['is_active' => false]);
            $this->assertTrue($actor->is_active);
        } elseif ($change === 'role') {
            $actor->load('roles');
            $actor->roles()->sync([Role::where('key', 'student')->value('id')]);
        }
        $request = Request::create('/admin/courses', 'POST', ['title' => 'دوره صرفاً آزمایشی', 'description' => 'بررسی نرم‌افزاری مجوز جاری ساخت دوره', 'stage' => 1, 'price' => 100000, 'capacity' => 5, 'format' => 'hybrid', 'is_published' => true]);
        $request->setUserResolver(fn () => $actor);
        $before = DB::table('users')->where('id', $actor->id)->first();
        try {
            app(CourseController::class)->store($request);
            $this->fail('A former or disabled admin must not create a course.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('cohorts', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertEquals($before, DB::table('users')->where('id', $actor->id)->first());
    }
}
