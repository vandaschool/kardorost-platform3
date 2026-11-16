<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->text('audience')->nullable());
        Schema::table('announcement_recipients', fn (Blueprint $table) => $table->text('scope_snapshot')->nullable());
    }

    public function down(): void
    {
        if (DB::table('announcements')->whereNotNull('audience')->exists()
            || DB::table('announcement_recipients')->whereNotNull('scope_snapshot')->exists()
            || DB::table('notifications')->where('type', 'App\\Notifications\\PlatformAnnouncement')->where('data', 'like', '%"private_payload"%')->exists()) {
            throw new RuntimeException('Private announcement scope and delivery history must be preserved before rollback.');
        }
        Schema::table('announcement_recipients', fn (Blueprint $table) => $table->dropColumn('scope_snapshot'));
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
