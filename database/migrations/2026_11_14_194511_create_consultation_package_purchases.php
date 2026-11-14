<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('sessions');
            $table->unsignedInteger('session_minutes');
            $table->unsignedInteger('valid_days');
            $table->boolean('published')->default(false);
            $table->boolean('manual_payment_enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('package_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('coach_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->json('definition');
            $table->unsignedBigInteger('amount');
            $table->string('status', 30)->default('pending_payment');
            $table->timestamp('expires_at');
            $table->foreignId('consultation_grant_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'consultation_product_id'], 'package_student_product');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('package_purchase_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('package_purchases', function (Blueprint $table): void {
            $table->foreignId('settled_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('package_purchases')->exists() || DB::table('payments')->whereNotNull('package_purchase_id')->exists()) {
            throw new RuntimeException('Package purchases exist. Back up and reconcile before rollback.');
        }
        Schema::table('package_purchases', function (Blueprint $table): void {
            $table->dropUnique(['settled_payment_id']);
            $table->dropConstrainedForeignId('settled_payment_id');
        });
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('package_purchase_id'));
        Schema::dropIfExists('package_purchases');
        Schema::dropIfExists('consultation_products');
    }
};
