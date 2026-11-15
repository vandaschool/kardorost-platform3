<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invoices', function (Blueprint $table) {
            $table->json('payment_schedule')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('organization_invoices')->whereNotNull('payment_schedule')->exists()) {
            throw new RuntimeException('Contract payment schedules must be preserved before rollback.');
        }
        Schema::table('organization_invoices', fn (Blueprint $table) => $table->dropColumn('payment_schedule'));
    }
};
