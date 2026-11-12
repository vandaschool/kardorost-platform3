<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationLicense;
use App\Models\Payment;
use App\Services\OrganizationBilling;
use App\Services\PaymentGateway;
use App\Services\ReceiptService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OrganizationInvoiceController extends Controller
{
    private function selected(Request $request): Organization
    {
        $organization = app(OrganizationController::class)->selected($request);
        abort_unless($organization, 403);

        return $organization;
    }

    private function access(Request $request, OrganizationInvoice $invoice): void
    {
        $organization = $this->selected($request);
        abort_unless($organization->id === $invoice->organization_id && $invoice->status !== 'draft', 403);
    }

    private function data(OrganizationInvoice $invoice): array
    {
        return [...$invoice->only('id', 'organization_id', 'organization_license_id', 'title', 'organization_name', 'contract_reference', 'lines', 'amount', 'number', 'status', 'revision', 'destination', 'holder', 'void_reason', 'issued_at', 'paid_at', 'voided_at'), 'due_date' => $invoice->due_date->toDateString(), 'overdue' => $invoice->status === 'issued' && $invoice->due_date->toDateString() < now()->toDateString()];
    }

    public function admin(Request $request): Response
    {
        return Inertia::render('OrganizationInvoices', ['admin' => true, 'organization' => null, 'invoices' => OrganizationInvoice::latest()->paginate(15)->through(fn ($invoice) => $this->data($invoice)), 'organizations' => Organization::where('status', 'active')->get(['id', 'name'])]);
    }

    public function index(Request $request): Response
    {
        $organization = $this->selected($request);

        return Inertia::render('OrganizationInvoices', ['admin' => false, 'organization' => $organization->only('id', 'name'), 'organizations' => [], 'invoices' => OrganizationInvoice::where('organization_id', $organization->id)->where('status', '!=', 'draft')->latest()->paginate(15)->through(fn ($invoice) => $this->data($invoice))]);
    }

    public function create(Request $request, OrganizationBilling $billing): RedirectResponse
    {
        $data = $request->validate(['organization_id' => 'required|integer|exists:organizations,id', 'definition' => 'required|array']);
        $invoice = $billing->draft($request->user(), Organization::findOrFail($data['organization_id']), $data['definition']);

        return redirect()->route('admin.organization-invoices.show', $invoice);
    }

    private function page(Request $request, OrganizationInvoice $invoice, bool $admin): Response
    {
        if (! $admin) {
            $this->access($request, $invoice);
        }AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'organization_invoice.viewed', 'subject_type' => 'organization_invoice', 'subject_id' => $invoice->id]);
        $payments = $invoice->payments()->with('receipt')->latest()->paginate(15)->through(fn ($payment) => [...$payment->only('id', 'amount', 'status', 'method', 'reference', 'captured_at', 'created_at', 'payer_name_snapshot'), 'receipt' => $payment->receipt ? app(FinanceController::class)->receiptData($payment->receipt) : null]);
        $blocked = $invoice->payments()->whereIn('status', ['pending_review', 'review_required'])->exists();

        return Inertia::render('OrganizationInvoice', ['admin' => $admin, 'invoice' => $this->data($invoice), 'payments' => $payments, 'can_pay' => ! $admin && $invoice->status === 'issued' && ! $blocked, 'blocked' => $blocked, 'gateway_enabled' => (bool) config('platform.zibal.enabled') && (bool) config('platform.zibal.merchant'), 'licenses' => $admin ? OrganizationLicense::where('organization_id', $invoice->organization_id)->with('cohort.course')->get()->map(fn ($l) => ['id' => $l->id, 'title' => $l->cohort->course->title.' · '.$l->cohort->title]) : []]);
    }

    public function show(Request $request, OrganizationInvoice $invoice): Response
    {
        return $this->page($request, $invoice, false);
    }

    public function adminShow(Request $request, OrganizationInvoice $invoice): Response
    {
        return $this->page($request, $invoice, true);
    }

    public function update(Request $request, OrganizationInvoice $invoice, OrganizationBilling $billing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'definition' => 'required|array']);
        $billing->draft($request->user(), $invoice->organization, $data['definition'], $invoice, $data['revision']);

        return back()->with('success', 'پیش‌نویس به‌روز شد.');
    }

    public function issue(Request $request, OrganizationInvoice $invoice, OrganizationBilling $billing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reviewed' => 'required|accepted']);
        $billing->issue($request->user(), $invoice, $data['revision']);

        return back()->with('success', 'صورتحساب داخلی صادر شد. سهمیه آموزشی به صورت خودکار تغییر نکرد.');
    }

    public function void(Request $request, OrganizationInvoice $invoice, OrganizationBilling $billing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);
        $billing->void($request->user(), $invoice, $data['revision'], $data['reason']);

        return back()->with('success', 'ابطال صورتحساب ثبت شد.');
    }

    public function receipt(Request $request, OrganizationInvoice $invoice, OrganizationBilling $billing, ReceiptService $references): RedirectResponse
    {
        $this->access($request, $invoice);
        $request->merge(['transfer_reference' => $references->normalizeReference((string) $request->input('transfer_reference', ''))]);
        $data = $request->validate(['transfer_reference' => 'required|string|min:4|max:100', 'transfer_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $billing->receipt($request->user(), $invoice, $request->file('file'), $data['transfer_reference'], $data['transfer_date']);

        return back()->with('success', 'رسید ثبت شد؛ تسویه پس از بررسی ادمین انجام می‌شود.');
    }

    public function pay(Request $request, OrganizationInvoice $invoice, OrganizationBilling $billing, PaymentGateway $gateway): HttpResponse
    {
        $this->access($request, $invoice);
        try {
            return Cache::lock('organization-invoice:'.$invoice->id, 30)->block(3, function () use ($request, $invoice, $billing, $gateway) {
                $payment = $billing->attempt($request->user(), $invoice);
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

                    return back()->with('error', 'اتصال به درگاه انجام نشد.');
                }
                $invoice->refresh();
                if ($invoice->status !== 'issued' || $payment->attempt_expires_at->isPast()) {
                    return back()->with('error', 'درخواست پرداخت دیگر معتبر نیست؛ وضعیت صورتحساب را بررسی کنید.');
                }

                return Inertia::location($url);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['invoice' => 'درخواست دیگری در جریان است؛ کمی صبر کنید.']);
        }
    }

    public function callback(Request $request, OrganizationBilling $billing, PaymentGateway $gateway): RedirectResponse
    {
        $track = $request->query('trackId');
        abort_unless(is_string($track) && strlen($track) <= 100, 400);
        $payment = Payment::where('track_id', $track)->whereNotNull('organization_invoice_id')->firstOrFail();
        if ($payment->status === 'paid') {
            return redirect()->route('organization.invoices.show', $payment->organization_invoice_id)->with('success', 'پرداخت قبلاً تأیید شده است.');
        }
        if (in_array($payment->status, ['review_required', 'refunded'], true)) {
            return redirect()->route('organization.invoices.show', $payment->organization_invoice_id)->with('error', 'وضعیت وجه قبلی را با مدیریت پیگیری کنید؛ دوباره پرداخت نکنید.');
        }
        try {
            $reference = $gateway->verify($payment);
        } catch (\Throwable) {
            return redirect()->route('organization.invoices.show', $payment->organization_invoice_id)->with('error', 'تأیید درگاه در دسترس نیست؛ وضعیت را پیگیری کنید.');
        }
        if (! $reference) {
            return redirect()->route('organization.invoices.show', $payment->organization_invoice_id)->with('error', 'پرداخت هنوز تأیید نشده است. در صورت کسر وجه با مدیریت پیگیری کنید.');
        }
        $settled = $billing->capture($payment, $reference);

        return redirect()->route('organization.invoices.show', $payment->organization_invoice_id)->with($settled ? 'success' : 'error', $settled ? 'صورتحساب تسویه شد.' : 'وجه نیازمند بررسی است؛ دوباره پرداخت نکنید.');
    }
}
