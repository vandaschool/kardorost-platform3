<?php

namespace App\Http\Controllers;

use App\Models\ConsultationProduct;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Services\BillingService;
use App\Services\PackageBilling;
use App\Services\PaymentGateway;
use App\Services\ReceiptService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PackagePurchaseController extends Controller
{
    public function create(Request $request, ConsultationProduct $product, PackageBilling $billing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'accepted' => 'required|accepted']);
        $purchase = $billing->reserve($request->user(), $product, (int) $data['revision']);

        return redirect()->route('package-purchases.show', $purchase);
    }

    public function show(Request $request, PackagePurchase $purchase, PackageBilling $billing): \Inertia\Response
    {
        $admin = $request->user()->hasRole('admin');
        abort_unless($admin || $request->user()->id === $purchase->student_id, 403);
        $reason = null;
        if ($request->user()->id === $purchase->student_id && $request->user()->hasRole('student')) {
            try {
                $billing->payable($request->user(), $purchase);
            } catch (ValidationException $exception) {
                $reason = collect($exception->errors())->flatten()->first();
            }
        } else {
            $reason = 'پرداخت فقط توسط صاحب سفارش انجام می‌شود.';
        }

        return Inertia::render('PackagePurchase', ['admin' => $admin, 'purchase' => [...$purchase->only('id', 'definition', 'amount', 'status', 'expires_at', 'consultation_grant_id'), 'can_pay' => $reason === null, 'has_gateway_attempt' => $purchase->payments()->where('status', 'pending')->where('attempt_expires_at', '>', now())->exists(), 'blocked_message' => $reason, ...($admin ? ['student' => $purchase->student->only('name', 'phone')] : [])], 'payments' => $purchase->payments()->with('receipt', 'refund', 'packagePurchase')->latest()->get()->map(fn ($payment) => app(FinanceController::class)->paymentData($payment, app(BillingService::class))), 'gateway_enabled' => (bool) config('platform.zibal.enabled'), 'transfer' => ['destination' => config('platform.finance.transfer_destination'), 'holder' => config('platform.finance.transfer_holder')]]);
    }

    public function pay(Request $request, PackagePurchase $purchase, PackageBilling $billing, PaymentGateway $gateway): Response
    {
        abort_unless($request->user()->id === $purchase->student_id, 403);
        try {
            return Cache::lock('package-payment:'.$purchase->id, 30)->block(3, function () use ($request, $purchase, $billing, $gateway) {
                $payment = $billing->attempt($request->user(), $purchase);
                if ($payment->track_id) {
                    return Inertia::location('https://gateway.zibal.ir/start/'.rawurlencode($payment->track_id));
                }
                try {
                    $url = $gateway->start($payment);
                } catch (\Throwable $exception) {
                    Payment::whereKey($payment->id)->where('status', 'pending')->update(['status' => 'failed']);
                    if ($exception instanceof ValidationException) {
                        throw $exception;
                    }

                    return back()->with('error', 'اتصال به درگاه انجام نشد؛ وضعیت را بررسی کنید.');
                }
                $billing->payable($request->user(), $purchase->fresh());

                return Inertia::location($url);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['package' => 'درخواست پرداخت در حال پردازش است؛ کمی صبر کنید.']);
        }
    }

    public function receipt(Request $request, PackagePurchase $purchase, PackageBilling $billing, ReceiptService $receipts): RedirectResponse
    {
        abort_unless($request->user()->id === $purchase->student_id, 403);
        $request->merge(['transfer_reference' => $receipts->normalizeReference((string) $request->input('transfer_reference', ''))]);
        $data = $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', 'transfer_reference' => 'required|string|min:4|max:100|regex:/^[A-Z0-9-]+$/D', 'transfer_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $billing->submitReceipt($request->user(), $purchase, $request->file('file'), $data['transfer_reference'], $data['transfer_date']);

        return back()->with('success', 'رسید ثبت شد؛ سهمیه پس از تأیید مستقل پرداخت صادر می‌شود.');
    }

    public function callback(Request $request, PaymentGateway $gateway, PackageBilling $billing): RedirectResponse
    {
        $track = $request->query('trackId');
        abort_unless(is_string($track) && strlen($track) <= 100, 400);
        $payment = Payment::where('track_id', $track)->whereNotNull('package_purchase_id')->whereNull('enrollment_id')->whereNull('installment_id')->whereNull('consultation_id')->whereNull('organization_invoice_id')->firstOrFail();
        $target = route('package-purchases.show', $payment->package_purchase_id);
        if (in_array($payment->status, ['paid', 'review_required', 'refunded'], true)) {
            return redirect($target)->with('success', 'وضعیت این پرداخت قبلاً ثبت شده است؛ نتیجه را در سفارش ببینید.');
        }
        try {
            $reference = $gateway->verify($payment);
        } catch (\Throwable) {
            return redirect($target)->with('error', 'تأیید درگاه در دسترس نیست؛ وضعیت را پیگیری کنید.');
        }
        if (! $reference) {
            return redirect($target)->with('error', 'پرداخت هنوز تأیید نشده است. در صورت کسر وجه، دوباره پرداخت نکنید و با پشتیبانی تماس بگیرید.');
        }
        $ok = $billing->capture($payment, $reference);

        return redirect($target)->with($ok ? 'success' : 'error', $ok ? 'پرداخت تأیید و سهمیه پکیج صادر شد.' : 'وجه ثبت شد و نیازمند بررسی است؛ دوباره پرداخت نکنید.');
    }
}
