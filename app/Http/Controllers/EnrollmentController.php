<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Payment;
use App\Services\BillingService;
use App\Services\EnrollmentService;
use App\Services\PaymentGateway;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentController extends Controller
{
    public function store(Request $request, Cohort $cohort, EnrollmentService $service): RedirectResponse
    {
        $data = $request->validate(['payment_plan' => 'sometimes|in:full,installments']);
        $enrollment = $service->reserve($request->user(), $cohort, $data['payment_plan'] ?? 'full');

        return redirect()->route('learning')->with('success', $enrollment->status === 'active' ? 'ثبت‌نام شما انجام شد.' : 'ظرفیت برای ۱۵ دقیقه نگه داشته شد. پرداخت را از بخش مالی تکمیل کنید.');
    }

    public function pay(Request $request, Enrollment $enrollment, PaymentGateway $gateway, BillingService $billing): Response
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);
        $invoice = $billing->current($enrollment)->whereNull('paid_at')->orderBy('sequence')->first();
        if (! $invoice) {
            throw ValidationException::withMessages(['payment' => 'صورتحساب پرداخت‌نشده‌ای وجود ندارد.']);
        }

        return $this->payInstallment($request, $invoice, $gateway, $billing);
    }

    public function payInstallment(Request $request, Installment $installment, PaymentGateway $gateway, BillingService $billing): Response
    {
        abort_unless($installment->enrollment->user_id === $request->user()->id, 403);
        try {
            return Cache::lock('installment-payment:'.$installment->id, 30)->block(3, function () use ($request, $installment, $gateway, $billing) {
                $payment = $billing->attempt($request->user(), $installment);
                if ($payment->track_id) {
                    return Inertia::location('https://gateway.zibal.ir/start/'.rawurlencode($payment->track_id));
                }
                try {
                    $url = $gateway->start($payment);
                } catch (\Throwable $e) {
                    $payment->update(['status' => 'failed']);
                    if ($e instanceof ValidationException) {
                        throw $e;
                    }

                    return back()->with('error', 'اتصال به درگاه انجام نشد. دوباره تلاش کنید.');
                }
                $payment->refresh();
                $installment->refresh();
                if ($installment->voided_at || $payment->attempt_expires_at?->isPast()) {
                    $payment->update(['status' => 'expired']);
                    throw ValidationException::withMessages(['payment' => 'مهلت پرداخت هنگام اتصال به درگاه پایان یافت؛ دوباره ثبت‌نام کنید.']);
                }

                return Inertia::location($url);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['payment' => 'درخواست پرداخت دیگری در حال پردازش است. کمی صبر کنید و دوباره وضعیت را ببینید.']);
        }
    }

    public function callback(Request $request, PaymentGateway $gateway, EnrollmentService $service): RedirectResponse
    {
        $track = $request->query('trackId');
        abort_unless(is_string($track) && strlen($track) <= 100, 400);
        $payment = Payment::where('track_id', $track)->whereNull('consultation_id')->whereNull('organization_invoice_id')->firstOrFail();
        if ($payment->status === 'refunded') {
            return redirect()->route('refunds')->with('success', 'مدرک بازپرداخت این تراکنش قبلاً ثبت شده است.');
        }
        if ($payment->status === 'paid') {
            return redirect()->route('learning')->with('success', 'پرداخت قبلاً تأیید شده است.');
        }
        if ($payment->status === 'review_required') {
            return redirect()->route('learning')->with('error', 'پرداخت شما نیازمند بررسی مدیریت است. دوباره پرداخت نکنید.');
        }
        try {
            $reference = $gateway->verify($payment);
        } catch (\Throwable) {
            return redirect()->route('learning')->with('error', 'تأیید درگاه در دسترس نیست. وضعیت را از بخش مالی پیگیری کنید.');
        }
        if (! $reference) {
            return redirect()->route('learning')->with('error', 'پرداخت هنوز تأیید نشده است. در صورت کسر وجه با پشتیبانی تماس بگیرید.');
        }
        $settled = $service->settle($payment, $reference);

        return redirect()->route('learning')->with($settled ? 'success' : 'error', $settled ? 'پرداخت تأیید و به صورتحساب تخصیص یافت.' : 'پرداخت دریافت شد؛ تخصیص مبلغ نیازمند بررسی مدیریت است.');
    }
}
