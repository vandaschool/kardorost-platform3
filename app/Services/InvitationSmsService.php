<?php

namespace App\Services;

use App\Exceptions\SmsFailure;
use App\Jobs\SendInvitationSms;
use App\Models\AuditEvent;
use App\Models\InvitationSms;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;

class InvitationSmsService
{
    public function enqueue(OrganizationInvitation $invitation, Organization $organization): void
    {
        if (! config('platform.sms.melipayamak.invitations_enabled')) {
            return;
        }
        $name = trim(preg_replace('/[;\x00-\x1F\x7F]+/u', ' ', $organization->name));
        InvitationSms::firstOrCreate(['organization_invitation_id' => $invitation->id, 'invitation_revision' => $invitation->revision], ['organization_name' => mb_substr($name ?: 'سازمان', 0, 150)]);
    }

    private function audit(InvitationSms $sms): void
    {
        AuditEvent::create(['action' => 'invitation_sms.'.$sms->status, 'subject_type' => 'invitation_sms', 'subject_id' => $sms->id, 'metadata' => ['invitation_id' => $sms->organization_invitation_id, 'revision' => $sms->invitation_revision, 'failure_code' => $sms->failure_code]]);
    }

    public function dispatchPending(): int
    {
        $count = 0;
        $ids = InvitationSms::where('status', 'queued')->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(10)))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $sms = InvitationSms::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($sms->status !== 'queued' || $sms->dispatched_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $sms->update(['dispatched_at' => now()]);
                SendInvitationSms::dispatch($sms->id)->onConnection('database')->onQueue('sms')->afterCommit();

                return true;
            }, 3);
        }

        return $count;
    }

    public function reconcile(): int
    {
        $count = 0;
        $ids = InvitationSms::where('status', 'processing')->where('attempted_at', '<=', now()->subMinutes(10))->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $sms = InvitationSms::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($sms->status !== 'processing' || $sms->attempted_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $sms->update(['status' => 'unknown', 'failure_code' => 'worker_interrupted', 'finished_at' => now()]);
                $this->audit($sms);

                return true;
            }, 3);
        }

        return $count;
    }

    public function send(int $id, SmsSender $sender): void
    {
        $original = InvitationSms::with('invitation')->findOrFail($id);
        $reservation = DB::transaction(function () use ($original) {
            $organization = Organization::whereKey($original->invitation->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = OrganizationInvitation::whereKey($original->organization_invitation_id)->lockForUpdate()->firstOrFail();
            $sms = InvitationSms::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($sms->status !== 'queued') {
                return null;
            }
            if (! config('platform.sms.melipayamak.invitations_enabled') || $organization->status !== 'active' || $invitation->status !== 'pending' || ! $invitation->expires_at->isFuture() || $invitation->revision !== $sms->invitation_revision) {
                $sms->update(['status' => 'skipped', 'failure_code' => 'invitation_unavailable', 'finished_at' => now()]);
                $this->audit($sms);

                return null;
            }
            $sms->update(['status' => 'processing', 'attempted_at' => now()]);

            return ['phone' => $invitation->phone, 'organization' => $sms->organization_name];
        }, 3);
        if (! $reservation) {
            return;
        }
        $reference = null;
        $failureCode = null;
        try {
            $reference = $sender->sendInvitation($reservation['phone'], $reservation['organization']);
            $status = 'accepted';
        } catch (SmsFailure $failure) {
            $status = $failure->uncertain ? 'unknown' : 'failed';
            $failureCode = $failure->failureCode;
        } catch (\Throwable) {
            $status = 'unknown';
            $failureCode = 'unexpected_error';
        }
        DB::transaction(function () use ($id, $reference, $status, $failureCode) {
            $sms = InvitationSms::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($sms->status !== 'processing') {
                return;
            }
            $sms->update(['status' => $status, 'provider_reference' => $reference, 'failure_code' => $failureCode, 'finished_at' => now()]);
            $this->audit($sms);
        }, 3);
    }
}
