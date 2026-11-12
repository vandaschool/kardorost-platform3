<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\PaymentReceipt;
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
        $rules = ['action' => 'required|in:approve,reject', 'expected_review_version' => 'required|integer|min:0', 'review_note' => 'nullable|required_if:action,reject|string|max:5000', 'verified_amount' => 'nullable|required_if:action,approve|integer|min:1|max:1000000000', 'statement_verified' => 'nullable'];
        if ($request->input('action') === 'approve') {
            $rules['statement_verified'] = 'required|accepted';
        }
        $data = $request->validate($rules);
        $allocated = $service->review($request->user(), $receipt, $data);

        return back()->with('success', $data['action'] === 'reject' ? 'رسید رد شد و دلیل آن برای دانشجو ثبت شد.' : ($allocated ? 'رسید تأیید و مبلغ تخصیص داده شد.' : 'رسید تأیید شد؛ تخصیص مبلغ به دلیل وضعیت ثبت‌نام نیازمند بررسی است.'));
    }

    public function file(Request $request, PaymentReceipt $receipt): StreamedResponse
    {
        abort_unless($request->user()->hasRole('admin') || $receipt->payment->user_id === $request->user()->id, 403);
        abort_unless(Storage::disk('receipts')->exists($receipt->file_path), 404);

        return Storage::disk('receipts')->download($receipt->file_path, $receipt->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
