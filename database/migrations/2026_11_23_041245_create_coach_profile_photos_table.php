<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_profile_photos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_key')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->text('file_path')->nullable();
            $table->longText('definition')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('consented_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->longText('withdrawal')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_profile_photos');
    }
};
