<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohorts', function (Blueprint $t) {
            $t->boolean('installment_enabled')->default(false);
            $t->boolean('manual_payment_enabled')->default(false);
        });
        Schema::table('enrollments', function (Blueprint $t) {
            $t->string('payment_plan', 20)->default('full');
            $t->unsignedInteger('billing_version')->default(1);
        });
        Schema::create('cohort_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cohort_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->unsignedBigInteger('amount');
            $t->date('due_date');
            $t->timestamps();
            $t->unique(['cohort_id', 'sequence']);
        });
        Schema::create('installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('enrollment_id')->constrained();
            $t->unsignedInteger('billing_version');
            $t->unsignedSmallInteger('sequence');
            $t->unsignedBigInteger('amount');
            $t->date('due_date');
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
            $t->unique(['enrollment_id', 'billing_version', 'sequence']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('installment_id')->nullable()->constrained();
            $t->string('method', 12)->default('gateway');
            $t->timestamp('attempt_expires_at')->nullable();
            $t->timestamp('captured_at')->nullable();
            $t->string('review_reason', 80)->nullable();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('installment_id')->unique()->constrained();
            $t->foreignId('payment_id')->unique()->constrained();
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });
        Schema::create('payment_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained();
            $t->text('file_path');
            $t->string('file_name');
            $t->string('file_hash', 64);
            $t->string('destination', 80);
            $t->string('transfer_reference', 100);
            $t->date('transfer_date');
            $t->string('status', 16)->default('pending');
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('review_version')->default(0);
            $t->timestamps();
        });
        Schema::create('receipt_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_receipt_id')->unique()->constrained();
            $t->string('file_hash', 64)->unique();
            $t->string('transfer_key', 64)->unique();
            $t->timestamps();
        });
        DB::table('enrollments')->where('amount', '>', 0)->orderBy('id')->chunkById(100, function ($enrollments) {
            foreach ($enrollments as $e) {
                $paid = DB::table('payments')->where('enrollment_id', $e->id)->where('status', 'paid')->where('amount', $e->amount)->first();
                $invoice = DB::table('installments')->insertGetId(['enrollment_id' => $e->id, 'billing_version' => 1, 'sequence' => 1, 'amount' => $e->amount, 'due_date' => substr($e->created_at, 0, 10), 'paid_at' => $paid?->paid_at, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('payments')->where('enrollment_id', $e->id)->update(['installment_id' => $invoice]);
                if ($paid) {
                    DB::table('payments')->where('id', $paid->id)->update(['captured_at' => $paid->paid_at]);
                    DB::table('payment_allocations')->insert(['installment_id' => $invoice, 'payment_id' => $paid->id, 'amount' => $paid->amount, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        DB::table('payments')->whereNotNull('paid_at')->update(['captured_at' => DB::raw('paid_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_claims');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_allocations');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('installment_id');
            $t->dropColumn(['method', 'attempt_expires_at', 'captured_at', 'review_reason']);
        });
        Schema::dropIfExists('installments');
        Schema::dropIfExists('cohort_installments');
        Schema::table('enrollments', fn (Blueprint $t) => $t->dropColumn(['payment_plan', 'billing_version']));
        Schema::table('cohorts', fn (Blueprint $t) => $t->dropColumn(['installment_enabled', 'manual_payment_enabled']));
    }
};
