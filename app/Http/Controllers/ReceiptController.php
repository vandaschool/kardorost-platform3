<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\PaymentReceipt;
use App\Services\OrganizationBilling;
use App\Services\PackageBilling;
use App\Services\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    public function store(Request $request, Installment $installment, ReceiptService $service): RedirectResponse
    {
        abort_unless($installment->enrollment->user_id === $request->user()->id, 403);
        $request->merge(['transfer_reference' => $service->normalizeReference((string) $request->input('transfer_reference', ''))]);
        $data = $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', 'transfer_reference' => 'required|string|min:4|max:100|regex:/^[A-Z0-9-]+$/D', 'transfer_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $service->submit($request->user(), $installment, $request->file('file'), $data['transfer_reference'], $data['transfer_date']);

        return back()->with('success', 'رسید ثبت شد؛ تا تأیید مدیریت، پرداخت‌شده محسوب نمی‌شود.');
    }

    public function review(Request $request, PaymentReceipt $receipt, ReceiptService $service): RedirectResponse
    {
        if ($receipt->payment->organization_invoice_id) {
            $data = $request->validate(['action' => 'required|in:approve,reject', 'expected_review_version' => 'required|integer|min:0', 'review_note' => 'required|string|min:3|max:5000', 'verified_amount' => 'nullable|integer|min:1|max:100000000000', 'statement_verified' => 'nullable']);
            $ok = app(OrganizationBilling::class)->review($request->user(), $receipt, $data);

            return back()->with('success', $ok ? 'صورتحساب سازمان تسویه شد.' : 'نتیجه رسید سازمان ثبت شد؛ وضعیت را بررسی کنید.');
        }
        $rules = ['action' => 'required|in:approve,reject', 'expected_review_version' => 'required|integer|min:0', 'review_note' => 'nullable|required_if:action,reject|string|max:5000', 'verified_amount' => 'nullable|required_if:action,approve|integer|min:1|max:1000000000', 'statement_verified' => 'nullable'];
        if ($request->input('action') === 'approve') {
            $rules['statement_verified'] = 'required|accepted';
        }
        $data = $request->validate($rules);
        $allocated = $receipt->payment->package_purchase_id ? app(PackageBilling::class)->review($request->user(), $receipt, $data) : $service->review($request->user(), $receipt, $data);

        return back()->with('success', $data['action'] === 'reject' ? 'رسید رد شد و دلیل آن برای دانشجو ثبت شد.' : ($allocated ? 'رسید تأیید و مبلغ تخصیص داده شد.' : 'رسید تأیید شد؛ انجام سفارش نیازمند بررسی است.'));
    }

    public function file(Request $request, PaymentReceipt $receipt): StreamedResponse
    {
        if ($receipt->payment->organization_invoice_id && ! $request->user()->hasRole('admin')) {
            $org = app(OrganizationController::class)->selected($request);
            abort_unless($request->user()->hasRole('org_admin') && $org && $org->id === $receipt->payment->organizationInvoice->organization_id, 403);
        } else {
            abort_unless($request->user()->hasRole('admin') || $receipt->payment->user_id === $request->user()->id, 403);
        }
        abort_unless(Storage::disk('receipts')->exists($receipt->file_path), 404);

        return Storage::disk('receipts')->download($receipt->file_path, $receipt->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
