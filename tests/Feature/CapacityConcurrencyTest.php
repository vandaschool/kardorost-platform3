<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
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
}
