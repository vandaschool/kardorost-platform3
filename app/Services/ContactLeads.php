<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ContactLead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContactLeads
{
    public function fingerprint(string $phone): string
    {
        return hash_hmac('sha256', Phone::normalize($phone), config('app.key'));
    }

    private function textEncoding(array $input, array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && ! mb_check_encoding($input[$key], 'UTF-8')) {
                throw ValidationException::withMessages([$key => 'متن واردشده معتبر نیست.']);
            }
        }
    }

    public function receive(array $input): ContactLead
    {
        $this->textEncoding($input, ['name', 'phone', 'organization_name', 'message']);
        foreach (['name', 'organization_name', 'message'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'name' => 'required|string|min:3|max:160', 'phone' => 'required|string|max:30', 'audience' => 'required|in:individual,company,university', 'organization_name' => 'required_unless:audience,individual|nullable|string|max:200', 'subject' => 'required|in:training,consultation,platform', 'message' => 'required|string|min:20|max:4000', 'consent' => 'required|accepted'])->validate();
        $phone = Phone::normalize($data['phone']);

        return DB::transaction(function () use ($data, $phone) {
            $lead = ContactLead::firstOrCreate(['request_key' => $data['request_key']], ['name' => $data['name'], 'phone' => $phone, 'phone_fingerprint' => $this->fingerprint($phone), 'audience' => $data['audience'], 'organization_name' => $data['organization_name'] ?? null, 'subject' => $data['subject'], 'message' => $data['message'], 'consented_at' => now(), 'consent_version' => 'contact_follow_up_v1']);
            if ($lead->wasRecentlyCreated) {
                $lead->events()->create(['revision' => 1, 'to_status' => 'new', 'note' => 'فرم عمومی با رضایت پیگیری دریافت شد.']);
                AuditEvent::create(['action' => 'contact_lead.received', 'subject_type' => 'contact_lead', 'subject_id' => $lead->id, 'metadata' => ['audience' => $lead->audience, 'subject' => $lead->subject]]);
            }

            return $lead;
        }, 3);
    }

    public function followUp(User $actor, ContactLead $lead, array $input): void
    {
        $this->textEncoding($input, ['note']);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'status' => 'required|in:new,working,qualified,closed', 'note' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $lead, $data) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
            $lead = ContactLead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($lead->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['lead' => 'پیگیری تغییر کرده است؛ صفحه را تازه کنید.']);
            }
            $previous = $lead->status;
            $lead->update(['status' => $data['status'], 'revision' => $lead->revision + 1]);
            $lead->events()->create(['actor_id' => $actor->id, 'revision' => $lead->revision, 'from_status' => $previous, 'to_status' => $lead->status, 'note' => $data['note']]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'contact_lead.follow_up', 'subject_type' => 'contact_lead', 'subject_id' => $lead->id, 'metadata' => ['revision' => $lead->revision, 'from' => $previous, 'to' => $lead->status]]);
        }, 3);
    }
}
