<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_surveys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cohort_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->json('draft')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('current_version')->default(0);
            $table->boolean('available')->default(false);
            $table->timestamps();
        });
        Schema::create('survey_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_survey_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->string('content_hash', 64);
            $table->boolean('accepting')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_survey_id', 'number']);
        });
        Schema::create('survey_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->text('answers');
            $table->string('respondent_name', 200)->nullable();
            $table->string('status', 20)->default('in_progress');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_publication_id', 'enrollment_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('survey_responses')->exists()) {
            throw new RuntimeException('Survey responses exist. Back up and reconcile before rollback.');
        }
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_publications');
        Schema::dropIfExists('course_surveys');
    }
};
