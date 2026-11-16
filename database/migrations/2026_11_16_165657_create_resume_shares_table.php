<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_resume_id')->constrained()->restrictOnDelete();
            $table->foreignId('resume_version_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('token_hash', 64)->unique();
            $table->longText('definition');
            $table->boolean('include_contacts');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('consented_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->longText('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['resume_version_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('resume_shares')->exists()) {
            throw new RuntimeException('Consented resume sharing and revocation history must be preserved before rollback.');
        }
        Schema::dropIfExists('resume_shares');
    }
};
