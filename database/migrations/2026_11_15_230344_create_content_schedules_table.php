<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('source_revision');
            $table->unsignedInteger('page_revision');
            $table->json('definition');
            $table->string('definition_hash', 64);
            $table->timestamp('scheduled_for');
            $table->string('status', 20)->default('pending');
            $table->string('reason', 40)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('content_publication_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['content_page_id', 'page_revision']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        if (DB::table('content_schedules')->exists()) {
            throw new RuntimeException('Scheduled content decisions must be preserved before rollback.');
        }
        Schema::dropIfExists('content_schedules');
    }
};
