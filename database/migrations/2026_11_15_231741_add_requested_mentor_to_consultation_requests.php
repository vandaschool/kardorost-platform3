<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->foreignId('requested_coach_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('requested_mentor')->nullable();
            $table->index(['requested_coach_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('consultation_requests')->whereNotNull('requested_coach_id')->orWhereNotNull('requested_mentor')->exists()) {
            throw new RuntimeException('Student mentor selections must be preserved before rollback.');
        }
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->dropIndex(['requested_coach_id', 'status']);
            $table->dropConstrainedForeignId('requested_coach_id');
            $table->dropColumn('requested_mentor');
        });
    }
};
