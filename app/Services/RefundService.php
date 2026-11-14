<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentAllocation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\OrganizationInvoiceChanged;
use App\Notifications\RefundChanged;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RefundService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }

    public function canonical(string $value): string
    {
        return strtoupper(preg_replace('/[\s\-]+/u', '', strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')))));
    }

    public function iban(string $value): string
    {
        $value = $this->canonical($value);
        if (! preg_match('/^IR[0-9]{24}$/D', $value)) {
            $this->fail('شماره شبای معتبر ایران با IR و ۲۴ رقم وارد کنید.');
        }
        $digits = substr($value, 4).'1827'.substr($value, 2, 2);
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }if ($remainder !== 1) {
            $this->fail('رقم کنترل شماره شبا معتبر نیست.');
        }

        return $value;
    }

    /** Uses the same parent-to-payment lock order as allocation and consultation settlement. */
    private function locked(Payment $payment): Payment
    {
        if ($payment->package_purchase_id) {
            app(PackageBilling::class)->locked($payment->packagePurchase);
        } elseif ($payment->organization_invoice_id) {
            $invoice = OrganizationInvoice::findOrFail($payment->organization_invoice_id);
            Organization::whereKey($invoice->organization_id)->lockForUpdate()->firstOrFail();
            OrganizationInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        } elseif ($payment->consultation_id) {
            $item = Consultation::findOrFail($payment->consultation_id);
            User::whereIn('id', [$item->student_id, $item->slot->coach_id])->orderBy('id')->lockForUpdate()->get();
            ConsultationSlot::whereKey($item->consultation_slot_id)->lockForUpdate()->firstOrFail();
            Consultation::whereKey($item->id)->lockForUpdate()->firstOrFail();
        } else {
            $e = Enrollment::findOrFail($payment->enrollment_id);
            Cohort::whereKey($e->cohort_id)->lockForUpdate()->firstOrFail();
            Enrollment::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($payment->installment_id) {
                Installment::whereKey($payment->installment_id)->lockForUpdate()->firstOrFail();
            }
        }

        return Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
    }

    public function eligible(Payment $p): bool
    {
        if ($p->organization_invoice_id) {
            return false;
        }
        if (! $p->captured_at || ! $p->reference || $p->amount <= 0 || ! in_array($p->status, ['paid', 'review_required'], true)) {
            return false;
        }
        if ($p->package_purchase_id) {
            return $p->status === 'review_required' && $p->packagePurchase->settled_payment_id !== $p->id;
        }
        if ($p->consultation_id) {
            $c = $p->consultation;
            if ($c->settled_payment_id === $p->id) {
                return $c->status === 'cancelled';
            }

            return $p->status === 'review_required';
        }

        return $p->status === 'review_required' && ! PaymentAllocation::where('payment_id', $p->id)->exists();
    }

    private function event(Refund $refund, User $actor, string $note): void
    {
        $refund->events()->create(['actor_id' => $actor->id, 'status' => $refund->status, 'version' => $refund->version, 'note' => $note]);
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'refund.'.$refund->status, 'subject_type' => 'refund', 'subject_id' => $refund->id]);
        if ($refund->payment->organization_invoice_id) {
            $invoice = $refund->payment->organizationInvoice;
            foreach ($invoice->organization->members()->wherePivot('kind', 'manager')->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('key', 'org_admin'))->get() as $manager) {
                $manager->notify(new OrganizationInvoiceChanged($invoice->id, 'وضعیت بازپرداخت سازمان به‌روز شد'));
            }
        } else {
            $refund->payment->user->notify(new RefundChanged($refund->status));
        }
    }

    public function eligibleOrganization(Payment $payment): bool
    {
        return (bool) $payment->organization_invoice_id && ! $payment->package_purchase_id && ! $payment->enrollment_id && ! $payment->consultation_id && ! $payment->installment_id
            && $payment->status === 'review_required' && (bool) $payment->captured_at && (bool) $payment->reference && $payment->amount > 0
            && ! OrganizationPaymentAllocation::where('payment_id', $payment->id)->exists();
    }

    private function refundable(Payment $payment): bool
    {
        return $payment->organization_invoice_id ? $this->eligibleOrganization($payment) : $this->eligible($payment);
    }

    public function request(User $actor, Payment $payment, string $destination, string $reason, int $version): Refund
    {
        abort_unless($actor->is_active && $actor->id === $payment->user_id, 403);
        if ($payment->organization_invoice_id) {
            $this->fail('بازپرداخت قرارداد سازمانی از مسیر رسیدگی سازمان پیگیری می‌شود.');
        }

        return $this->storeRequest($actor, $payment, $destination, $reason, $version);
    }

    public function requestOrganization(User $actor, Payment $payment, string $destination, string $beneficiaryName, string $reason, int $version): Refund
    {
        abort_unless($payment->organization_invoice_id, 422);
        app(OrganizationBilling::class)->manager($actor, $payment->organizationInvoice);
        $beneficiaryName = trim($beneficiaryName);
        if (mb_strlen($beneficiaryName) < 3 || mb_strlen($beneficiaryName) > 200) {
            $this->fail('نام صاحب حساب سازمان را دقیق وارد کنید.');
        }

        return $this->storeRequest($actor, $payment, $destination, $reason, $version, $beneficiaryName);
    }

    private function storeRequest(User $actor, Payment $payment, string $destination, string $reason, int $version, ?string $beneficiaryName = null): Refund
    {
        $destination = $this->iban($destination);

        return DB::transaction(function () use ($actor, $payment, $destination, $reason, $version, $beneficiaryName) {
            $p = $this->locked($payment);
            if ($p->organization_invoice_id) {
                app(OrganizationBilling::class)->manager($actor, $p->organizationInvoice);
            }
            $refund = Refund::where('payment_id', $p->id)->lockForUpdate()->first();
            if (! $this->refundable($p)) {
                $this->fail('این پرداخت در وضعیت فعلی مشمول مسیر بازپرداخت نیست.');
            }
            if ($refund) {
                if ($refund->status !== 'rejected' || $refund->version !== $version) {
                    $this->fail('درخواست بازپرداخت تغییر کرده یا قبلاً ثبت شده است.');
                }$refund->fill(['status' => 'requested', 'destination' => $destination, 'beneficiary_name' => $beneficiaryName, 'reason' => $reason, 'review_note' => null, 'reviewed_at' => null, 'reviewed_by' => null, 'version' => $refund->version + 1]);
                $refund->save();
            } else {
                if ($version !== 0) {
                    $this->fail('نسخه درخواست معتبر نیست.');
                }$refund = Refund::create(['payment_id' => $p->id, 'amount' => $p->amount, 'destination' => $destination, 'beneficiary_name' => $beneficiaryName, 'reason' => $reason]);
            }
            $this->event($refund, $actor, $reason);

            return $refund;
        }, 3);
    }

    public function review(User $admin, Refund $refund, int $version, string $action, string $note): void
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        DB::transaction(function () use ($admin, $refund, $version, $action, $note) {
            $p = $this->locked($refund->payment);
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'requested' || $refund->version !== $version) {
                $this->fail('درخواست تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if (! $this->refundable($p) || $refund->amount !== $p->amount) {
                $this->fail('وضعیت پرداخت دیگر با بازپرداخت تطبیق ندارد.');
            }
            if (! in_array($action, ['approve', 'reject'], true)) {
                $this->fail('تصمیم معتبر نیست.');
            }
            $refund->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note, 'version' => $refund->version + 1]);
            $this->event($refund, $admin, $note);
        }, 3);
    }

    public function complete(User $admin, Refund $refund, array $data, UploadedFile $file): void
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        if (! in_array($data['statement_verified'] ?? false, [true, 1, '1', 'yes', 'on', 'true'], true) || ! in_array($data['beneficiary_verified'] ?? false, [true, 1, '1', 'yes', 'on', 'true'], true)) {
            $this->fail('تطبیق گردش حساب و هویت گیرنده ضروری است.');
        }
        $reference = $this->canonical($data['transfer_reference']);
        if (! preg_match('/^[A-Z0-9]{6,100}$/D', $reference)) {
            $this->fail('شناسه واریز معتبر وارد کنید.');
        }
        $key = hash('sha256', $data['transfer_date'].'|'.$reference);
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('proofs', 'refunds');
        try {
            DB::transaction(function () use ($admin, $refund, $data, $file, $reference, $key, $hash, $path) {
                $p = $this->locked($refund->payment);
                $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                if ($refund->status !== 'approved' || $refund->version !== (int) $data['version']) {
                    $this->fail('درخواست برای ثبت واریز معتبر نیست یا قبلاً تکمیل شده است.');
                }
                if (! $this->refundable($p) || (int) $data['amount'] * 10 !== $refund->amount || $refund->amount !== $p->amount) {
                    $this->fail('مبلغ برگشت باید دقیقاً با کل مبلغ تأییدشده برابر باشد.');
                }
                if ($this->iban($data['destination']) !== $refund->destination) {
                    $this->fail('شبای گیرنده با درخواست تأییدشده یکسان نیست.');
                }
                if ($data['transfer_date'] > now()->toDateString() || $data['transfer_date'] < $p->captured_at->toDateString()) {
                    $this->fail('تاریخ واریز باید بین تاریخ دریافت وجه و امروز باشد.');
                }
                if (Refund::where('transfer_key', $key)->orWhere('file_hash', $hash)->exists()) {
                    $this->fail('این مدرک یا شناسه واریز قبلاً استفاده شده است.');
                }
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['\\', '/'], '_', $file->getClientOriginalName())), 0, 200);
                $refund->update(['status' => 'completed', 'version' => $refund->version + 1, 'transfer_reference' => $reference, 'transfer_date' => $data['transfer_date'], 'transfer_key' => $key, 'file_hash' => $hash, 'file_path' => $path, 'file_name' => $name, 'completed_by' => $admin->id, 'completed_at' => now()]);
                $p->update(['status' => 'refunded']);
                if ($p->package_purchase_id) {
                    $purchase = $p->packagePurchase;
                    if (! $purchase->settled_payment_id && ! $purchase->payments()->where('status', '!=', 'refunded')->where(fn ($query) => $query->whereNotNull('captured_at')->orWhereIn('status', ['pending_review', 'review_required']))->exists()) {
                        $purchase->update(['status' => 'cancelled']);
                    }
                }
                if ($p->consultation_id && $p->consultation->settled_payment_id === $p->id) {
                    $p->consultation->update(['refund_status' => 'completed']);
                }
                $this->event($refund, $admin, 'مدرک برگشت کامل وجه و تطبیق گیرنده ثبت شد.');
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('refunds')->delete($path);
            if ($exception instanceof UniqueConstraintViolationException) {
                $this->fail('مدرک یا شناسه واریز هم‌زمان برای درخواست دیگری ثبت شده است.');
            }

            throw $exception;
        }
    }
}
