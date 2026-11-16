<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('source_revision');
            $table->unsignedInteger('announcement_revision');
            $table->text('definition');
            $table->string('definition_hash', 64);
            $table->timestamp('scheduled_for');
            $table->string('status', 20)->default('pending');
            $table->string('reason', 40)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'announcement_revision']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        if (DB::table('announcement_schedules')->exists()) {
            throw new RuntimeException('Scheduled announcement approvals must be preserved before rollback.');
        }
        Schema::dropIfExists('announcement_schedules');
    }
};
