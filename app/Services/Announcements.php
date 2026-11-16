<?php

namespace App\Services;

use App\Jobs\DeliverAnnouncement;
use App\Models\Announcement;
use App\Models\AnnouncementSchedule;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class Announcements
{
    private function admin(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['announcement' => 'وضعیت یا نسخه اطلاعیه تغییر کرده است؛ صفحه را تازه کنید.']);
    }

    private function audit(string $action, Announcement $announcement, ?int $actor = null, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $actor, 'action' => 'announcement.'.$action, 'subject_type' => 'announcement', 'subject_id' => $announcement->id, 'metadata' => [...$metadata, 'revision' => $announcement->revision]]);
    }

    private function lockedActor(User $actor): User
    {
        $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);

        return $actor;
    }

    private function definition(array $input): array
    {
        $data = Validator::make($input, ['title' => 'required|string|min:3|max:160', 'message' => 'required|string|min:3|max:3000', 'roles' => 'required|array|list|min:1|max:4', 'roles.*' => 'required|distinct|in:student,coach,org_admin,admin'])->validate();
        foreach (['title', 'message'] as $key) {
            if (! mb_check_encoding($data[$key], 'UTF-8')) {
                throw ValidationException::withMessages([$key => 'متن معتبر وارد کنید.']);
            }
        }
        sort($data['roles']);

        return $data;
    }

    private function hash(array $definition, AnnouncementSchedule $schedule): string
    {
        $approval = ['engine' => 'internal_announcement_schedule_v1', 'announcement_id' => (int) $schedule->announcement_id, 'created_by' => (int) $schedule->created_by, 'source_revision' => $schedule->source_revision, 'announcement_revision' => $schedule->announcement_revision, 'scheduled_for_utc' => $schedule->scheduled_for->copy()->setTimezone('UTC')->format('Y-m-d\\TH:i:s\\Z'), 'definition' => $definition];

        return hash_hmac('sha256', json_encode($approval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function cancelPending(User $actor, Announcement $announcement, string $reason): void
    {
        foreach ($announcement->schedules()->where('status', 'pending')->lockForUpdate()->get() as $schedule) {
            $schedule->update(['status' => 'cancelled', 'reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit('schedule_cancelled', $announcement, $actor->id, ['schedule_id' => $schedule->id, 'reason' => $reason]);
        }
    }

    private function publishLocked(User $actor, Announcement $announcement, ?int $scheduleId = null): void
    {
        $snapshot = $this->audience($announcement->roles)->selectRaw('? as announcement_id, users.id as user_id, ? as status, ? as created_at, ? as updated_at', [$announcement->id, 'pending', now()->toDateTimeString(), now()->toDateTimeString()]);
        DB::table('announcement_recipients')->insertUsing(['announcement_id', 'user_id', 'status', 'created_at', 'updated_at'], $snapshot->toBase());
        $count = $announcement->recipients()->count();
        if ($count === 0) {
            throw ValidationException::withMessages(['announcement' => 'مخاطب فعال با موبایل تأییدشده برای این نقش‌ها وجود ندارد.']);
        }
        $announcement->update(['status' => 'sending', 'recipient_count' => $count, 'published_at' => now(), 'revision' => $announcement->revision + 1]);
        $this->audit('published', $announcement, $actor->id, $scheduleId ? ['schedule_id' => $scheduleId, 'source' => 'scheduled'] : []);
    }

    public function schedule(User $actor, Announcement $announcement, int $revision, CarbonImmutable $at, bool $reviewed): AnnouncementSchedule
    {
        $at = $at->setTimezone('Asia/Tehran')->startOfMinute();
        if (! $reviewed || $at->lt(now()->addMinute()) || $at->gt(now()->addYear())) {
            throw ValidationException::withMessages(['announcement' => 'تأیید صریح متن ذخیره‌شده و زمانی از یک دقیقه آینده تا یک سال لازم است.']);
        }

        return DB::transaction(function () use ($actor, $announcement, $revision, $at) {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $actor = $this->lockedActor($actor);
            if ($announcement->status !== 'draft' || $announcement->revision !== $revision) {
                $this->fail();
            }
            $definition = $this->definition($announcement->only('title', 'message', 'roles'));
            $this->cancelPending($actor, $announcement, 'replaced');
            $announcement->update(['revision' => $revision + 1]);
            $schedule = $announcement->schedules()->make(['created_by' => $actor->id, 'source_revision' => $revision, 'announcement_revision' => $announcement->revision, 'definition' => $definition, 'scheduled_for' => $at]);
            $schedule->definition_hash = $this->hash($definition, $schedule);
            $schedule->save();
            $this->audit('scheduled', $announcement, $actor->id, ['schedule_id' => $schedule->id]);

            return $schedule;
        }, 3);
    }

    public function cancelSchedule(User $actor, Announcement $announcement, int $revision, AnnouncementSchedule $schedule): void
    {
        DB::transaction(function () use ($actor, $announcement, $revision, $schedule): void {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $actor = $this->lockedActor($actor);
            $schedule = $announcement->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($announcement->status !== 'draft' || $announcement->revision !== $revision || $schedule->status !== 'pending') {
                $this->fail();
            }
            $this->cancelPending($actor, $announcement, 'admin_cancelled');
            $announcement->update(['revision' => $revision + 1]);
        }, 3);
    }

    public function processSchedule(AnnouncementSchedule $input): string
    {
        return DB::transaction(function () use ($input) {
            $announcement = Announcement::whereKey($input->announcement_id)->lockForUpdate()->firstOrFail();
            $schedule = AnnouncementSchedule::whereKey($input->id)->lockForUpdate()->firstOrFail();
            if ($schedule->status !== 'pending') {
                return 'already_handled';
            }
            abort_unless($schedule->announcement_id === $announcement->id, 409);
            if ($schedule->scheduled_for->gt(now())) {
                return 'pending';
            }
            $actor = User::whereKey($schedule->created_by)->lockForUpdate()->firstOrFail();
            $reason = null;
            if (! $actor->is_active || ! $actor->hasRole('admin')) {
                $reason = 'author_ineligible';
            } elseif ($announcement->status !== 'draft') {
                $reason = 'already_published';
            } elseif ($announcement->revision !== $schedule->announcement_revision) {
                $reason = 'announcement_changed';
            } else {
                try {
                    $definition = $this->definition($schedule->definition);
                    $current = $this->definition($announcement->only('title', 'message', 'roles'));
                    if ($this->hash($definition, $schedule) !== $schedule->definition_hash || $this->hash($current, $schedule) !== $schedule->definition_hash) {
                        $reason = 'snapshot_changed';
                    } else {
                        try {
                            $this->publishLocked($actor, $announcement, $schedule->id);
                        } catch (ValidationException) {
                            $reason = 'empty_audience';
                        }
                    }
                } catch (ValidationException) {
                    $reason = 'invalid_content';
                }
            }
            if ($reason) {
                $schedule->update(['status' => 'blocked', 'reason' => $reason, 'closed_at' => now()]);
                $announcement->update(['revision' => $announcement->revision + 1]);
                $this->audit('schedule_blocked', $announcement, $actor->id, ['schedule_id' => $schedule->id, 'reason' => $reason]);

                return 'blocked';
            }
            $schedule->update(['status' => 'published', 'closed_by' => $actor->id, 'closed_at' => now()]);

            return 'published';
        }, 3);
    }

    public function processDueSchedules(): array
    {
        $result = ['published' => 0, 'blocked' => 0, 'failed' => 0];
        $schedules = AnnouncementSchedule::where('status', 'pending')->where('scheduled_for', '<=', now())->orderBy('scheduled_for')->orderBy('id')->limit(100)->get(['id', 'announcement_id']);
        foreach ($schedules as $schedule) {
            try {
                $state = $this->processSchedule($schedule);
                if (isset($result[$state])) {
                    $result[$state]++;
                }
            } catch (Throwable) {
                $result['failed']++;
                Log::warning('Scheduled announcement could not be processed.', ['schedule_id' => $schedule->id]);
            }
        }

        return $result;
    }

    public function audience(array $roles): Builder
    {
        return User::where('is_active', true)->whereNotNull('phone_verified_at')->whereHas('roles', fn ($q) => $q->whereIn('key', $roles));
    }

    public function save(User $actor, array $input, ?Announcement $announcement = null, ?int $revision = null): Announcement
    {
        $this->admin($actor);
        $data = $this->definition($input);

        return DB::transaction(function () use ($actor, $data, $announcement, $revision) {
            if ($announcement) {
                $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
                $actor = $this->lockedActor($actor);
                if ($announcement->status !== 'draft' || $announcement->revision !== $revision) {
                    $this->fail();
                }
                $this->cancelPending($actor, $announcement, 'draft_changed');
                $announcement->update([...$data, 'revision' => $announcement->revision + 1]);
            } else {
                $actor = $this->lockedActor($actor);
                $announcement = Announcement::create([...$data, 'created_by' => $actor->id]);
            }
            $this->audit('draft_saved', $announcement, $actor->id);

            return $announcement;
        }, 3);
    }

    public function publish(User $actor, Announcement $announcement, int $revision): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $announcement, $revision) {
            $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
            $actor = $this->lockedActor($actor);
            if ($announcement->status !== 'draft' || $announcement->revision !== $revision) {
                $this->fail();
            }
            $this->definition($announcement->only('title', 'message', 'roles'));
            $this->cancelPending($actor, $announcement, 'published_now');
            $this->publishLocked($actor, $announcement);
        }, 3);
    }

    public function stop(User $actor, Announcement $announcement, int $revision, string $reason): void
    {
        $this->admin($actor);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $announcement, $revision, $reason) {
            $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
            $actor = $this->lockedActor($actor);
            if (! in_array($announcement->status, ['sending', 'failed'], true) || $announcement->revision !== $revision) {
                $this->fail();
            }
            $announcement->recipients()->where('status', 'pending')->update(['status' => 'cancelled', 'finished_at' => now()]);
            $announcement->update(['status' => 'stopped', 'stop_reason' => $reason, 'finished_at' => now(), 'revision' => $announcement->revision + 1]);
            $this->audit('stopped', $announcement, $actor->id);
        }, 3);
    }

    public function retry(User $actor, Announcement $announcement, int $revision): void
    {
        $this->admin($actor);
        DB::transaction(function () use ($actor, $announcement, $revision) {
            $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
            $actor = $this->lockedActor($actor);
            if ($announcement->status !== 'failed' || $announcement->revision !== $revision) {
                $this->fail();
            }
            $announcement->update(['status' => 'sending', 'dispatched_at' => null, 'revision' => $announcement->revision + 1]);
            $this->audit('resumed', $announcement, $actor->id);
        }, 3);
    }

    public function dispatchPending(): int
    {
        $count = 0;
        $ids = Announcement::where('status', 'sending')->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(10)))->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id) {
                $announcement = Announcement::lockForUpdate()->findOrFail($id);
                if ($announcement->status !== 'sending' || $announcement->dispatched_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $announcement->update(['dispatched_at' => now()]);
                DeliverAnnouncement::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();

                return true;
            }, 3);
        }

        return $count;
    }

    public function deliver(int $id): int
    {
        return DB::transaction(function () use ($id) {
            $announcement = Announcement::lockForUpdate()->findOrFail($id);
            if ($announcement->status !== 'sending') {
                return 0;
            }
            $recipients = $announcement->recipients()->where('status', 'pending')->orderBy('id')->limit(100)->lockForUpdate()->get();
            $users = User::whereIn('id', $recipients->pluck('user_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sent = 0;
            foreach ($recipients as $recipient) {
                $user = $users->get($recipient->user_id);
                if (! $user->is_active || ! $user->phone_verified_at || ! $user->roles()->whereIn('key', $announcement->roles)->exists()) {
                    $recipient->update(['status' => 'skipped', 'failure_code' => 'recipient_unavailable', 'finished_at' => now()]);

                    continue;
                }
                $notification = new PlatformAnnouncement($id, $announcement->title, $announcement->message);
                $notification->id = (string) Str::uuid();
                $user->notify($notification);
                $recipient->update(['status' => 'delivered', 'notification_id' => $notification->id, 'finished_at' => now()]);
                $sent++;
            }
            $pending = $announcement->recipients()->where('status', 'pending')->exists();
            $announcement->update(['status' => $pending ? 'sending' : 'completed', 'dispatched_at' => null, 'finished_at' => $pending ? null : now(), 'revision' => $announcement->revision + 1]);
            if (! $pending) {
                $this->audit('completed', $announcement);
            }

            return $sent;
        }, 3);
    }

    public function failed(int $id): void
    {
        DB::transaction(function () use ($id) {
            $announcement = Announcement::lockForUpdate()->findOrFail($id);
            if ($announcement->status === 'sending') {
                $announcement->update(['status' => 'failed', 'revision' => $announcement->revision + 1]);
                $this->audit('delivery_failed', $announcement);
            }
        }, 3);
    }
}
