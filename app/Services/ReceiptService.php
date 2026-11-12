<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Notifications\ReceiptReviewed;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    public function __construct(private BillingService $billing) {}

    public function submit(User $user, Installment $installment, UploadedFile $file, string $reference, string $date): PaymentReceipt
    {
        $reference = $this->normalizeReference($reference);
        $path = null;
        try {
            return DB::transaction(function () use ($user, $installment, $file, $reference, $date, &$path) {
                [$cohort,$enrollment,$installment] = $this->billing->locked($installment);
                $this->billing->payable($user, $enrollment, $installment);
                $destination = $this->normalizeReference((string) config('platform.finance.transfer_destination'));
                if (! $cohort->manual_payment_enabled || ! preg_match('/^(?:IR[0-9]{24}|[0-9]{8,30})$/D', $destination)) {
                    throw ValidationException::withMessages(['file' => 'پرداخت کارت‌به‌کارت یا اطلاعات واریز این نوبت هنوز فعال نشده است.']);
                }
                if (Payment::where('installment_id', $installment->id)->where(fn ($q) => $q->where('status', 'pending_review')->orWhere(fn ($q) => $q->where('status', 'pending')->where('attempt_expires_at', '>', now())))->exists()) {
                    throw ValidationException::withMessages(['file' => 'برای این مبلغ رسید یا پرداخت در حال انجام وجود دارد. تا تعیین تکلیف آن دوباره ارسال نکنید.']);
                }
                $hash = hash_file('sha256', $file->getRealPath());
                $key = hash('sha256', $destination.'|'.$date.'|'.$reference);
                if (ReceiptClaim::where('file_hash', $hash)->orWhere('transfer_key', $key)->exists()) {
                    throw ValidationException::withMessages(['file' => 'این رسید یا شناسه واریز قبلاً در حال بررسی یا تأیید شده است.']);
                }
                $path = $file->store('receipts', 'receipts');
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['\\', '/'], '_', $file->getClientOriginalName())), 0, 200);
                $payment = Payment::create(['user_id' => $user->id, 'enrollment_id' => $enrollment->id, 'installment_id' => $installment->id, 'amount' => $installment->amount, 'method' => 'manual', 'status' => 'pending_review']);
                $receipt = PaymentReceipt::create(['payment_id' => $payment->id, 'file_path' => $path, 'file_name' => $name, 'file_hash' => $hash, 'destination' => $destination, 'transfer_reference' => $reference, 'transfer_date' => $date]);
                ReceiptClaim::create(['payment_receipt_id' => $receipt->id, 'file_hash' => $hash, 'transfer_key' => $key]);
                if ($enrollment->status === 'pending_payment') {
                    $enrollment->update(['expires_at' => now()->addHours(max(1, min(168, (int) config('platform.finance.receipt_hold_hours', 24))))]);
                }
                AuditEvent::create(['actor_id' => $user->id, 'action' => 'receipt.submitted', 'subject_type' => 'payment_receipt', 'subject_id' => $receipt->id]);

                return $receipt;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('receipts')->delete($path);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['file' => 'این رسید یا شناسه واریز هم‌زمان ثبت شده است. صفحه را به‌روز کنید.']);
            }throw $exception;
        }
    }

    public function review(User $admin, PaymentReceipt $receipt, array $data): bool
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);

        abort_if($receipt->payment->organization_invoice_id, 422);

        return DB::transaction(function () use ($admin, $receipt, $data) {
            $payment = $receipt->payment;
            [$cohort,$enrollment,$installment] = $this->billing->locked($payment->installment);
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $receipt = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'approved' && $data['action'] === 'approve') {
                return $payment->status === 'paid';
            }
            if ($receipt->status !== 'pending' || $receipt->review_version !== (int) $data['expected_review_version']) {
                throw ValidationException::withMessages(['receipt' => 'این رسید قبلاً بررسی شده است. صفحه را به‌روز کنید.']);
            }
            if ($data['action'] === 'approve' && ! in_array($data['statement_verified'] ?? false, [true, 1, '1', 'yes', 'on', 'true'], true)) {
                throw ValidationException::withMessages(['statement_verified' => 'بررسی گردش بانکی باید صریحاً تأیید شود.']);
            }
            if ($data['action'] === 'approve' && (int) $data['verified_amount'] * 10 !== $payment->amount) {
                throw ValidationException::withMessages(['verified_amount' => 'مبلغ تأییدشده با مبلغ این صورتحساب مطابقت ندارد.']);
            }
            $receipt->update(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'review_note' => $data['review_note'] ?? null, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_version' => $receipt->review_version + 1]);
            if ($data['action'] === 'approve') {
                $allocated = $this->billing->capture($payment, 'MANUAL-'.$receipt->id);
            } else {
                $payment->update(['status' => 'failed']);
                $receipt->claim()->delete();
                $allocated = false;
                if ($enrollment->status === 'pending_payment') {
                    $enrollment->update(['expires_at' => now()]);
                }
            }
            $enrollment->user->notify(new ReceiptReviewed($receipt, $allocated));
            AuditEvent::create(['actor_id' => $admin->id, 'action' => 'receipt.'.$receipt->status, 'subject_type' => 'payment_receipt', 'subject_id' => $receipt->id, 'metadata' => ['statement_verified' => $data['action'] === 'approve', 'verified_amount' => $data['action'] === 'approve' ? $payment->amount : null]]);

            return $allocated;
        });
    }

    public function normalizeReference(string $value): string
    {
        $value = strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));

        return strtoupper(preg_replace('/[\s-]+/u', '', trim($value)));
    }
}
