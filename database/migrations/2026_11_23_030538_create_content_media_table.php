<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_key')->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->text('file_path')->nullable();
            $table->longText('definition')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['owner_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media');
    }
};
