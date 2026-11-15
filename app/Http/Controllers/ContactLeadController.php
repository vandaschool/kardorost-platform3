<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\ContactLead;
use App\Services\ContactLeads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactLeadController extends Controller
{
    public function store(Request $request, ContactLeads $service): RedirectResponse
    {
        if (! $request->filled('website')) {
            $service->receive($request->only('request_key', 'name', 'phone', 'audience', 'organization_name', 'subject', 'message', 'consent'));
        }

        return redirect()->route('public.contact')->with('contact_success', 'درخواست شما دریافت شد؛ مدیریت برای پیگیری آن را بررسی می‌کند.');
    }

    private function data(ContactLead $lead): array
    {
        return $lead->only('id', 'name', 'phone', 'audience', 'organization_name', 'subject', 'message', 'status', 'revision', 'consented_at', 'consent_version', 'created_at', 'updated_at');
    }

    public function admin(Request $request, ContactLeads $service, ?ContactLead $lead = null): Response
    {
        $filters = $request->validate(['status' => 'nullable|in:all,new,working,qualified,closed', 'audience' => 'nullable|in:all,individual,company,university', 'phone' => 'nullable|string|max:30']);
        $status = $filters['status'] ?? 'new';
        $audience = $filters['audience'] ?? 'all';
        $phone = trim($filters['phone'] ?? '');
        $fingerprint = $phone !== '' ? $service->fingerprint($phone) : null;
        $rows = ContactLead::when($status !== 'all', fn ($q) => $q->where('status', $status))->when($audience !== 'all', fn ($q) => $q->where('audience', $audience))->when($fingerprint, fn ($q) => $q->where('phone_fingerprint', $fingerprint))->latest('id')->paginate(15)->withQueryString()->through(fn ($row) => $row->only('id', 'name', 'phone', 'audience', 'organization_name', 'subject', 'status', 'revision', 'created_at'));
        $events = $lead?->events()->with('actor:id,name')->latest('id')->paginate(20, ['actor_id', 'revision', 'from_status', 'to_status', 'note', 'created_at'], 'events_page')->withQueryString()->through(fn ($event) => [...$event->only('revision', 'from_status', 'to_status', 'note', 'created_at'), 'actor_name' => $event->actor?->name ?? 'فرم عمومی']);
        if ($lead) {
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'contact_lead.viewed', 'subject_type' => 'contact_lead', 'subject_id' => $lead->id]);
        }

        return Inertia::render('ContactLeads', ['leads' => $rows, 'selected' => $lead ? $this->data($lead) : null, 'events' => $events, 'filters' => ['status' => $status, 'audience' => $audience, 'phone' => $phone]]);
    }

    public function followUp(Request $request, ContactLead $lead, ContactLeads $service): RedirectResponse
    {
        $service->followUp($request->user(), $lead, $request->only('revision', 'status', 'note'));

        return back()->with('success', 'یادداشت و وضعیت پیگیری ثبت شد.');
    }
}
