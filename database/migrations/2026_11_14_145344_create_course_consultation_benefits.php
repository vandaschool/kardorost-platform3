<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_consultation_benefits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cohort_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('sessions');
            $table->unsignedInteger('session_minutes');
            $table->unsignedInteger('valid_days');
            $table->string('activation', 30);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('enrollment_consultation_benefits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('billing_version');
            $table->foreignId('cohort_consultation_benefit_id')->constrained()->restrictOnDelete();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->json('definition');
            $table->string('status', 20)->default('waiting');
            $table->string('issue_reason', 40)->nullable();
            $table->foreignId('consultation_grant_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'billing_version']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('enrollment_consultation_benefits')->exists()) {
            throw new RuntimeException('Enrollment benefit contracts exist. Back up and reconcile before rollback.');
        }
        Schema::dropIfExists('enrollment_consultation_benefits');
        Schema::dropIfExists('cohort_consultation_benefits');
    }
};
