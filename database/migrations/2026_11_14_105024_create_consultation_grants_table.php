<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('source_reference', 200);
            $table->unsignedInteger('total_sessions');
            $table->unsignedInteger('session_minutes');
            $table->date('valid_until');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->string('cancellation_policy', 60)->default('before_start_full_credit_v1');
            $table->timestamps();
            $table->unique(['student_id', 'source_reference']);
        });
        Schema::table('consultations', function (Blueprint $table): void {
            $table->foreignId('consultation_grant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('listed_amount')->nullable();
        });
        DB::table('consultations')->update(['listed_amount' => DB::raw('amount')]);
        Schema::table('consultation_requests', function (Blueprint $table): void {
            $table->foreignId('consultation_grant_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('consultation_grant_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('consultation_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('consultation_grants')->exists()) {
            throw new RuntimeException('Consultation entitlements exist. Back up and reconcile before rollback.');
        }
        Schema::dropIfExists('consultation_grant_uses');
        Schema::table('consultation_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('consultation_grant_id'));
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('consultation_grant_id');
            $table->dropColumn('listed_amount');
        });
        Schema::dropIfExists('consultation_grants');
    }
};
