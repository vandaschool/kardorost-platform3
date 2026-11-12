<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ConsultationChanged;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationBilling
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }

    private function audit(Payment $p, string $action): void
    {
        AuditEvent::create(['actor_id' => $p->user_id, 'action' => $action, 'subject_type' => 'payment', 'subject_id' => $p->id]);
    }

    /** Caller must hold the coach and slot locks. */
    public function expireLockedSlot(ConsultationSlot $slot): void
    {
        $expired = Consultation::where('consultation_slot_id', $slot->id)->where('status', 'pending_payment')->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($expired as $item) {
            $item->update(['status' => 'expired', 'version' => $item->version + 1]);
            AuditEvent::create(['action' => 'consultation.hold_expired', 'subject_type' => 'consultation', 'subject_id' => $item->id]);
        }
        if ($expired->isNotEmpty()) {
            $live = Consultation::where('consultation_slot_id', $slot->id)->whereIn('status', ['confirmed', 'pending_payment'])->exists();
            $slot->update(['booked' => $live]);
        }
    }

    public function expire(int $slotId): void
    {
        $slot = ConsultationSlot::findOrFail($slotId);
        DB::transaction(function () use ($slot) {
            User::whereKey($slot->coach_id)->lockForUpdate()->firstOrFail();
            $slot = ConsultationSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $this->expireLockedSlot($slot);
        }, 3);
    }

    private function locks(Consultation $item): array
    {
        User::whereIn('id', [$item->student_id, $item->slot->coach_id])->orderBy('id')->lockForUpdate()->get();
        $slot = ConsultationSlot::whereKey($item->consultation_slot_id)->lockForUpdate()->firstOrFail();
        $item = Consultation::whereKey($item->id)->lockForUpdate()->firstOrFail();

        return [$item, $slot];
    }

    public function attempt(User $user, Consultation $item): Payment
    {
        abort_unless($user->id === $item->student_id && $user->is_active && $user->hasRole('student'), 403);

        return DB::transaction(function () use ($user, $item) {
            [$item,$slot] = $this->locks($item);
            if ($item->status !== 'pending_payment' || ! $item->expires_at || $item->expires_at->lte(now()) || $slot->starts_at->lte(now()) || ! $slot->coach->is_active || ! $slot->coach->hasRole('coach') || $item->amount <= 0) {
                $this->fail('رزرو پولی معتبر و در مهلت پرداخت وجود ندارد.');
            }
            if (Payment::where('consultation_id', $item->id)->where('status', 'review_required')->exists()) {
                $this->fail('پرداخت قبلی نیازمند بررسی است؛ دوباره پرداخت نکنید.');
            }
            $pending = Payment::where('consultation_id', $item->id)->where('status', 'pending')->first();
            if ($pending) {
                return $pending;
            }

            return Payment::create(['user_id' => $user->id, 'enrollment_id' => null, 'consultation_id' => $item->id, 'amount' => $item->amount, 'status' => 'pending', 'method' => 'gateway', 'attempt_expires_at' => $item->expires_at]);
        }, 3);
    }

    public function capture(Payment $payment, string $reference): bool
    {
        abort_unless($payment->consultation_id && ! $payment->organization_invoice_id && ! $payment->enrollment_id && ! $payment->installment_id, 422);
        try {
            return DB::transaction(function () use ($payment, $reference) {
                [$item,$slot] = $this->locks($payment->consultation);
                $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($p->status === 'paid') {
                    return true;
                } if (in_array($p->status, ['review_required', 'refunded'], true)) {
                    return false;
                }
                if (Payment::where('reference', $reference)->whereKeyNot($p->id)->exists()) {
                    $p->update(['status' => 'review_required', 'review_reason' => 'reference_conflict']);
                    $this->audit($p, 'consultation_payment.reference_conflict');

                    return false;
                }
                $p->fill(['reference' => $reference, 'captured_at' => now(), 'paid_at' => now()]);
                $reason = null;
                if ($p->user_id !== $item->student_id || $p->amount !== $item->amount) {
                    $reason = 'consultation_amount_mismatch';
                } elseif ($item->settled_payment_id) {
                    $reason = 'consultation_duplicate';
                } elseif ($item->status !== 'pending_payment' || ! $item->expires_at || $item->expires_at->lte(now()) || $slot->starts_at->lte(now()) || ! $slot->booked) {
                    $reason = 'consultation_expired';
                } elseif (! $item->student->is_active || ! $slot->coach->is_active || ! $item->student->hasRole('student') || ! $slot->coach->hasRole('coach')) {
                    $reason = 'inactive_account';
                }
                if ($reason) {
                    $p->fill(['status' => 'review_required', 'review_reason' => $reason]);
                } else {
                    $p->status = 'paid';
                    $item->update(['status' => 'confirmed', 'settled_payment_id' => $p->id, 'version' => $item->version + 1]);
                }
                $p->save();
                $this->audit($p, 'consultation_payment.'.$p->status);
                foreach (User::whereIn('id', [$item->student_id, $slot->coach_id])->get() as $user) {
                    $user->notify(new ConsultationChanged($reason ? 'پرداخت مشاوره نیازمند بررسی مدیریت است' : 'پرداخت تأیید و جلسه قطعی شد'));
                }

                return ! $reason;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (! Payment::where('reference', $reference)->whereKeyNot($payment->id)->exists()) {
                throw $e;
            }

            return $this->capture($payment, $reference);
        }
    }
}
