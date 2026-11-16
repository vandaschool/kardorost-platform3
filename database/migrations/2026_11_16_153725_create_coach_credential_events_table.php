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
        Schema::create('coach_credential_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_credential_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedInteger('revision');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['coach_credential_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('coach_credential_events')->exists()) {
            throw new RuntimeException('Private credential review history must be independently preserved before rollback.');
        }
        Schema::dropIfExists('coach_credential_events');
    }
};
