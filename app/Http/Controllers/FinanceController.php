<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function index(Request $request, BillingService $billing): Response
    {
        $enrollments = Enrollment::where('user_id', $request->user()->id)->where('amount', '>', 0)->with('course', 'cohort')->latest()->get();

        return Inertia::render('Finance', [
            'enrollments' => $enrollments->map(fn ($e) => ['id' => $e->id, 'title' => $e->course->title, 'cohort' => $e->cohort->title, 'status' => $e->status, 'payment_plan' => $e->payment_plan, 'expires_at' => $e->expires_at, 'summary' => $billing->summary($e),
                'installments' => $billing->current($e)->with('payments.receipt')->orderBy('sequence')->get()->map(function ($i) use ($billing, $e, $request) {
                    $reason = null;
                    try {
                        $billing->payable($request->user(), $e, $i);
                    } catch (ValidationException $exception) {
                        $reason = collect($exception->errors())->flatten()->first();
                    }
                    $pending = $i->payments->firstWhere('status', 'pending_review');
                    if ($pending) {
                        $reason = 'رسید شما در انتظار بررسی مدیریت است.';
                    }

                    return ['id' => $i->id, 'sequence' => $i->sequence, 'amount' => $i->amount, 'due_date' => $i->due_date->toDateString(), 'paid_at' => $i->paid_at, 'overdue' => $e->status === 'active' && $i->overdue(), 'can_pay' => $reason === null, 'blocked_message' => $reason, 'manual_enabled' => $e->cohort->manual_payment_enabled && (bool) config('platform.finance.transfer_destination'), 'pending_receipt' => $pending?->receipt ? $this->receiptData($pending->receipt) : null];
                })]),
            'payments' => Payment::where('user_id', $request->user()->id)->with('enrollment.course', 'consultation.slot.coach', 'installment', 'receipt')->latest()->paginate(20)->through(fn ($p) => $this->paymentData($p, $billing)),
            'gateway_enabled' => (bool) config('platform.zibal.enabled'), 'transfer' => ['destination' => config('platform.finance.transfer_destination'), 'holder' => config('platform.finance.transfer_holder')],
        ]);
    }

    public function admin(Request $request, BillingService $billing): Response
    {
        $data = $request->validate(['tab' => 'nullable|in:receipts,payments,debts', 'status' => 'nullable|in:all,pending,approved,rejected,paid,pending_review,review_required,failed,expired']);
        $tab = $data['tab'] ?? 'receipts';
        $status = $data['status'] ?? ($tab === 'receipts' ? 'pending' : 'all');
        $rows = null;
        if ($tab === 'receipts') {
            $rows = PaymentReceipt::with('payment.enrollment.course', 'payment.installment', 'payment.enrollment.user')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->paginate(20)->withQueryString()->through(fn ($r) => [...$this->receiptData($r), 'payment' => $this->paymentData($r->payment, $billing), 'student' => $r->payment->enrollment->user->only('id', 'name', 'phone')]);
        } elseif ($tab === 'payments') {
            $rows = Payment::with('enrollment.course', 'enrollment.user', 'user', 'consultation.slot.coach', 'installment', 'receipt')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->paginate(20)->withQueryString()->through(fn ($p) => [...$this->paymentData($p, $billing), 'student' => $p->user->only('id', 'name', 'phone')]);
        } else {
            $rows = Installment::whereNull('paid_at')->whereNull('voided_at')->where('due_date', '<', now()->toDateString())->whereHas('enrollment', fn ($q) => $q->where('status', 'active')->whereColumn('enrollments.billing_version', 'installments.billing_version'))->with('enrollment.course', 'enrollment.user')->orderBy('due_date')->paginate(20)->withQueryString()->through(fn ($i) => ['id' => $i->id, 'sequence' => $i->sequence, 'amount' => $i->amount, 'due_date' => $i->due_date->toDateString(), 'title' => $i->enrollment->course->title, 'student' => $i->enrollment->user->only('id', 'name', 'phone')]);
        }

        return Inertia::render('AdminFinance', ['tab' => $tab, 'status' => $status, 'rows' => $rows, 'stats' => ['allocated' => (int) PaymentAllocation::sum('amount') + (int) Payment::whereNotNull('consultation_id')->where('status', 'paid')->sum('amount'), 'unallocated' => (int) Payment::where('status', 'review_required')->whereNotNull('captured_at')->sum('amount'), 'pending_receipts' => PaymentReceipt::where('status', 'pending')->count()]]);
    }

    public function allocate(Request $request, Payment $payment, BillingService $billing): RedirectResponse
    {
        $billing->resolve($payment, $request->user());

        return back()->with('success', 'پرداخت تأییدشده به صورتحساب تخصیص یافت.');
    }

    public function receiptData(PaymentReceipt $receipt): array
    {
        return [...$receipt->only('id', 'file_name', 'destination', 'transfer_reference', 'transfer_date', 'status', 'review_note', 'reviewed_at', 'review_version', 'created_at'), 'transfer_date' => $receipt->transfer_date->toDateString(), 'download_url' => route('receipts.file', $receipt)];
    }

    public function paymentData(Payment $payment, BillingService $billing): array
    {
        return [...$payment->only('id', 'amount', 'status', 'method', 'reference', 'captured_at', 'paid_at', 'created_at'), 'title' => $payment->consultation_id ? 'مشاوره با '.$payment->consultation->slot->coach->name : $payment->enrollment->course->title, 'refund_status' => $payment->consultation?->refund_status, 'sequence' => $payment->installment?->sequence, 'review_label' => $payment->review_reason ? $billing->reasonLabel($payment->review_reason) : null, 'can_allocate' => ! $payment->consultation_id && $payment->status === 'review_required' && (bool) $payment->captured_at && (bool) $payment->reference, 'receipt' => $payment->receipt ? $this->receiptData($payment->receipt) : null];
    }
}
