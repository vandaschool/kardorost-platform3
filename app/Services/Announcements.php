<?php

namespace App\Services;

use App\Jobs\DeliverAnnouncement;
use App\Models\Announcement;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    private function audit(string $action, Announcement $announcement, ?int $actor = null): void
    {
        AuditEvent::create(['actor_id' => $actor, 'action' => 'announcement.'.$action, 'subject_type' => 'announcement', 'subject_id' => $announcement->id, 'metadata' => ['revision' => $announcement->revision]]);
    }

    public function audience(array $roles): Builder
    {
        return User::where('is_active', true)->whereNotNull('phone_verified_at')->whereHas('roles', fn ($q) => $q->whereIn('key', $roles));
    }

    public function save(User $actor, array $input, ?Announcement $announcement = null, ?int $revision = null): Announcement
    {
        $this->admin($actor);
        $data = Validator::make($input, ['title' => 'required|string|min:3|max:160', 'message' => 'required|string|min:3|max:3000', 'roles' => 'required|array|list|min:1|max:4', 'roles.*' => 'required|distinct|in:student,coach,org_admin,admin'])->validate();
        sort($data['roles']);

        return DB::transaction(function () use ($actor, $data, $announcement, $revision) {
            if ($announcement) {
                $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
                if ($announcement->status !== 'draft' || $announcement->revision !== $revision) {
                    $this->fail();
                }
                $announcement->update([...$data, 'revision' => $announcement->revision + 1]);
            } else {
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
            if ($announcement->status !== 'draft' || $announcement->revision !== $revision) {
                $this->fail();
            }
            $snapshot = $this->audience($announcement->roles)->selectRaw('? as announcement_id, users.id as user_id, ? as status, ? as created_at, ? as updated_at', [$announcement->id, 'pending', now()->toDateTimeString(), now()->toDateTimeString()]);
            DB::table('announcement_recipients')->insertUsing(['announcement_id', 'user_id', 'status', 'created_at', 'updated_at'], $snapshot->toBase());
            $count = $announcement->recipients()->count();
            if ($count === 0) {
                throw ValidationException::withMessages(['announcement' => 'مخاطب فعال با موبایل تأییدشده برای این نقش‌ها وجود ندارد.']);
            }
            $announcement->update(['status' => 'sending', 'recipient_count' => $count, 'published_at' => now(), 'revision' => $announcement->revision + 1]);
            $this->audit('published', $announcement, $actor->id);
        }, 3);
    }

    public function stop(User $actor, Announcement $announcement, int $revision, string $reason): void
    {
        $this->admin($actor);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $announcement, $revision, $reason) {
            $announcement = Announcement::lockForUpdate()->findOrFail($announcement->id);
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
