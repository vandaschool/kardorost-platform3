<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->text('beneficiary_name')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('refunds')->whereNotNull('beneficiary_name')->exists()) {
            throw new RuntimeException('Organization refund beneficiaries exist. Back up the ledger before rollback.');
        }
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('beneficiary_name');
        });
    }
};
