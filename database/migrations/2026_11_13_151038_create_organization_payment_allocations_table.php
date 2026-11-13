<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payments')->whereNotNull('organization_invoice_id')->where('status', 'paid')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('organization_invoices')->whereColumn('organization_invoices.settled_payment_id', 'payments.id')->whereColumn('organization_invoices.id', 'payments.organization_invoice_id');
        })->exists()) {
            throw new RuntimeException('An allocated organization payment has no invoice settlement. Reconcile before migrating.');
        }
        $settlements = DB::table('organization_invoices as invoices')
            ->leftJoin('payments', 'payments.id', '=', 'invoices.settled_payment_id')
            ->where(fn ($query) => $query->where('invoices.status', 'paid')->orWhereNotNull('invoices.settled_payment_id'))
            ->get(['invoices.id', 'invoices.status', 'invoices.amount', 'payments.id as payment_id', 'payments.organization_invoice_id', 'payments.amount as payment_amount', 'payments.status as payment_status', 'payments.captured_at', 'payments.enrollment_id', 'payments.consultation_id', 'payments.installment_id']);
        foreach ($settlements as $settlement) {
            if ($settlement->status !== 'paid' || $settlement->payment_status !== 'paid' || ! $settlement->captured_at || $settlement->enrollment_id || $settlement->consultation_id || $settlement->installment_id || (int) $settlement->organization_invoice_id !== (int) $settlement->id || (int) $settlement->payment_amount !== (int) $settlement->amount) {
                throw new RuntimeException('Organization settlement is inconsistent. Reconcile before migrating the allocation ledger.');
            }
        }
        Schema::create('organization_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamp('created_at');
        });
        foreach ($settlements as $settlement) {
            DB::table('organization_payment_allocations')->insert(['organization_invoice_id' => $settlement->id, 'payment_id' => $settlement->payment_id, 'amount' => $settlement->amount, 'created_at' => $settlement->captured_at]);
        }
    }

    public function down(): void
    {
        if (DB::table('organization_payment_allocations')->exists()) {
            throw new RuntimeException('Organization allocations exist. Back up and reconcile the payment ledger before rollback.');
        }
        Schema::dropIfExists('organization_payment_allocations');
    }
};
