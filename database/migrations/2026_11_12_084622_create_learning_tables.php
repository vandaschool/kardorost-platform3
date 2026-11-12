<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('media_kind', 12)->default('none');
            $t->text('media_url')->nullable();
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->unsignedInteger('position')->default(0);
            $t->boolean('is_published')->default(false);
            $t->timestamp('available_at')->nullable();
            $t->timestamps();
        });
        Schema::create('lesson_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position_seconds')->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['enrollment_id', 'lesson_id']);
        });
        Schema::create('live_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cohort_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('provider', 12);
            $t->text('meeting_url');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->boolean('is_published')->default(false);
            $t->string('recording_kind', 12)->default('none');
            $t->text('recording_url')->nullable();
            $t->timestamps();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $t->string('status', 12);
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestamps();
            $t->unique(['live_session_id', 'enrollment_id']);
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cohort_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('instructions');
            $t->unsignedInteger('max_score')->default(100);
            $t->boolean('is_published')->default(false);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->timestamps();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->text('body')->nullable();
            $t->text('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('status', 24)->default('submitted');
            $t->unsignedInteger('score')->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('review_version')->default(0);
            $t->timestamps();
            $t->unique(['assignment_id', 'enrollment_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach (['submissions', 'assignments', 'attendances', 'live_sessions', 'lesson_progress', 'lessons', 'course_sections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
