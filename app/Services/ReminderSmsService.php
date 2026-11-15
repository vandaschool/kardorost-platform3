<?php

namespace App\Services;

use App\Exceptions\SmsFailure;
use App\Jobs\SendReminderSms;
use App\Models\AuditEvent;
use App\Models\EventReminder;
use Illuminate\Support\Facades\DB;

class ReminderSmsService
{
    private function audit(EventReminder $reminder): void
    {
        AuditEvent::create(['action' => 'reminder_sms.'.$reminder->sms_status, 'subject_type' => 'event_reminder', 'subject_id' => $reminder->id, 'metadata' => ['failure_code' => $reminder->sms_failure_code]]);
    }

    public function dispatchPending(): int
    {
        $count = 0;
        $ids = EventReminder::where('sms_status', 'queued')->where(fn ($q) => $q->whereNull('sms_dispatched_at')->orWhere('sms_dispatched_at', '<=', now()->subMinutes(10)))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $reminder = EventReminder::lockForUpdate()->findOrFail($id);
                if ($reminder->sms_status !== 'queued' || $reminder->sms_dispatched_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $reminder->update(['sms_dispatched_at' => now()]);
                SendReminderSms::dispatch($id)->onConnection('database')->onQueue('sms')->afterCommit();

                return true;
            }, 3);
        }

        return $count;
    }

    public function send(int $id, MelipayamakClient $client, EventReminders $events): void
    {
        $original = EventReminder::findOrFail($id);
        $reservation = DB::transaction(function () use ($original, $client, $events) {
            $context = $events->context($original->source_type, $original->source_id, $original->user_id, $original->occurs_at);
            $reminder = EventReminder::lockForUpdate()->findOrFail($original->id);
            if ($reminder->sms_status !== 'queued') {
                return null;
            }
            if (! $context || ! config('platform.reminders.enabled') || ! config('platform.sms.melipayamak.reminders_enabled') || ! $context['user']->sms_reminders_enabled) {
                $reminder->update(['sms_status' => 'skipped', 'sms_failure_code' => 'event_or_recipient_unavailable', 'sms_finished_at' => now()]);
                $this->audit($reminder);

                return null;
            }
            $account = $client->account();
            $reminder->update(['sms_status' => 'processing', 'sms_attempted_at' => now(), 'sender_mode' => $account['mode'] ?? null, 'sender_account' => $account['fingerprint'] ?? null]);

            $time = $context['occurs_at']->copy()->timezone('Asia/Tehran');
            $day = $time->toDateString() === now()->timezone('Asia/Tehran')->toDateString() ? 'امروز' : 'فردا';

            return ['phone' => $context['user']->phone, 'args' => [$context['kind'], $day.' '.$time->format('H:i')]];
        }, 3);
        if (! $reservation) {
            return;
        }
        $reference = null;
        $failureCode = null;
        try {
            $reference = $client->send($reservation['phone'], config('platform.sms.melipayamak.reminder_body_id'), $reservation['args']);
            $status = 'accepted';
        } catch (SmsFailure $failure) {
            $status = $failure->uncertain ? 'unknown' : 'failed';
            $failureCode = $failure->failureCode;
        } catch (\Throwable) {
            $status = 'unknown';
            $failureCode = 'unexpected_error';
        }
        DB::transaction(function () use ($id, $reference, $status, $failureCode) {
            $reminder = EventReminder::lockForUpdate()->findOrFail($id);
            if ($reminder->sms_status !== 'processing') {
                return;
            }
            $deliveryId = null;
            if ($status === 'accepted' && $reminder->sender_mode && $reminder->sender_account) {
                try {
                    $deliveryId = app(SmsDeliveryService::class)->register('reminder', $id, $reference, ['mode' => $reminder->sender_mode, 'fingerprint' => $reminder->sender_account])->id;
                } catch (SmsFailure $failure) {
                    $status = 'unknown';
                    $failureCode = $failure->failureCode;
                }
            }
            $reminder->update(['sms_status' => $status, 'sms_reference' => $reference, 'sms_failure_code' => $failureCode, 'sms_delivery_id' => $deliveryId, 'sms_finished_at' => now()]);
            $this->audit($reminder);
        }, 3);
    }

    public function reconcile(): int
    {
        $count = 0;
        $ids = EventReminder::where('sms_status', 'processing')->where('sms_attempted_at', '<=', now()->subMinutes(10))->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $reminder = EventReminder::lockForUpdate()->findOrFail($id);
                if ($reminder->sms_status !== 'processing' || ! $reminder->sms_attempted_at || $reminder->sms_attempted_at->gt(now()->subMinutes(10))) {
                    return false;
                }
                $reminder->update(['sms_status' => 'unknown', 'sms_failure_code' => 'worker_interrupted', 'sms_finished_at' => now()]);
                $this->audit($reminder);

                return true;
            }, 3);
        }

        return $count;
    }
}
