<?php

namespace App\Services;

use App\Exceptions\SmsFailure;
use App\Jobs\PollSmsDelivery;
use App\Models\AuditEvent;
use App\Models\SmsDelivery;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SmsDeliveryService
{
    /** @param array{mode:string,fingerprint:string} $account */
    public function register(string $type, int $sourceId, string $reference, array $account): SmsDelivery
    {
        if (! in_array($type, ['invitation', 'reminder'], true) || ! preg_match('/^[1-9][0-9]{3,31}$/D', $reference)) {
            throw new SmsFailure('invalid_delivery_source', true);
        }
        try {
            $delivery = SmsDelivery::firstOrCreate(['source_type' => $type, 'source_id' => $sourceId], ['provider_mode' => $account['mode'], 'provider_account' => $account['fingerprint'], 'provider_reference' => $reference, 'next_poll_at' => now()->addMinutes(5), 'expires_at' => now()->addHours(72)]);
        } catch (UniqueConstraintViolationException) {
            throw new SmsFailure('provider_reference_conflict', true);
        }
        if ($delivery->provider_mode !== $account['mode'] || ! hash_equals($delivery->provider_account, $account['fingerprint']) || $delivery->provider_reference !== $reference) {
            throw new SmsFailure('provider_reference_conflict', true);
        }

        return $delivery;
    }

    public function dispatchPending(): int
    {
        if (! config('platform.sms.melipayamak.delivery_enabled')) {
            return 0;
        }
        $count = 0;
        $ids = SmsDelivery::where('status', 'pending')->where('next_poll_at', '<=', now())->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(10)))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $delivery = SmsDelivery::lockForUpdate()->findOrFail($id);
                if ($delivery->status !== 'pending' || ! $delivery->next_poll_at || $delivery->next_poll_at->isFuture() || $delivery->dispatched_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $delivery->update(['dispatched_at' => now()]);
                PollSmsDelivery::dispatch($delivery->id)->onConnection('database')->onQueue('sms')->afterCommit();

                return true;
            }, 3);
        }

        return $count;
    }

    public function begin(int $id): ?SmsDeliveryAttempt
    {
        if (! config('platform.sms.melipayamak.delivery_enabled')) {
            return null;
        }

        return DB::transaction(function () use ($id) {
            $delivery = SmsDelivery::lockForUpdate()->findOrFail($id);
            if ($delivery->status !== 'pending' || ! $delivery->next_poll_at || $delivery->next_poll_at->isFuture()) {
                return null;
            }
            if ($delivery->expires_at->lte(now())) {
                $delivery->update(['status' => 'unknown', 'error_code' => 'tracking_window_expired', 'next_poll_at' => null, 'revision' => $delivery->revision + 1]);

                return null;
            }
            $token = (string) Str::uuid();
            $number = $delivery->attempts + 1;
            $delivery->update(['status' => 'polling', 'lease_token' => $token, 'poll_started_at' => now(), 'next_poll_at' => null, 'attempts' => $number, 'revision' => $delivery->revision + 1]);

            return $delivery->attemptsLog()->create(['number' => $number, 'lease_token' => $token]);
        }, 3);
    }

    /** @param array{state:string,code:?int,error:?string} $result */
    public function finish(SmsDeliveryAttempt $attempt, array $result): void
    {
        DB::transaction(function () use ($attempt, $result) {
            $delivery = SmsDelivery::lockForUpdate()->findOrFail($attempt->sms_delivery_id);
            $attempt = SmsDeliveryAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($delivery->status !== 'polling' || $delivery->lease_token !== $attempt->lease_token) {
                if ($attempt->status === 'processing') {
                    $attempt->update(['status' => 'superseded', 'finished_at' => now()]);
                }

                return;
            }
            $state = $result['state'];
            $error = $result['error'];
            if ($state === 'pending' && $delivery->expires_at->lte(now())) {
                $state = 'unknown';
                $error = 'tracking_window_expired';
            }
            $minutes = match (true) {
                $delivery->attempts < 3 => 5,
                $delivery->attempts < 6 => 15,
                $delivery->attempts < 10 => 60,
                default => 360,
            };
            $delivery->update(['status' => $state, 'last_code' => $result['code'], 'error_code' => $error, 'last_checked_at' => now(), 'next_poll_at' => $state === 'pending' ? now()->addMinutes($minutes)->min($delivery->expires_at) : null, 'lease_token' => null, 'poll_started_at' => null, 'dispatched_at' => null, 'revision' => $delivery->revision + 1]);
            $attempt->update(['status' => $state, 'code' => $result['code'], 'error_code' => $error, 'finished_at' => now()]);
            AuditEvent::create(['action' => 'sms_delivery.checked', 'subject_type' => 'sms_delivery', 'subject_id' => $delivery->id, 'metadata' => ['attempt' => $attempt->number, 'status' => $state, 'code' => $result['code'], 'error' => $error]]);
        }, 3);
    }

    public function poll(int $id, MelipayamakClient $client): void
    {
        $attempt = $this->begin($id);
        if (! $attempt) {
            return;
        }
        $delivery = SmsDelivery::findOrFail($id);
        $result = $client->delivery($delivery->provider_mode, $delivery->provider_account, $delivery->provider_reference);
        $this->finish($attempt, $result);
    }

    public function reconcile(): int
    {
        $count = 0;
        $ids = SmsDelivery::where('status', 'polling')->where('poll_started_at', '<=', now()->subMinutes(10))->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $delivery = SmsDelivery::lockForUpdate()->findOrFail($id);
                if ($delivery->status !== 'polling' || ! $delivery->poll_started_at || $delivery->poll_started_at->gt(now()->subMinutes(10))) {
                    return false;
                }
                $delivery->attemptsLog()->where('lease_token', $delivery->lease_token)->where('status', 'processing')->update(['status' => 'interrupted', 'error_code' => 'query_interrupted', 'finished_at' => now()]);
                $delivery->update(['status' => 'pending', 'error_code' => 'query_interrupted', 'lease_token' => null, 'poll_started_at' => null, 'dispatched_at' => null, 'next_poll_at' => now(), 'revision' => $delivery->revision + 1]);

                return true;
            }, 3);
        }

        return $count;
    }

    public function retry(User $actor, SmsDelivery $delivery, int $revision): void
    {
        DB::transaction(function () use ($actor, $delivery, $revision) {
            if (! config('platform.sms.melipayamak.delivery_enabled')) {
                throw ValidationException::withMessages(['delivery' => 'استعلام تحویل در این محیط فعال نشده است.']);
            }
            $actor = $actor->fresh();
            abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
            $delivery = SmsDelivery::lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->revision !== $revision || in_array($delivery->status, ['polling', 'delivered'], true)) {
                throw ValidationException::withMessages(['delivery' => 'گزارش تغییر کرده، در حال بررسی یا دارای تحویل تأییدشده است.']);
            }
            $delivery->update(['status' => 'pending', 'next_poll_at' => now(), 'expires_at' => now()->addHours(72), 'dispatched_at' => null, 'revision' => $delivery->revision + 1]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'sms_delivery.query_requested', 'subject_type' => 'sms_delivery', 'subject_id' => $delivery->id, 'metadata' => ['revision' => $delivery->revision]]);
        }, 3);
    }
}
