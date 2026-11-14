<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_sms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_invitation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('invitation_revision');
            $table->string('organization_name', 150);
            $table->string('status', 20)->default('queued');
            $table->string('provider_reference', 32)->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_invitation_id', 'invitation_revision']);
            $table->index(['status', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_sms');
    }
};
