<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->uuid('membership_key')->nullable()->unique();
        });
        DB::table('organization_user')->orderBy('organization_id')->orderBy('user_id')->get(['organization_id', 'user_id'])->each(function ($row) {
            DB::table('organization_user')->where('organization_id', $row->organization_id)->where('user_id', $row->user_id)->update(['membership_key' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropUnique(['membership_key']);
            $table->dropColumn('membership_key');
        });
    }
};
