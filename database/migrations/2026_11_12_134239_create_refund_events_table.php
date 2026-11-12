<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('refund_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 20);
            $t->text('note');
            $t->unsignedInteger('version');
            $t->unique(['refund_id', 'version']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_events');
    }
};
