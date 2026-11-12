<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Payment;
use App\Services\ConsultationBilling;
use App\Services\PaymentGateway;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ConsultationPaymentController extends Controller
{
    public function pay(Request $r, Consultation $consultation, ConsultationBilling $billing, PaymentGateway $gateway): Response
    {
        abort_unless($r->user()->id === $consultation->student_id, 403);
        try {
            return Cache::lock('consultation-payment:'.$consultation->id, 30)->block(3, function () use ($r, $consultation, $billing, $gateway) {
                $p = $billing->attempt($r->user(), $consultation);
                if ($p->track_id) {
                    return Inertia::location('https://gateway.zibal.ir/start/'.rawurlencode($p->track_id));
                }
                try {
                    $url = $gateway->start($p);
                } catch (\Throwable $e) {
                    $p->update(['status' => 'failed']);
                    if ($e instanceof ValidationException) {
                        throw $e;
                    }

                    return back()->with('error', 'اتصال به درگاه انجام نشد. دوباره تلاش کنید.');
                }
                $consultation->refresh();
                if ($consultation->status !== 'pending_payment' || $consultation->expires_at->lte(now())) {
                    return back()->with('error', 'مهلت رزرو پایان یافته یا رزرو لغو شده است؛ پرداخت نکنید.');
                }

                return Inertia::location($url);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['payment' => 'درخواست پرداخت در حال پردازش است؛ کمی صبر کنید.']);
        }
    }

    public function callback(Request $r, PaymentGateway $gateway, ConsultationBilling $billing): RedirectResponse
    {
        $track = $r->query('trackId');
        abort_unless(is_string($track) && strlen($track) <= 100, 400);
        $p = Payment::where('track_id', $track)->whereNotNull('consultation_id')->firstOrFail();
        if ($p->status === 'refunded') {
            return redirect()->route('refunds')->with('success', 'مدرک بازپرداخت این تراکنش قبلاً ثبت شده است.');
        }
        if ($p->status === 'paid') {
            return redirect()->route('consultations')->with('success', 'پرداخت قبلاً تأیید شده است.');
        }
        if ($p->status === 'review_required') {
            return redirect()->route('consultations')->with('error', 'پرداخت نیازمند بررسی مدیریت است؛ دوباره پرداخت نکنید.');
        }
        try {
            $reference = $gateway->verify($p);
        } catch (\Throwable) {
            return redirect()->route('consultations')->with('error', 'تأیید درگاه در دسترس نیست. وضعیت را از بخش مالی پیگیری کنید.');
        }
        if (! $reference) {
            return redirect()->route('consultations')->with('error', 'پرداخت هنوز تأیید نشده است. در صورت کسر وجه با پشتیبانی تماس بگیرید.');
        }
        $ok = $billing->capture($p, $reference);

        return redirect()->route('consultations')->with($ok ? 'success' : 'error', $ok ? 'پرداخت تأیید و جلسه قطعی شد.' : 'وجه ثبت شد؛ رزرو نیازمند بررسی مدیریت است. دوباره پرداخت نکنید.');
    }
}
