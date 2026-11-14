<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ConsultationGrant;
use App\Models\ConsultationProduct;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Notifications\ConsultationChanged;
use App\Notifications\ReceiptReviewed;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PackageBilling
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }

    private function audit(string $action, int $id, ?int $actor = null): void
    {
        AuditEvent::create(['action' => $action, 'subject_type' => 'package_purchase', 'subject_id' => $id, 'actor_id' => $actor]);
    }

    public function configure(User $admin, array $data, ?ConsultationProduct $product = null): ConsultationProduct
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);

        return DB::transaction(function () use ($admin, $data, $product) {
            if ($product) {
                $product = ConsultationProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($product->revision !== (int) $data['revision']) {
                    $this->fail('تنظیمات تغییر کرده است؛ صفحه را تازه کنید.');
                }
            }
            $coach = User::findOrFail($data['coach_id']);
            if ($data['published'] && (! $coach->is_active || ! $coach->hasRole('coach'))) {
                $this->fail('مربی فعال لازم است.');
            }
            if ($data['manual_payment_enabled'] && ! config('platform.finance.transfer_destination')) {
                $this->fail('ابتدا اطلاعات حساب دریافت وجه را روی سرور تنظیم کنید.');
            }
            $fields = [...array_intersect_key($data, array_flip(['coach_id', 'title', 'description', 'sessions', 'session_minutes', 'valid_days', 'published', 'manual_payment_enabled'])), 'price' => (int) $data['price'] * 10, 'reviewed_by' => $admin->id, 'revision' => ($product?->revision ?? 0) + 1];
            if ($product) {
                $product->update($fields);
            } else {
                $product = ConsultationProduct::create($fields);
            }
            AuditEvent::create(['actor_id' => $admin->id, 'action' => 'consultation_product.configured', 'subject_type' => 'consultation_product', 'subject_id' => $product->id, 'metadata' => ['revision' => $product->revision]]);

            return $product;
        }, 3);
    }

    public function definition(ConsultationProduct $product): array
    {
        return [...$product->only('title', 'description', 'price', 'sessions', 'session_minutes', 'valid_days', 'revision', 'manual_payment_enabled'), 'coach_name' => $product->coach->name, 'cancellation_policy' => 'before_start_full_credit_v1'];
    }

    public function reserve(User $student, ConsultationProduct $product, int $revision): PackagePurchase
    {
        abort_unless($student->is_active && $student->phone_verified_at && $student->hasRole('student'), 403);

        return DB::transaction(function () use ($student, $product, $revision) {
            $product = ConsultationProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $product->published || $product->revision !== $revision) {
                $this->fail('شرایط پکیج تغییر کرده یا فروش آن متوقف است؛ صفحه را تازه کنید.');
            }
            $existing = PackagePurchase::where('student_id', $student->id)->where('consultation_product_id', $product->id)->whereIn('status', ['pending_payment', 'review_required'])->latest('id')->lockForUpdate()->first();
            if ($existing && ($existing->status === 'review_required' || $existing->payments()->where(fn ($query) => $query->whereNotNull('captured_at')->orWhere('status', 'pending_review'))->exists())) {
                $this->fail('سفارش قبلی نیازمند بررسی است؛ از سوابق خرید پیگیری کنید.');
            }
            if ($existing && $existing->expires_at->isFuture()) {
                return $existing;
            }
            $coach = $product->coach;
            User::whereIn('id', [$student->id, $coach->id])->orderBy('id')->lockForUpdate()->get();
            $coach->refresh();
            $student->refresh();
            if (! $coach->is_active || ! $coach->hasRole('coach') || $coach->id === $student->id || ! $student->is_active || ! $student->hasRole('student') || ! $student->phone_verified_at) {
                $this->fail('حساب دانشجو و مربی باید فعال، معتبر و متفاوت باشند.');
            }
            $purchase = PackagePurchase::create(['consultation_product_id' => $product->id, 'student_id' => $student->id, 'coach_id' => $coach->id, 'reviewed_by' => $product->reviewed_by, 'definition' => $this->definition($product), 'amount' => $product->price, 'expires_at' => now()->addMinutes(15)]);
            $this->audit('package_purchase.created', $purchase->id, $student->id);

            return $purchase;
        }, 3);
    }

    /** Shared order: purchase, sorted participant rows, payment, receipt/refund, grant. */
    public function locked(PackagePurchase $purchase): PackagePurchase
    {
        $purchase = PackagePurchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
        User::whereIn('id', [$purchase->student_id, $purchase->coach_id])->orderBy('id')->lockForUpdate()->get();

        return $purchase;
    }

    public function payable(User $student, PackagePurchase $purchase): void
    {
        abort_unless($student->id === $purchase->student_id && $student->is_active && $student->phone_verified_at && $student->hasRole('student'), 403);
        $coach = User::findOrFail($purchase->coach_id);
        if ($purchase->status !== 'pending_payment' || $purchase->expires_at->lte(now()) || ! $coach->is_active || ! $coach->hasRole('coach')) {
            $this->fail('سفارش معتبر و در مهلت پرداخت لازم است.');
        }
        if ($purchase->payments()->whereIn('status', ['pending_review', 'review_required'])->exists()) {
            $this->fail('پرداخت یا رسید قبلی نیازمند بررسی است؛ دوباره پرداخت نکنید.');
        }
    }

    public function attempt(User $student, PackagePurchase $purchase): Payment
    {
        return DB::transaction(function () use ($student, $purchase) {
            $purchase = $this->locked($purchase);
            $this->payable($student->fresh(), $purchase);
            $pending = $purchase->payments()->where('status', 'pending')->where('attempt_expires_at', '>', now())->first();
            if ($pending) {
                return $pending;
            }

            return Payment::create(['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $student->id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'pending', 'attempt_expires_at' => $purchase->expires_at]);
        }, 3);
    }

    private function purpose(Payment $payment): void
    {
        abort_unless($payment->package_purchase_id && ! $payment->enrollment_id && ! $payment->installment_id && ! $payment->consultation_id && ! $payment->organization_invoice_id, 422);
    }

    private function reason(PackagePurchase $purchase, Payment $payment, bool $review = false): ?string
    {
        $student = User::findOrFail($purchase->student_id);
        $coach = User::findOrFail($purchase->coach_id);

        return match (true) {
            $payment->user_id !== $purchase->student_id || $payment->amount !== $purchase->amount || $purchase->amount <= 0 => 'package_amount_mismatch',
            (bool) $purchase->settled_payment_id || (bool) $purchase->consultation_grant_id => 'package_duplicate',
            ! in_array($purchase->status, $review ? ['pending_payment', 'review_required'] : ['pending_payment'], true) || (! $review && $purchase->expires_at->lte(now())) => 'package_expired',
            ! $student->is_active || ! $student->phone_verified_at || ! $student->hasRole('student') || ! $coach->is_active || ! $coach->hasRole('coach') || $student->id === $coach->id => 'package_account_unavailable',
            ConsultationGrant::where('student_id', $student->id)->where('source_reference', 'PACKAGE-PURCHASE-'.$purchase->id)->exists() => 'package_source_conflict',
            default => null,
        };
    }

    private function settle(PackagePurchase $purchase, Payment $payment): void
    {
        $definition = $purchase->definition;
        $grant = ConsultationGrant::create(['student_id' => $purchase->student_id, 'coach_id' => $purchase->coach_id, 'created_by' => $purchase->reviewed_by, 'title' => $definition['title'], 'source_reference' => 'PACKAGE-PURCHASE-'.$purchase->id, 'total_sessions' => $definition['sessions'], 'session_minutes' => $definition['session_minutes'], 'valid_until' => now()->addDays($definition['valid_days'])->toDateString()]);
        $purchase->update(['status' => 'paid', 'settled_payment_id' => $payment->id, 'consultation_grant_id' => $grant->id]);
        $payment->update(['status' => 'paid', 'review_reason' => null]);
        $this->audit('package_purchase.paid', $purchase->id, $purchase->student_id);
        $purchase->student->notify(new ConsultationChanged('خرید پکیج تأیید و سهمیه مشاوره فعال شد'));
    }

    public function capture(Payment $payment, string $reference): bool
    {
        $this->purpose($payment);
        try {
            return DB::transaction(function () use ($payment, $reference) {
                $purchase = $this->locked($payment->packagePurchase);
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($payment->status === 'paid') {
                    return true;
                }
                if (in_array($payment->status, ['review_required', 'refunded'], true)) {
                    return false;
                }
                if ($payment->method === 'manual' && $payment->receipt?->status !== 'approved') {
                    $this->fail('رسید باید مستقل از فایل، توسط مدیریت تأیید شود.');
                }
                if (Payment::where('reference', $reference)->whereKeyNot($payment->id)->exists()) {
                    $payment->update(['status' => 'review_required', 'review_reason' => 'reference_conflict']);
                    $this->audit('package_payment.reference_conflict', $purchase->id);

                    return false;
                }
                $payment->fill(['reference' => $reference, 'captured_at' => now(), 'paid_at' => now()])->save();
                $reason = $this->reason($purchase, $payment);
                if ($reason) {
                    $payment->update(['status' => 'review_required', 'review_reason' => $reason]);
                    if ($purchase->status !== 'paid') {
                        $purchase->update(['status' => 'review_required']);
                    }
                    $this->audit('package_payment.review_required', $purchase->id);
                    $purchase->student->notify(new ConsultationChanged('وجه خرید پکیج نیازمند رسیدگی مدیریت است؛ دوباره پرداخت نکنید'));
                } else {
                    $this->settle($purchase, $payment);
                }

                return $reason === null;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Payment::where('reference', $reference)->whereKeyNot($payment->id)->exists()) {
                throw $exception;
            }

            return $this->capture($payment, $reference);
        }
    }

    public function resolve(User $admin, Payment $payment): bool
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        $this->purpose($payment);

        return DB::transaction(function () use ($admin, $payment) {
            $purchase = $this->locked($payment->packagePurchase);
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->refund()->where('status', '!=', 'rejected')->exists()) {
                $this->fail('این وجه در فرایند بازپرداخت است.');
            }
            if ($payment->status === 'paid') {
                return true;
            }
            if ($payment->status !== 'review_required' || ! $payment->captured_at || ! $payment->reference) {
                $this->fail('دریافت وجه تأیید نشده است.');
            }
            if ($this->reason($purchase, $payment, true)) {
                $this->fail('سفارش هنوز قابل انجام نیست؛ حساب‌ها، مبلغ و سابقه صدور را بررسی کنید.');
            }
            $this->settle($purchase, $payment);
            $this->audit('package_payment.allocated_after_review', $purchase->id, $admin->id);

            return true;
        }, 3);
    }

    public function submitReceipt(User $student, PackagePurchase $purchase, UploadedFile $file, string $reference, string $date): PaymentReceipt
    {
        $path = null;
        try {
            return DB::transaction(function () use ($student, $purchase, $file, $reference, $date, &$path) {
                $purchase = $this->locked($purchase);
                $this->payable($student->fresh(), $purchase);
                $destination = app(ReceiptService::class)->normalizeReference((string) config('platform.finance.transfer_destination'));
                if (! $purchase->definition['manual_payment_enabled'] || ! preg_match('/^(?:IR[0-9]{24}|[0-9]{8,30})$/D', $destination)) {
                    $this->fail('پرداخت دستی این سفارش فعال نیست.');
                }
                if ($purchase->payments()->where('status', 'pending')->where('attempt_expires_at', '>', now())->exists()) {
                    $this->fail('درخواست درگاه هنوز باز است؛ دوباره واریز نکنید.');
                }
                $reference = app(ReceiptService::class)->normalizeReference($reference);
                $hash = hash_file('sha256', $file->getRealPath());
                $key = hash('sha256', $destination.'|'.$date.'|'.$reference);
                if (ReceiptClaim::where('file_hash', $hash)->orWhere('transfer_key', $key)->exists()) {
                    $this->fail('این رسید یا شناسه واریز قبلاً ثبت شده است.');
                }
                $path = $file->store('receipts', 'receipts');
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['\\', '/'], '_', $file->getClientOriginalName())), 0, 200);
                $payment = Payment::create(['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $student->id, 'amount' => $purchase->amount, 'method' => 'manual', 'status' => 'pending_review']);
                $receipt = PaymentReceipt::create(['payment_id' => $payment->id, 'file_path' => $path, 'file_name' => $name, 'file_hash' => $hash, 'destination' => $destination, 'transfer_reference' => $reference, 'transfer_date' => $date]);
                ReceiptClaim::create(['payment_receipt_id' => $receipt->id, 'file_hash' => $hash, 'transfer_key' => $key]);
                $purchase->update(['expires_at' => now()->addHours(max(1, min(168, (int) config('platform.finance.receipt_hold_hours', 24))))]);
                $this->audit('package_receipt.submitted', $purchase->id, $student->id);

                return $receipt;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('receipts')->delete($path);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                $this->fail('این رسید هم‌زمان ثبت شده است؛ صفحه را تازه کنید.');
            }
            throw $exception;
        }
    }

    public function review(User $admin, PaymentReceipt $receipt, array $data): bool
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        $this->purpose($receipt->payment);

        return DB::transaction(function () use ($admin, $receipt, $data) {
            $purchase = $this->locked($receipt->payment->packagePurchase);
            $payment = Payment::whereKey($receipt->payment_id)->lockForUpdate()->firstOrFail();
            $receipt = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'approved' && $data['action'] === 'approve') {
                return $payment->status === 'paid';
            }
            if ($receipt->status !== 'pending' || $receipt->review_version !== (int) $data['expected_review_version']) {
                $this->fail('رسید قبلاً بررسی شده یا نسخه فرم قدیمی است.');
            }
            if ($data['action'] === 'approve' && (! in_array($data['statement_verified'] ?? false, [true, 1, '1', 'yes', 'on', 'true'], true) || (int) $data['verified_amount'] * 10 !== $payment->amount)) {
                $this->fail('تطبیق مستقل گردش حساب و مبلغ دقیق سفارش ضروری است.');
            }
            $receipt->update(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'review_note' => $data['review_note'] ?? null, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_version' => $receipt->review_version + 1]);
            if ($data['action'] === 'approve') {
                $allocated = $this->capture($payment, 'MANUAL-'.$receipt->id);
            } else {
                $payment->update(['status' => 'failed']);
                $receipt->claim()->delete();
                $allocated = false;
            }
            $purchase->student->notify(new ReceiptReviewed($receipt, $allocated));
            $this->audit('package_receipt.'.$receipt->status, $purchase->id, $admin->id);

            return $allocated;
        }, 3);
    }
}
