<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
        });
        foreach (['student' => 'دانشجو', 'coach' => 'مربی', 'org_admin' => 'مدیر سازمان', 'admin' => 'مدیر پلتفرم'] as $key => $name) {
            DB::table('roles')->insert(compact('key', 'name'));
        }
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type', 20);
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $t) {
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20)->default('member');
            $t->timestamps();
            $t->primary(['organization_id', 'user_id']);
        });
        Schema::create('otp_challenges', function (Blueprint $t) {
            $t->id();
            $t->string('phone', 12)->index();
            $t->string('purpose', 20);
            $t->string('code_hash');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('slug')->unique();
            $t->text('description');
            $t->unsignedTinyInteger('stage')->default(1);
            $t->unsignedBigInteger('price')->default(0);
            $t->boolean('is_published')->default(false);
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('cohorts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('format', 20);
            $t->unsignedInteger('capacity');
            $t->timestamp('starts_at')->nullable();
            $t->boolean('registration_open')->default(true);
            $t->timestamps();
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('course_id')->constrained();
            $t->foreignId('cohort_id')->constrained();
            $t->string('status', 30)->default('pending_payment');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'cohort_id']);
            $t->index(['cohort_id', 'status', 'expires_at']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('enrollment_id')->constrained();
            $t->unsignedBigInteger('amount');
            $t->string('status', 20)->default('pending');
            $t->string('track_id')->nullable()->unique();
            $t->string('reference')->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'enrollments', 'cohorts', 'courses', 'audit_events', 'otp_challenges', 'organization_user', 'organizations', 'role_user', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
