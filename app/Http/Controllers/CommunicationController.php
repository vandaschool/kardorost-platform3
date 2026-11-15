<?php

namespace App\Http\Controllers;

use App\Models\EventReminder;
use App\Models\InvitationSms;
use App\Models\SmsDelivery;
use App\Services\EventReminders;
use App\Services\MelipayamakClient;
use App\Services\SmsDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommunicationController extends Controller
{
    public function index(Request $request, MelipayamakClient $client): Response
    {
        $data = $request->validate(['status' => 'nullable|in:pending,polling,delivered,undelivered,unknown,unavailable', 'delivery' => 'nullable|integer|min:1']);
        $selected = isset($data['delivery']) ? SmsDelivery::findOrFail($data['delivery']) : null;
        $deliveries = SmsDelivery::query()->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(20)->withQueryString()->through(fn ($delivery) => $delivery->only('id', 'source_type', 'source_id', 'provider_mode', 'provider_reference', 'status', 'last_code', 'error_code', 'attempts', 'revision', 'last_checked_at', 'next_poll_at', 'created_at'));
        $attempts = $selected?->attemptsLog()->latest('number')->paginate(15, ['number', 'status', 'code', 'error_code', 'created_at', 'finished_at'], 'attempts_page');
        $invitationIssues = InvitationSms::with('invitation.organization:id,name')->whereIn('status', ['failed', 'unknown'])->latest('id')->limit(20)->get()->map(fn ($sms) => ['id' => $sms->id, 'status' => $sms->status, 'failure_code' => $sms->failure_code, 'finished_at' => $sms->finished_at, 'organization' => $sms->invitation->organization->name]);
        $reminderIssues = EventReminder::with('recipient:id,name')->whereIn('sms_status', ['failed', 'unknown'])->latest('id')->limit(20)->get()->map(fn ($reminder) => ['id' => $reminder->id, 'status' => $reminder->sms_status, 'failure_code' => $reminder->sms_failure_code, 'finished_at' => $reminder->sms_finished_at, 'recipient_name' => $reminder->recipient->name]);

        return Inertia::render('Communications', ['deliveries' => $deliveries, 'filters' => $data, 'attempts' => $attempts, 'selected' => $selected?->only('id', 'status', 'revision', 'provider_reference'), 'invitation_issues' => $invitationIssues, 'reminder_issues' => $reminderIssues, 'legacy_untracked_count' => InvitationSms::where('status', 'accepted')->whereNull('sms_delivery_id')->count(), 'settings' => ['mode' => in_array(config('platform.sms.melipayamak.mode'), ['rest', 'console'], true) ? config('platform.sms.melipayamak.mode') : 'unconfigured', 'delivery_enabled' => (bool) config('platform.sms.melipayamak.delivery_enabled'), 'delivery_supported' => config('platform.sms.melipayamak.mode') === 'rest', 'invitation_configured' => $client->configured(config('platform.sms.melipayamak.invitation_body_id')), 'in_app_reminders_enabled' => (bool) config('platform.reminders.enabled'), 'sms_reminders_enabled' => (bool) config('platform.sms.melipayamak.reminders_enabled'), 'reminder_configured' => $client->configured(config('platform.sms.melipayamak.reminder_body_id')), 'lead_minutes' => max(5, min(1440, (int) config('platform.reminders.lead_minutes', 60)))]]);
    }

    public function retry(Request $request, SmsDelivery $delivery, SmsDeliveryService $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $service->retry($request->user(), $delivery, $data['revision']);
        $service->dispatchPending();

        return back()->with('success', 'درخواست استعلام تازه ثبت شد؛ پیامک دوباره ارسال نمی‌شود.');
    }

    public function open(Request $request, EventReminder $reminder, EventReminders $events): RedirectResponse
    {
        return redirect()->to($events->destination($request->user(), $reminder));
    }
}
