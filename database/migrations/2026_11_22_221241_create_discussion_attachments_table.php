<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discussion_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->text('file_path')->nullable();
            $table->longText('definition')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['owner_id', 'request_key']);
            $table->index(['discussion_post_id', 'withdrawn_at']);
        });
        Schema::table('discussion_post_revisions', function (Blueprint $table): void {
            $table->longText('attachments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('discussion_post_revisions', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });
        Schema::dropIfExists('discussion_attachments');
    }
};
