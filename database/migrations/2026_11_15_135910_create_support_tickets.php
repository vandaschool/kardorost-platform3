<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subject', 160);
            $table->string('category', 30);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('waiting_staff');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('request_key');
            $table->timestamp('last_message_at');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['status', 'last_message_at']);
        });
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('author_role', 20);
            $table->longText('body')->nullable();
            $table->boolean('internal_note')->default(false);
            $table->uuid('request_key');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->timestamps();
            $table->unique(['author_id', 'request_key']);
            $table->index(['support_ticket_id', 'internal_note', 'id']);
        });
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->boolean('internal')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('support_tickets')->exists()) {
            throw new RuntimeException('Private support history must be preserved.');
        }
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
