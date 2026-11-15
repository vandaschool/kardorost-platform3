<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('title');
            $table->longText('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('current_version')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('resume_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_resume_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->longText('definition');
            $table->string('content_hash', 64);
            $table->timestamps();
            $table->unique(['career_resume_id', 'number']);
        });
        Schema::create('resume_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->longText('job_text')->nullable();
            $table->string('job_hash', 64);
            $table->string('method', 40);
            $table->longText('report');
            $table->timestamps();
            $table->unique(['resume_version_id', 'job_hash', 'method']);
        });
    }

    public function down(): void
    {
        if (DB::table('career_resumes')->exists()) {
            throw new RuntimeException('Private resume drafts and snapshots must be preserved before rollback.');
        }
        Schema::dropIfExists('resume_checks');
        Schema::dropIfExists('resume_versions');
        Schema::dropIfExists('career_resumes');
    }
};
