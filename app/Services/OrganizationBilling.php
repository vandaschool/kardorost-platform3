<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationLicense;
use App\Models\OrganizationPaymentAllocation;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptClaim;
use App\Models\User;
use App\Notifications\OrganizationInvoiceChanged;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrganizationBilling
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }

    private function admin(User $actor): void
    {
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
    }

    public function manager(User $actor, OrganizationInvoice $invoice): void
    {
        abort_unless($actor->is_active && $actor->hasRole('org_admin') && $invoice->organization->status === 'active' && $invoice->organization->members()->where('users.id', $actor->id)->wherePivot('kind', 'manager')->exists(), 403);
    }

    private function locked(OrganizationInvoice $invoice): OrganizationInvoice
    {
        Organization::whereKey($invoice->organization_id)->lockForUpdate()->firstOrFail();

        return OrganizationInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function audit(User|int|null $actor, string $action, string $type, int $id, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $actor instanceof User ? $actor->id : $actor, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => $metadata]);
    }

    private function notify(OrganizationInvoice $invoice, string $title): void
    {
        foreach ($invoice->organization->members()->wherePivot('kind', 'manager')->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'org_admin'))->get() as $manager) {
            $manager->notify(new OrganizationInvoiceChanged($invoice->id, $title));
        }
    }

    private function fields(array $input): array
    {
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'contract_reference' => 'required|string|max:200', 'due_date' => 'required|date_format:Y-m-d', 'organization_license_id' => 'nullable|integer', 'lines' => 'required|array|min:1|max:50', 'lines.*.title' => 'required|string|max:200', 'lines.*.quantity' => 'required|integer|min:1|max:10000', 'lines.*.unit_price' => 'required|integer|min:1|max:1000000000'])->validate();
        $lines = [];
        $amount = 0;
        foreach ($data['lines'] as $line) {
            $line = ['title' => $line['title'], 'quantity' => (int) $line['quantity'], 'unit_price' => (int) $line['unit_price'] * 10];
            $amount += $line['quantity'] * $line['unit_price'];
            $lines[] = $line;
        }if ($amount > 1000000000000) {
            $this->fail('جمع صورتحساب از سقف قابل ثبت بیشتر است.');
        }

        return ['title' => $data['title'], 'contract_reference' => $data['contract_reference'], 'due_date' => $data['due_date'], 'organization_license_id' => $data['organization_license_id'] ?? null, 'lines' => $lines, 'amount' => $amount];
    }

    public function draft(User $actor, Organization $organization, array $input, ?OrganizationInvoice $invoice = null, ?int $revision = null): OrganizationInvoice
    {
        $this->admin($actor);
        $data = $this->fields($input);

        return DB::transaction(function () use ($actor, $organization, $data, $invoice, $revision) {
            $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if ($organization->status !== 'active') {
                $this->fail('سازمان فعال لازم است.');
            }
            if ($invoice) {
                $invoice = OrganizationInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($invoice->organization_id !== $organization->id || $invoice->status !== 'draft' || $invoice->revision !== $revision) {
                    $this->fail('فقط پیش‌نویس با نسخه تازه قابل ویرایش است.');
                }
            }
            if ($data['organization_license_id'] && ! OrganizationLicense::whereKey($data['organization_license_id'])->where('organization_id', $organization->id)->exists()) {
                $this->fail('سهمیه انتخاب‌شده متعلق به این سازمان نیست.');
            }
            if ($invoice) {
                $invoice->update([...$data, 'organization_name' => $organization->name, 'revision' => $invoice->revision + 1]);
            } else {
                $invoice = OrganizationInvoice::create([...$data, 'organization_id' => $organization->id, 'organization_name' => $organization->name, 'created_by' => $actor->id]);
            }
            $this->audit($actor, 'organization_invoice.draft_saved', 'organization_invoice', $invoice->id);

            return $invoice;
        }, 3);
    }

    public function issue(User $actor, OrganizationInvoice $invoice, int $revision): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $invoice, $revision) {
            $invoice = $this->locked($invoice);
            if ($invoice->status !== 'draft' || $invoice->revision !== $revision || $invoice->organization->status !== 'active') {
                $this->fail('پیش‌نویس معتبر و سازمان فعال لازم است.');
            }
            $destination = app(ReceiptService::class)->normalizeReference((string) config('platform.finance.transfer_destination'));
            $holder = trim((string) config('platform.finance.transfer_holder'));
            if (! preg_match('/^(?:IR[0-9]{24}|[0-9]{8,30})$/D', $destination) || ! $holder) {
                $this->fail('حساب مقصد و نام صاحب حساب باید پیش از صدور روی سرور تنظیم شوند.');
            }
            $invoice->update(['status' => 'issued', 'number' => 'ORG-'.str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT), 'destination' => $destination, 'holder' => $holder, 'issued_at' => now(), 'revision' => $invoice->revision + 1]);
            $this->audit($actor, 'organization_invoice.issued', 'organization_invoice', $invoice->id);
            $this->notify($invoice, 'صورتحساب سازمان صادر شد');
        }, 3);
    }

    public function void(User $actor, OrganizationInvoice $invoice, int $revision, string $reason): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $invoice, $revision, $reason) {
            $invoice = $this->locked($invoice);
            if (! in_array($invoice->status, ['draft', 'issued'], true) || $invoice->revision !== $revision || $invoice->payments()->where(fn ($q) => $q->whereNotNull('captured_at')->orWhere('status', 'pending_review')->orWhere(fn ($q) => $q->where('status', 'pending')->where('attempt_expires_at', '>', now())))->exists()) {
                $this->fail('صورت‌حساب دارای وجه یا پرداخت در جریان را نمی‌توان باطل کرد.');
            }
            $invoice->update(['status' => 'void', 'void_reason' => $reason, 'voided_at' => now(), 'revision' => $invoice->revision + 1]);
            $this->audit($actor, 'organization_invoice.voided', 'organization_invoice', $invoice->id);
            $this->notify($invoice, 'صورتحساب سازمان باطل شد');
        }, 3);
    }

    private function payable(User $actor, OrganizationInvoice $invoice): void
    {
        $this->manager($actor, $invoice);
        if ($invoice->status !== 'issued' || $invoice->remainingAmount() === 0) {
            $this->fail('این صورتحساب برای پرداخت تازه معتبر نیست.');
        }if ($invoice->payments()->whereIn('status', ['pending_review', 'review_required'])->exists()) {
            $this->fail('رسید یا وجه قبلی نیازمند بررسی است؛ دوباره پرداخت نکنید.');
        }
    }

    private function paymentAmount(OrganizationInvoice $invoice, ?int $amountToman): int
    {
        $remaining = $invoice->remainingAmount();
        if ($amountToman !== null && ($amountToman < 1 || $amountToman > intdiv($remaining, 10))) {
            $this->fail('مبلغ پرداخت باید مثبت و حداکثر برابر مانده صورتحساب باشد.');
        }

        return $amountToman === null ? $remaining : $amountToman * 10;
    }

    public function attempt(User $actor, OrganizationInvoice $invoice, ?int $amountToman = null): Payment
    {
        return DB::transaction(function () use ($actor, $invoice, $amountToman) {
            $invoice = $this->locked($invoice);
            $this->payable($actor, $invoice);
            $amount = $this->paymentAmount($invoice, $amountToman);
            $pending = $invoice->payments()->where('method', 'gateway')->where('status', 'pending')->where('attempt_expires_at', '>', now())->first();
            if ($pending) {
                if ($pending->amount !== $amount) {
                    $this->fail('درخواست درگاه با مبلغ دیگری در جریان است؛ تا تعیین تکلیف آن صبر کنید.');
                }

                return $pending;
            }$invoice->payments()->where('status', 'pending')->update(['status' => 'expired']);

            return Payment::create(['organization_invoice_id' => $invoice->id, 'enrollment_id' => null, 'user_id' => $actor->id, 'payer_name_snapshot' => $actor->name, 'amount' => $amount, 'method' => 'gateway', 'status' => 'pending', 'attempt_expires_at' => now()->addMinutes(15)]);
        }, 3);
    }

    public function receipt(User $actor, OrganizationInvoice $invoice, UploadedFile $file, string $reference, string $date, ?int $amountToman = null): PaymentReceipt
    {
        $reference = app(ReceiptService::class)->normalizeReference($reference);
        if (! preg_match('/^[A-Z0-9]{4,100}$/D', $reference)) {
            $this->fail('شناسه واریز معتبر وارد کنید.');
        }
        $filePath = $file->store('organization', 'receipts');
        $fileHash = hash_file('sha256', $file->getRealPath());
        try {
            return DB::transaction(function () use ($actor, $invoice, $file, $reference, $date, $filePath, $fileHash, $amountToman) {
                $invoice = $this->locked($invoice);
                $this->payable($actor, $invoice);
                $amount = $this->paymentAmount($invoice, $amountToman);
                if ($invoice->payments()->where('status', 'pending')->where('attempt_expires_at', '>', now())->exists()) {
                    $this->fail('درخواست درگاه در جریان است؛ تا تعیین تکلیف دوباره رسید نفرستید.');
                }
                if ($date > now()->toDateString()) {
                    $this->fail('تاریخ واریز نمی‌تواند در آینده باشد.');
                }
                $claimKey = hash('sha256', $invoice->destination.'|'.$date.'|'.$reference);
                if (ReceiptClaim::where('file_hash', $fileHash)->orWhere('transfer_key', $claimKey)->exists()) {
                    $this->fail('این رسید یا شناسه واریز قبلاً در سامانه استفاده شده است.');
                }
                $payment = Payment::create(['organization_invoice_id' => $invoice->id, 'enrollment_id' => null, 'user_id' => $actor->id, 'payer_name_snapshot' => $actor->name, 'amount' => $amount, 'method' => 'manual', 'status' => 'pending_review']);
                $fileName = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['\\', '/'], '_', $file->getClientOriginalName())), 0, 200);
                $receipt = PaymentReceipt::create(['payment_id' => $payment->id, 'file_path' => $filePath, 'file_name' => $fileName, 'file_hash' => $fileHash, 'destination' => $invoice->destination, 'transfer_reference' => $reference, 'transfer_date' => $date]);
                ReceiptClaim::create(['payment_receipt_id' => $receipt->id, 'file_hash' => $fileHash, 'transfer_key' => $claimKey]);
                $this->audit($actor, 'organization_invoice.receipt_submitted', 'payment_receipt', $receipt->id);

                return $receipt;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('receipts')->delete($filePath);
            if ($exception instanceof UniqueConstraintViolationException) {
                $this->fail('همین مدرک یا شناسه واریز هم‌زمان ثبت شده است.');
            }throw $exception;
        }
    }

    public function review(User $actor, PaymentReceipt $receipt, array $data): bool
    {
        $this->admin($actor);
        abort_unless($receipt->payment->organization_invoice_id, 422);
        if (! in_array($data['action'], ['approve', 'reject'], true)) {
            $this->fail('نتیجه بررسی معتبر نیست.');
        }

        return DB::transaction(function () use ($actor, $receipt, $data) {
            $invoice = $this->locked($receipt->payment->organizationInvoice);
            $payment = Payment::whereKey($receipt->payment_id)->lockForUpdate()->firstOrFail();
            $receipt = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'pending' || $receipt->review_version !== (int) $data['expected_review_version']) {
                $this->fail('رسید قبلاً بررسی شده یا نسخه فرم تغییر کرده است.');
            }
            if ($data['action'] === 'approve') {
                if (! in_array($data['statement_verified'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true) || (int) ($data['verified_amount'] ?? 0) * 10 !== $payment->amount) {
                    $this->fail('بررسی مستقل گردش حساب و مبلغ دقیق لازم است.');
                }$receipt->update(['status' => 'approved']);
            } else {
                $receipt->update(['status' => 'rejected']);
                $payment->update(['status' => 'failed']);
                $receipt->claim()->delete();
            }
            $receipt->update(['review_note' => $data['review_note'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_version' => $receipt->review_version + 1]);
            $settled = $data['action'] === 'approve' ? $this->capture($payment, 'MANUAL-'.$receipt->id) : false;
            $this->audit($actor, 'organization_invoice.receipt_'.$receipt->status, 'payment_receipt', $receipt->id);
            if ($data['action'] === 'reject') {
                $this->notify($invoice, 'رسید صورتحساب سازمان رد شد');
            }

            return $settled;
        }, 3);
    }

    public function capture(Payment $payment, string $reference): bool
    {
        abort_unless($payment->organization_invoice_id && ! $payment->enrollment_id && ! $payment->consultation_id && ! $payment->installment_id, 422);
        try {
            return DB::transaction(function () use ($payment, $reference) {
                $invoice = $this->locked($payment->organizationInvoice);
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($payment->status === 'paid') {
                    return true;
                }if (in_array($payment->status, ['review_required', 'refunded'], true)) {
                    return false;
                }
                if ($payment->method === 'manual' && $payment->receipt?->status !== 'approved') {
                    $this->fail('رسید باید ابتدا بررسی و تأیید شود.');
                }
                if (Payment::where('reference', $reference)->whereKeyNot($payment->id)->exists()) {
                    $payment->update(['status' => 'review_required', 'review_reason' => 'reference_conflict']);
                    $this->audit(null, 'organization_invoice.reference_conflict', 'payment', $payment->id);

                    return false;
                }
                $remaining = $invoice->remainingAmount();
                $reason = match (true) {
                    $invoice->status === 'paid' => 'organization_invoice_duplicate',
                    $invoice->status !== 'issued' => 'organization_invoice_void',
                    $payment->amount < 1 || $payment->amount % 10 !== 0 => 'organization_invoice_mismatch',
                    $payment->amount > $remaining => 'organization_invoice_excess',
                    default => null,
                };
                $payment->update(['reference' => $reference, 'captured_at' => now(), 'paid_at' => now(), 'status' => $reason ? 'review_required' : 'paid', 'review_reason' => $reason]);
                if (! $reason) {
                    OrganizationPaymentAllocation::create(['organization_invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => $payment->amount]);
                    $invoice->update(['status' => $payment->amount === $remaining ? 'paid' : 'issued', 'paid_at' => $payment->amount === $remaining ? now() : null, 'settled_payment_id' => $payment->amount === $remaining ? $payment->id : null, 'revision' => $invoice->revision + 1]);
                }
                $this->audit(null, 'organization_invoice.payment_'.$payment->status, 'payment', $payment->id);
                $this->notify($invoice, $reason ? 'پرداخت سازمان نیازمند بررسی است' : ($invoice->status === 'paid' ? 'صورتحساب سازمان تسویه شد' : 'پرداخت بخشی از صورتحساب سازمان تأیید شد'));

                return ! $reason;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Payment::where('reference', $reference)->whereKeyNot($payment->id)->exists()) {
                throw $exception;
            }

            return $this->capture($payment, $reference);
        }
    }
}
