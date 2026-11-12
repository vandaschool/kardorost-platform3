<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_slots', function (Blueprint $t): void {
            $t->unsignedBigInteger('price')->default(0);
        });
        Schema::table('consultations', function (Blueprint $t): void {
            $t->unsignedBigInteger('amount')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->string('refund_status', 20)->nullable();
        });
        Schema::table('payments', function (Blueprint $t): void {
            $t->foreignId('enrollment_id')->nullable()->change();
            $t->foreignId('consultation_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('consultations', function (Blueprint $t): void {
            $t->foreignId('settled_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('consultation_id')->exists()) {
            throw new RuntimeException('Consultation billing has payment records. Reconcile and back up those records before rolling back.');
        }
        Schema::table('consultations', function (Blueprint $t): void {
            $t->dropUnique(['settled_payment_id']);
            $t->dropConstrainedForeignId('settled_payment_id');
        });
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('consultation_id');
            $t->foreignId('enrollment_id')->nullable(false)->change();
        });
        Schema::table('consultations', function (Blueprint $t): void {
            $t->dropColumn(['amount', 'expires_at', 'refund_status']);
        });
        Schema::table('consultation_slots', function (Blueprint $t): void {
            $t->dropColumn('price');
        });
    }
};
