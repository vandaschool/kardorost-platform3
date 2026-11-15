<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cohort_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->json('definition');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('certificate_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->json('definition');
            $table->string('recipient_name', 200);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('public_consent_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['enrollment_id', 'status']);
        });
        Schema::create('course_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('certificate_request_id')->unique()->constrained()->restrictOnDelete();
            $table->string('serial', 40)->unique();
            $table->string('verification_token', 64)->unique();
            $table->json('definition');
            $table->json('evidence');
            $table->string('status', 20)->default('issued');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('certificate_requests')->exists() || DB::table('course_certificates')->exists()) {
            throw new RuntimeException('Certificate records exist. Back up and reconcile before rollback.');
        }
        Schema::dropIfExists('course_certificates');
        Schema::dropIfExists('certificate_requests');
        Schema::dropIfExists('certificate_policies');
    }
};
