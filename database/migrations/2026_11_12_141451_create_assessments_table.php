<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 160);
            $t->json('draft')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedInteger('current_version')->default(0);
            $t->boolean('available')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
