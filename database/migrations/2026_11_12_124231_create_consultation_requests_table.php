<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->text('topic');
            $table->text('availability');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('consultation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->index(['student_id', 'status']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_requests');
    }
};
