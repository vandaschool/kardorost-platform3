<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('title');
            $table->text('message');
            $table->json('roles');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('recipient_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('stop_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'dispatched_at']);
        });
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->uuid('notification_id')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['announcement_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('announcements')->exists()) {
            throw new RuntimeException('Announcement content and recipient history must be preserved before rollback.');
        }
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
    }
};
