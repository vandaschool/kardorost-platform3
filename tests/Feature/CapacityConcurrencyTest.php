<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CapacityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_requests_cannot_reserve_the_last_seat(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real row-lock concurrency is verified on PostgreSQL.');
        }
        $a = User::factory()->create();
        $b = User::factory()->create();
        $course = Course::create(['title' => 'همزمان', 'slug' => 'concurrency', 'description' => 'ظرفیت', 'price' => 1000000, 'is_published' => true]);
        $cohort = Cohort::create(['course_id' => $course->id, 'title' => 'آخرین صندلی', 'format' => 'live', 'capacity' => 1]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\EnrollmentService::class)->reserve(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Cohort::findOrFail((int)$argv[2]));echo 'RESERVED';}
catch(\Illuminate\Validation\ValidationException){echo 'FULL';}
CODE;
        $processes = [];
        foreach ([$a, $b] as $user) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $user->id, (string) $cohort->id], base_path(), null, null, 20);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(['FULL', 'RESERVED'], $results);
        $this->assertSame(1, Enrollment::count());
    }

    public function test_two_simultaneous_submissions_cannot_replace_the_same_attempt(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Submission row-lock concurrency is verified on PostgreSQL.');
        }
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $user->id]);
        $assignment = Assignment::factory()->create(['cohort_id' => $enrollment->cohort_id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user=\App\Models\User::findOrFail((int)$argv[1]);
$request=\Illuminate\Http\Request::create('/test-submit','POST',['body'=>$argv[4],'expected_version'=>0]);
$request->setUserResolver(fn()=>$user);
try{app(\App\Http\Controllers\SubmissionController::class)->store($request,\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Assignment::findOrFail((int)$argv[3]),app(\App\Services\LearningAccess::class));echo 'SAVED';}
catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $processes = [];
        foreach (['First concurrent answer', 'Second concurrent answer'] as $answer) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $user->id, (string) $enrollment->id, (string) $assignment->id, $answer], base_path(), null, null, 20);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(['SAVED', 'STALE'], $results);
        $this->assertSame(1, Submission::count());
    }
}
