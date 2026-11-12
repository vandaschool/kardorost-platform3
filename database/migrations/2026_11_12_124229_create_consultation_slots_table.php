<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('published')->default(true);
            $table->boolean('booked')->default(false);
            $table->string('provider', 20);
            $table->text('meeting_url')->nullable();
            $table->index(['coach_id', 'starts_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_slots');
    }
};
