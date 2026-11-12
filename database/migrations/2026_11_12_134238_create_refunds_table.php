<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount');
            $t->string('status', 20)->default('requested');
            $t->unsignedInteger('version')->default(1);
            $t->text('destination');
            $t->text('reason');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->timestamp('completed_at')->nullable();
            $t->string('transfer_reference', 100)->nullable();
            $t->date('transfer_date')->nullable();
            $t->string('transfer_key', 64)->nullable()->unique();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('file_hash', 64)->nullable()->unique();
            $t->index(['status', 'created_at']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
