<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_seats', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_license_id')->constrained()->restrictOnDelete();
            $t->foreignId('enrollment_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('member_name');
            $t->string('member_phone', 30);
            $t->unique(['organization_license_id', 'user_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_seats');
    }
};
