<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Payment;
use App\Models\User;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\MediaUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationController extends Controller
{
    public function index(Request $r): Response
    {
        $u = $r->user();
        $mode = $r->query('view', $u->hasRole('student') ? 'student' : ($u->hasRole('coach') ? 'coach' : 'admin'));
        abort_unless(in_array($mode, ['student', 'coach', 'admin'], true) && $u->hasRole($mode), 403);
        Consultation::where('status', 'pending_payment')->where('expires_at', '<=', now())->when($mode === 'student', fn ($q) => $q->where('student_id', $u->id))->when($mode === 'coach', fn ($q) => $q->whereHas('slot', fn ($s) => $s->where('coach_id', $u->id)))->select('consultation_slot_id')->distinct()->get()->each(fn ($c) => app(ConsultationBilling::class)->expire($c->consultation_slot_id));
        $slots = ConsultationSlot::with('coach:id,name,is_active')->when($mode === 'student', fn ($q) => $q->where('published', true)->where(fn ($q) => $q->where('booked', false)->orWhere(fn ($q) => $q->whereHas('consultations', fn ($c) => $c->where('status', 'pending_payment')->where('expires_at', '<=', now()))->whereDoesntHave('consultations', fn ($c) => $c->where('status', 'confirmed')->orWhere(fn ($c) => $c->where('status', 'pending_payment')->where('expires_at', '>', now())))))->where('starts_at', '>', now())->whereHas('coach', fn ($c) => $c->where('is_active', true)->whereHas('roles', fn ($role) => $role->where('key', 'coach'))))->when($mode === 'coach', fn ($q) => $q->where('coach_id', $u->id))->orderBy('starts_at')->paginate(12, ['*'], 'slots_page')->withQueryString();
        $slots->through(fn ($s) => [...$s->only('id', 'coach_id', 'starts_at', 'ends_at', 'provider', 'published', 'booked', 'price'), 'coach_name' => $s->coach->name, 'meeting_url' => $mode === 'student' ? null : $s->meeting_url]);
        $items = Consultation::with(['slot.coach:id,name', 'student:id,name'])->when($mode === 'student', fn ($q) => $q->where('student_id', $u->id))->when($mode === 'coach', fn ($q) => $q->whereHas('slot', fn ($s) => $s->where('coach_id', $u->id)))->orderByDesc('id')->paginate(12, ['*'], 'bookings_page')->withQueryString();
        $items->through(fn ($c) => [...$c->only('id', 'status', 'version', 'topic', 'shared_summary', 'cancellation_reason', 'amount', 'expires_at', 'refund_status'), 'student_name' => $c->student->name, 'coach_name' => $c->slot->coach->name, 'starts_at' => $c->slot->starts_at, 'ends_at' => $c->slot->ends_at, 'can_join' => $c->status === 'confirmed' && $c->slot->meeting_url && $c->slot->starts_at->subMinutes(15)->lte(now()) && $c->slot->ends_at->gt(now()), 'can_pay' => $mode === 'student' && $c->status === 'pending_payment' && $c->expires_at?->gt(now()) && ! Payment::where('consultation_id', $c->id)->where('status', 'review_required')->exists(), 'can_cancel' => in_array($c->status, ['confirmed', 'pending_payment'], true) && $c->slot->starts_at->gt(now()), 'can_finish' => $mode !== 'student' && $c->status === 'confirmed' && $c->slot->ends_at->lte(now())]);
        $requests = $mode === 'coach' ? null : ConsultationRequest::with('student:id,name')->when($mode === 'student', fn ($q) => $q->where('student_id', $u->id))->orderByDesc('id')->paginate(12, ['*'], 'requests_page')->withQueryString();
        if ($requests) {
            $requests->through(fn ($c) => [...$c->only('id', 'topic', 'availability', 'status', 'version', 'resolution', 'consultation_id', 'created_at'), 'student_name' => $c->student->name]);
        }

        return Inertia::render('Consultations', ['role' => $mode, 'slots' => $slots, 'bookings' => $items, 'requests' => $requests, 'gateway_enabled' => (bool) config('platform.zibal.enabled') && (bool) config('platform.zibal.merchant'), 'coaches' => $mode === 'admin' ? User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'coach'))->get(['id', 'name']) : []]);
    }

    public function slot(Request $r, ConsultationService $service, MediaUrl $media): RedirectResponse
    {
        $r->merge(['price' => $r->input('price', 0)]);
        $d = $r->validate(['price' => 'required|integer|min:0|max:100000000', 'coach_id' => 'required|integer|exists:users,id', 'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'minutes' => 'required|integer|min:15|max:180', 'provider' => 'required|in:meet,skyroom,zoom', 'meeting_url' => 'nullable|string|max:2000', 'free_confirmation' => (int) $r->input('price') === 0 ? 'required|accepted' : 'exclude', 'price_confirmation' => (int) $r->input('price') > 0 ? 'required|accepted' : 'exclude']);
        $url = empty($d['meeting_url']) ? null : $media->meeting($d['provider'], $d['meeting_url']);
        $service->createSlot($r->user(), User::findOrFail($d['coach_id']), CarbonImmutable::createFromFormat('!Y-m-d H:i', $d['date'].' '.$d['time'], 'Asia/Tehran')->utc(), $d['minutes'], $d['provider'], $url, (int) $d['price'] * 10);

        return back()->with('success', 'زمان و تعرفه مشاوره منتشر شد.');
    }

    public function publish(Request $r, ConsultationSlot $slot, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['published' => 'required|boolean']);
        $service->publish($r->user(), $slot, $d['published']);

        return back();
    }

    public function book(Request $r, ConsultationSlot $slot, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['topic' => 'required|string|min:3|max:3000']);
        $item = $service->book($r->user(), $slot, $d['topic']);

        return back()->with('success', $item->status === 'confirmed' ? 'جلسه رزرو شد.' : 'زمان برای حداکثر ۱۵ دقیقه نگه داشته شد؛ پرداخت را از کارت جلسه تکمیل کنید.');
    }

    public function request(Request $r, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['topic' => 'required|string|min:3|max:3000', 'availability' => 'required|string|min:3|max:1500']);
        $service->request($r->user(), $d['topic'], $d['availability']);

        return back()->with('success', 'درخواست هماهنگی ثبت شد؛ نتیجه در همین صفحه اعلام می‌شود.');
    }

    public function schedule(Request $r, ConsultationRequest $consultationRequest, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['slot_id' => 'required|integer|exists:consultation_slots,id', 'version' => 'required|integer|min:1']);
        $service->book($consultationRequest->student, ConsultationSlot::findOrFail($d['slot_id']), $consultationRequest->topic, $consultationRequest, $r->user(), $d['version']);

        return back()->with('success', 'جلسه هماهنگ و رزرو شد.');
    }

    public function resolve(Request $r, ConsultationRequest $consultationRequest, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1500']);
        $service->resolve($r->user(), $consultationRequest, $d['version'], $d['reason']);

        return back();
    }

    public function change(Request $r, Consultation $consultation, ConsultationService $service): RedirectResponse
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:cancel,completed,no_show', 'reason' => 'required_if:action,cancel|nullable|string|min:3|max:1500', 'shared_summary' => 'nullable|string|max:5000']);
        $service->change($r->user(), $consultation, $d['version'], $d['action'], $d['reason'] ?? null, $d['shared_summary'] ?? null);

        return back()->with('success', 'وضعیت جلسه ثبت شد.');
    }

    public function meeting(Request $r, ConsultationSlot $slot, ConsultationService $service): RedirectResponse
    {
        $service->manager($r->user(), $slot);
        $d = $r->validate(['meeting_url' => 'required|string|max:2000']);
        $service->meeting($r->user(), $slot, $d['meeting_url']);

        return back();
    }

    public function join(Request $r, Consultation $consultation): RedirectResponse
    {
        $u = $r->user();
        $s = $consultation->slot;
        abort_unless(($u->hasRole('student') && $u->id === $consultation->student_id) || ($u->hasRole('coach') && $u->id === $s->coach_id) || $u->hasRole('admin'), 403);
        abort_unless($consultation->status === 'confirmed' && $s->meeting_url && $s->starts_at->subMinutes(15)->lte(now()) && $s->ends_at->gt(now()), 403);
        abort_unless($s->coach->is_active && $s->coach->hasRole('coach'), 403);

        return redirect()->away(app(MediaUrl::class)->meeting($s->provider, $s->meeting_url));
    }
}
