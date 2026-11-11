<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\EnrollmentService;
use App\Services\PaymentGateway;
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
        $enrollment = $service->reserve($request->user(), $cohort);

        return redirect()->route('learning')->with('success', $enrollment->status === 'active' ? 'ثبت‌نام شما انجام شد.' : 'ظرفیت برای ۱۵ دقیقه نگه داشته شد. برای تکمیل، پرداخت را انجام دهید.');
    }

    public function pay(Request $request, Enrollment $enrollment, PaymentGateway $gateway): Response
    {
        abort_unless($enrollment->user_id === $request->user()->id, 403);

        return Cache::lock('enrollment-payment:'.$enrollment->id, 30)->block(3, function () use ($enrollment, $gateway) {
            $enrollment->refresh();
            abort_unless($enrollment->status === 'pending_payment' && $enrollment->expires_at?->isFuture(), 409, 'مهلت پرداخت تمام شده است. دوباره ثبت‌نام کنید.');
            $pending = Payment::where('enrollment_id', $enrollment->id)->where('status', 'pending')->whereNotNull('track_id')->latest()->first();
            if ($pending) {
                return Inertia::location('https://gateway.zibal.ir/start/'.rawurlencode($pending->track_id));
            }
            $payment = Payment::create(['user_id' => $enrollment->user_id, 'enrollment_id' => $enrollment->id, 'amount' => $enrollment->amount]);
            try {
                $url = $gateway->start($payment);
            } catch (\Throwable $e) {
                $payment->update(['status' => 'failed']);
                if ($e instanceof ValidationException) {
                    throw $e;
                }

                return back()->with('error', 'اتصال به درگاه انجام نشد. دوباره تلاش کنید.');
            }

            return Inertia::location($url);
        });
    }

    public function callback(Request $request, PaymentGateway $gateway, EnrollmentService $service): RedirectResponse
    {
        $track = $request->query('trackId');
        abort_unless(is_string($track) && strlen($track) <= 100, 400);
        $payment = Payment::where('track_id', $track)->firstOrFail();
        if ($payment->status === 'paid') {
            return redirect()->route('learning')->with('success', 'پرداخت قبلاً تأیید شده است.');
        }
        if ($payment->status === 'review_required') {
            return redirect()->route('learning')->with('error', 'پرداخت شما دریافت شده و نیازمند بررسی پشتیبانی است.');
        }
        try {
            $reference = $gateway->verify($payment);
        } catch (\Throwable) {
            return redirect()->route('learning')->with('error', 'تأیید درگاه در دسترس نیست. وضعیت پرداخت را دوباره بررسی کنید.');
        }
        if (! $reference) {
            return redirect()->route('learning')->with('error', 'پرداخت هنوز تأیید نشده است. در صورت کسر وجه با پشتیبانی تماس بگیرید.');
        }
        $settled = $service->settle($payment, $reference);

        return redirect()->route('learning')->with($settled ? 'success' : 'error', $settled ? 'پرداخت و ثبت‌نام تأیید شد.' : 'پرداخت دریافت شد؛ ثبت‌نام نیاز به بررسی پشتیبانی دارد.');
    }
}
