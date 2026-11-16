<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coach_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('status', 20)->default('submitted');
            $table->unsignedInteger('revision')->default(1);
            $table->text('definition');
            $table->text('file_path')->nullable();
            $table->timestamp('consented_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['status', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('coach_credentials')->exists()) {
            throw new RuntimeException('Private coach credential records must be independently preserved before rollback.');
        }
        Schema::dropIfExists('coach_credentials');
    }
};
