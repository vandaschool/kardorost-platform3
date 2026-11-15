<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            $table->boolean('public_enabled')->default(false);
            $table->timestamp('public_consent_at')->nullable();
            $table->timestamp('public_withdrawn_at')->nullable();
        });
        DB::table('course_certificates')->whereExists(fn ($query) => $query->selectRaw('1')->from('certificate_requests')->whereColumn('certificate_requests.id', 'course_certificates.certificate_request_id')->whereNotNull('public_consent_at'))->update(['public_enabled' => true, 'public_consent_at' => DB::raw('(SELECT public_consent_at FROM certificate_requests WHERE certificate_requests.id = course_certificates.certificate_request_id)')]);
        Schema::create('certificate_visibility_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_certificate_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('enabled');
            $table->unsignedInteger('revision');
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->unique(['course_certificate_id', 'revision']);
        });
    }

    public function down(): void
    {
        if (DB::table('certificate_visibility_events')->exists() || DB::table('course_certificates')->where('public_enabled', false)->orWhereNotNull('public_withdrawn_at')->exists()) {
            throw new RuntimeException('Certificate public consent and privacy choices must be preserved before rollback.');
        }
        Schema::dropIfExists('certificate_visibility_events');
        Schema::table('course_certificates', fn (Blueprint $table) => $table->dropColumn(['public_enabled', 'public_consent_at', 'public_withdrawn_at']));
    }
};
