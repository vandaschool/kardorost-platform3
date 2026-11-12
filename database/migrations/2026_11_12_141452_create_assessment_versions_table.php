<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->json('definition');
            $t->string('content_hash', 64);
            $t->foreignId('published_by')->constrained('users');
            $t->unique(['assessment_id', 'number']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_versions');
    }
};
