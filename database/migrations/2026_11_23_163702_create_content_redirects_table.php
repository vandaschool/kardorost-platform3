<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_page_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('from_slug', 80);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'from_slug']);
            $table->index(['content_page_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('content_redirects')->exists()) {
            throw new RuntimeException('Content redirect decisions must be preserved before rollback.');
        }
        Schema::dropIfExists('content_redirects');
    }
};
