<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('provider_mode', 20);
            $table->string('provider_account', 64);
            $table->string('provider_reference', 32);
            $table->string('status', 20)->default('pending');
            $table->integer('last_code')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('poll_started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
            $table->unique(['provider_mode', 'provider_account', 'provider_reference'], 'sms_delivery_provider_reference_unique');
            $table->index(['status', 'next_poll_at']);
        });
        Schema::create('sms_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_delivery_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->uuid('lease_token');
            $table->string('status', 20)->default('processing');
            $table->integer('code')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['sms_delivery_id', 'number']);
        });
        Schema::table('invitation_sms', function (Blueprint $table) {
            $table->string('sender_mode', 20)->nullable();
            $table->string('sender_account', 64)->nullable();
            $table->foreignId('sms_delivery_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sms_reminders_enabled')->default(true);
        });
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('occurs_at');
            $table->uuid('notification_id')->nullable();
            $table->timestamp('in_app_sent_at')->nullable();
            $table->string('sms_status', 20)->default('not_requested');
            $table->string('sms_reference', 32)->nullable();
            $table->string('sms_failure_code', 60)->nullable();
            $table->string('sender_mode', 20)->nullable();
            $table->string('sender_account', 64)->nullable();
            $table->timestamp('sms_dispatched_at')->nullable();
            $table->timestamp('sms_attempted_at')->nullable();
            $table->timestamp('sms_finished_at')->nullable();
            $table->foreignId('sms_delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'source_type', 'source_id', 'occurs_at'], 'event_reminder_recipient_occurrence_unique');
            $table->index(['sms_status', 'sms_dispatched_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('event_reminders')->exists() || DB::table('sms_deliveries')->exists()) {
            throw new RuntimeException('Delivery and reminder history must be preserved before rollback.');
        }
        Schema::dropIfExists('event_reminders');
        Schema::table('invitation_sms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_delivery_id');
            $table->dropColumn(['sender_mode', 'sender_account']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sms_reminders_enabled'));
        Schema::dropIfExists('sms_delivery_attempts');
        Schema::dropIfExists('sms_deliveries');
    }
};
