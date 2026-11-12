<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefundController extends Controller
{
    public function index(Request $r, RefundService $service): Response
    {
        $d = $r->validate(['view' => 'nullable|in:admin', 'payment' => 'nullable|integer|min:1', 'status' => 'nullable|in:all,requested,approved,rejected,completed']);
        $admin = ($d['view'] ?? null) === 'admin';
        if ($admin) {
            abort_unless($r->user()->hasRole('admin'), 403);
        }
        $payments = Payment::whereNull('organization_invoice_id')->with(['refund', 'enrollment.course', 'consultation.slot.coach', 'user:id,name'])->when(! $admin, fn ($q) => $q->where('user_id', $r->user()->id));
        $selected = isset($d['payment']) ? (clone $payments)->findOrFail($d['payment']) : null;
        $status = $d['status'] ?? ($admin ? 'requested' : 'all');
        $rows = Refund::with(['events', 'payment.user:id,name', 'payment.enrollment.course', 'payment.consultation.slot.coach'])->when(! $admin, fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('user_id', $r->user()->id)))->when($status !== 'all', fn ($q) => $q->where('status', $status))->when($selected, fn ($q) => $q->where('payment_id', $selected->id))->latest()->paginate(12)->withQueryString()->through(fn ($f) => $this->data($f, $admin));
        $candidates = (clone $payments)->whereNotNull('captured_at')->whereIn('status', ['paid', 'review_required'])->where(fn ($q) => $q->whereDoesntHave('refund')->orWhereHas('refund', fn ($f) => $f->where('status', 'rejected')))->when($selected, fn ($q) => $q->whereKey($selected->id))->latest()->paginate(12, ['*'], 'payments_page')->withQueryString()->through(fn ($p) => ['id' => $p->id, 'title' => $this->title($p), 'amount' => $p->amount, 'eligible' => $service->eligible($p), 'version' => $p->refund?->version ?? 0]);

        return Inertia::render('Refunds', ['admin' => $admin, 'status' => $status, 'rows' => $rows, 'candidates' => $admin ? null : $candidates, 'selected_payment' => $selected?->id]);
    }

    private function title(Payment $p): string
    {
        return $p->consultation_id ? 'مشاوره با '.$p->consultation->slot->coach->name : $p->enrollment->course->title;
    }

    public function data(Refund $f, bool $admin): array
    {
        return [...$f->only('id', 'payment_id', 'amount', 'status', 'version', 'reason', 'review_note', 'reviewed_at', 'completed_at', 'transfer_reference', 'created_at'), 'transfer_date' => $f->transfer_date?->toDateString(), 'destination_mask' => 'IR ···· '.substr($f->destination, -4), 'destination' => $admin && $f->status !== 'completed' ? $f->destination : null, 'student' => $admin ? $f->payment->user->name : null, 'title' => $this->title($f->payment), 'file_url' => $f->file_path ? route('refunds.file', $f) : null, 'events' => $f->events->sortBy('version')->values()->map(fn ($e) => $e->only('status', 'note', 'version', 'created_at'))];
    }

    public function store(Request $r, Payment $payment, RefundService $service): RedirectResponse
    {
        $d = $r->validate(['destination' => 'required|string|max:100', 'reason' => 'required|string|min:3|max:2000', 'version' => 'required|integer|min:0', 'owner_confirmed' => 'required|accepted']);
        $service->request($r->user(), $payment, $d['destination'], $d['reason'], $d['version']);

        return back()->with('success', 'درخواست بررسی بازپرداخت ثبت شد؛ هنوز وجهی برگشت داده نشده است.');
    }

    public function review(Request $r, Refund $refund, RefundService $service): RedirectResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:approve,reject', 'note' => 'required|string|min:3|max:2000']);
        $service->review($r->user(), $refund, $d['version'], $d['action'], $d['note']);

        return back()->with('success', 'نتیجه بررسی ثبت شد. تأیید درخواست به معنی انجام واریز نیست.');
    }

    public function complete(Request $r, Refund $refund, RefundService $service): RedirectResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'amount' => 'required|integer|min:1|max:1000000000', 'destination' => 'required|string|max:100', 'transfer_reference' => 'required|string|max:150', 'transfer_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'statement_verified' => 'required|accepted', 'beneficiary_verified' => 'required|accepted', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $service->complete($r->user(), $refund, $d, $r->file('file'));

        return back()->with('success', 'مدرک برگشت کامل وجه ثبت شد.');
    }

    public function file(Request $r, Refund $refund): StreamedResponse
    {
        abort_unless($r->user()->hasRole('admin') || $refund->payment->user_id === $r->user()->id, 403);
        abort_unless($refund->status === 'completed' && $refund->file_path && Storage::disk('refunds')->exists($refund->file_path), 404);

        return Storage::disk('refunds')->download($refund->file_path, $refund->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
