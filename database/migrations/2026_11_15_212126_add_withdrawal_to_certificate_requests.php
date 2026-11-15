<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_requests', fn (Blueprint $table) => $table->timestamp('withdrawn_at')->nullable());
    }

    public function down(): void
    {
        if (DB::table('certificate_requests')->whereNotNull('withdrawn_at')->exists()) {
            throw new RuntimeException('Certificate withdrawal history must be preserved before rollback.');
        }
        Schema::table('certificate_requests', fn (Blueprint $table) => $table->dropColumn('withdrawn_at'));
    }
};
