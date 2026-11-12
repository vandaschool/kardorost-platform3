<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('organization_license_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('number')->nullable()->unique();
            $t->string('title', 160);
            $t->string('organization_name');
            $t->string('contract_reference', 200);
            $t->json('lines');
            $t->unsignedBigInteger('amount');
            $t->date('due_date');
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->string('destination')->nullable();
            $t->string('holder')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
        Schema::table('payments', function (Blueprint $t): void {
            $t->foreignId('organization_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('payer_name_snapshot')->nullable();
        });
        Schema::table('organization_invoices', function (Blueprint $t): void {
            $t->foreignId('settled_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('organization_invoice_id')->exists()) {
            throw new RuntimeException('Organization payments exist. Reconcile and back up the ledger before rollback.');
        }
        Schema::table('organization_invoices', function (Blueprint $t): void {
            $t->dropUnique(['settled_payment_id']);
            $t->dropConstrainedForeignId('settled_payment_id');
        });
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('organization_invoice_id');
            $t->dropColumn('payer_name_snapshot');
        });
        Schema::dropIfExists('organization_invoices');
    }
};
