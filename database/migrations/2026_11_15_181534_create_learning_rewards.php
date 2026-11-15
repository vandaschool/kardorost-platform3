<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_reward_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('learning_reward_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_reward_policy_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('definition');
            $table->timestamps();
            $table->unique(['learning_reward_policy_id', 'revision'], 'learning_reward_version_unique');
        });
        Schema::table('learning_reward_policies', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->constrained('learning_reward_versions')->restrictOnDelete();
        });
        Schema::create('learning_reward_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('cohort_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $table->foreignId('learning_reward_version_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('points');
            $table->text('lesson_title');
            $table->text('cohort_title');
            $table->timestamp('earned_at');
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'earned_at']);
        });
        Schema::create('learning_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cohort_id')->constrained()->restrictOnDelete();
            $table->foreignId('learning_reward_version_id')->constrained()->restrictOnDelete();
            $table->text('title');
            $table->unsignedBigInteger('goal_points');
            $table->unsignedBigInteger('points_at_award');
            $table->timestamp('earned_at');
            $table->timestamps();
            $table->unique(['user_id', 'cohort_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('learning_reward_policies')->exists() || DB::table('learning_reward_events')->exists()) {
            throw new RuntimeException('Learning reward definitions and earned history must be preserved before rollback.');
        }
        Schema::dropIfExists('learning_badges');
        Schema::dropIfExists('learning_reward_events');
        Schema::table('learning_reward_policies', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_version_id'));
        Schema::dropIfExists('learning_reward_versions');
        Schema::dropIfExists('learning_reward_policies');
    }
};
