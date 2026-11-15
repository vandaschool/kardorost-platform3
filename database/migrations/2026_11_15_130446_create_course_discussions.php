<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_discussions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cohort_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('title', 160);
            $table->string('kind', 20)->default('question');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('locked')->default(false);
            $table->boolean('hidden')->default(false);
            $table->boolean('pinned')->default(false);
            $table->text('moderation_reason')->nullable();
            $table->timestamp('last_post_at');
            $table->timestamps();
            $table->unique(['author_id', 'request_key'], 'discussion_author_request');
            $table->index(['cohort_id', 'last_post_at']);
        });
        Schema::create('discussion_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_discussion_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('author_role', 20);
            $table->uuid('request_key');
            $table->text('body');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('hidden')->default(false);
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
            $table->unique(['author_id', 'request_key'], 'discussion_post_author_request');
            $table->index(['course_discussion_id', 'id']);
        });
        Schema::create('discussion_post_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discussion_post_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('author_role', 20);
            $table->unsignedInteger('revision');
            $table->text('body');
            $table->boolean('hidden');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['discussion_post_id', 'revision'], 'discussion_post_revision_unique');
        });
        Schema::table('course_discussions', function (Blueprint $table): void {
            $table->foreignId('accepted_post_id')->nullable()->constrained('discussion_posts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('course_discussions')->exists() || DB::table('discussion_post_revisions')->exists()) {
            throw new RuntimeException('Discussion history exists. Back up and reconcile before rollback.');
        }
        Schema::table('course_discussions', fn (Blueprint $table) => $table->dropConstrainedForeignId('accepted_post_id'));
        Schema::dropIfExists('discussion_post_revisions');
        Schema::dropIfExists('discussion_posts');
        Schema::dropIfExists('course_discussions');
    }
};
