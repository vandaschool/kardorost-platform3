<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('assessment_version_id')->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('in_progress');
            $t->unsignedInteger('revision')->default(1);
            $t->text('answers');
            $t->text('result')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unique(['user_id', 'assessment_version_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_attempts');
    }
};
