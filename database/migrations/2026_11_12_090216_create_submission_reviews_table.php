<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->unsignedInteger('revision');
            $t->string('status', 24);
            $t->unsignedInteger('score')->nullable();
            $t->text('feedback')->nullable();
            $t->timestamps();
            $t->unique(['submission_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_reviews');
    }
};
