<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->text('name');
            $table->text('phone');
            $table->string('phone_fingerprint', 64)->index();
            $table->string('audience', 20);
            $table->text('organization_name')->nullable();
            $table->string('subject', 20);
            $table->text('message');
            $table->timestamp('consented_at');
            $table->string('consent_version', 40);
            $table->string('status', 20)->default('new');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('contact_lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note');
            $table->timestamps();
            $table->unique(['contact_lead_id', 'revision']);
        });
    }

    public function down(): void
    {
        if (DB::table('contact_leads')->exists()) {
            throw new RuntimeException('Contact consent and follow-up history must be preserved before rollback.');
        }
        Schema::dropIfExists('contact_lead_events');
        Schema::dropIfExists('contact_leads');
    }
};
