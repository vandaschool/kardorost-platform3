<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('slug', 80);
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('current_version')->default(0);
            $table->boolean('available')->default(false);
            $table->timestamps();
            $table->unique(['kind', 'slug']);
        });
        Schema::create('content_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->restrictOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->string('content_hash', 64);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['content_page_id', 'number']);
        });
    }

    public function down(): void
    {
        if (DB::table('content_publications')->exists()) {
            throw new RuntimeException('Published content history must be backed up before rollback.');
        }
        Schema::dropIfExists('content_publications');
        Schema::dropIfExists('content_pages');
    }
};
