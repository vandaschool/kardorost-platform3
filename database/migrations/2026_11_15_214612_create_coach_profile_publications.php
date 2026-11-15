<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('slug')->unique();
            $table->text('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->string('state', 20)->default('draft');
            $table->boolean('public_enabled')->default(false);
            $table->timestamp('public_published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('coach_profile_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('profile_revision');
            $table->text('definition');
            $table->string('definition_hash', 64);
            $table->timestamp('consented_at');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['coach_profile_id', 'profile_revision'], 'coach_submission_revision_unique');
        });
        Schema::create('coach_profile_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('coach_profile_submission_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->text('definition');
            $table->string('definition_hash', 64);
            $table->timestamp('consented_at');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['coach_profile_id', 'number']);
        });
        Schema::create('coach_profile_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('profile_revision');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['coach_profile_id', 'profile_revision'], 'coach_withdrawal_revision_unique');
        });
        Schema::table('coach_profiles', function (Blueprint $table) {
            $table->foreignId('current_publication_id')->nullable()->constrained('coach_profile_publications')->restrictOnDelete();
            $table->foreignId('latest_submission_id')->nullable()->constrained('coach_profile_submissions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('coach_profiles')->exists()) {
            throw new RuntimeException('Coach drafts, submissions, consent and publication history must be preserved before rollback.');
        }
        Schema::table('coach_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_publication_id');
            $table->dropConstrainedForeignId('latest_submission_id');
        });
        Schema::dropIfExists('coach_profile_withdrawals');
        Schema::dropIfExists('coach_profile_publications');
        Schema::dropIfExists('coach_profile_submissions');
        Schema::dropIfExists('coach_profiles');
    }
};
