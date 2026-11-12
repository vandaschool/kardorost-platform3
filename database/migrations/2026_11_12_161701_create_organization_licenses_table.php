<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_licenses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('cohort_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('seat_limit');
            $t->date('expires_on');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->string('contract_reference', 200);
            $t->foreignId('created_by')->constrained('users');
            $t->unique(['organization_id', 'cohort_id']);
            $t->timestamps();
        });
        Schema::table('enrollments', function (Blueprint $t): void {
            $t->foreignId('organization_license_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('organization_license_id');
        });
        Schema::dropIfExists('organization_licenses');
    }
};
