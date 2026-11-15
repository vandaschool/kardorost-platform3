<?php

namespace App\Services;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\SupportChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupportDesk
{
    public function isCoach(User $user, SupportTicket $ticket): bool
    {
        return $user->is_active && $user->hasRole('coach') && $ticket->cohort_id && $ticket->cohort->course->coach_id === $user->id && Enrollment::where('user_id', $ticket->user_id)->where('cohort_id', $ticket->cohort_id)->where('status', 'active')->whereHas('user', fn ($query) => $query->where('is_active', true))->exists();
    }

    public function authorize(User $user, SupportTicket $ticket): void
    {
        abort_unless($user->is_active && ($user->id === $ticket->user_id || $user->hasRole('admin') || $this->isCoach($user, $ticket)), 403);
    }

    public function scope(Builder $query, User $user, string $view): Builder
    {
        if ($view === 'admin') {
            abort_unless($user->hasRole('admin'), 403);

            return $query;
        }
        if ($view === 'coach') {
            abort_unless($user->hasRole('coach'), 403);

            return $query->whereHas('cohort.course', fn ($q) => $q->where('coach_id', $user->id))->whereHas('owner', fn ($q) => $q->where('is_active', true))->whereExists(function ($q) {
                $q->selectRaw('1')->from('enrollments')->whereColumn('enrollments.user_id', 'support_tickets.user_id')->whereColumn('enrollments.cohort_id', 'support_tickets.cohort_id')->where('enrollments.status', 'active');
            });
        }

        return $query->where('user_id', $user->id);
    }

    /** Lock educational parents before accounts and the ticket, matching classroom workflows. */
    private function lock(User $actor, ?SupportTicket $ticket, ?int $cohortId = null, ?int $assigneeId = null): User
    {
        $cohortId ??= $ticket?->cohort_id;
        if ($cohortId) {
            $cohort = Cohort::findOrFail($cohortId);
            Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
            Cohort::whereKey($cohortId)->lockForUpdate()->firstOrFail();
            Enrollment::where('cohort_id', $cohortId)->where('user_id', $ticket?->user_id ?? $actor->id)->lockForUpdate()->first();
        }
        $ids = array_filter([$actor->id, $ticket?->user_id, $assigneeId]);
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $actor = $actor->fresh();
        abort_unless($actor->is_active && $actor->phone_verified_at, 403);

        return $actor;
    }

    public function create(User $actor, array $data, ?UploadedFile $file): SupportTicket
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $data, $file, &$path) {
                $cohortId = ! empty($data['cohort_id']) ? (int) $data['cohort_id'] : null;
                $actor = $this->lock($actor, null, $cohortId);
                if ($cohortId) {
                    abort_unless($data['category'] === 'academic' && $actor->hasRole('student') && Enrollment::where('user_id', $actor->id)->where('cohort_id', $cohortId)->where('status', 'active')->exists(), 403);
                }
                $key = strtolower($data['request_key']);
                if ($existing = SupportTicket::where('user_id', $actor->id)->where('request_key', $key)->first()) {
                    $first = $existing->messages()->orderBy('id')->firstOrFail();
                    $this->sameMessage($first, $existing, $data['body'] ?? null, $file, false);
                    if ($existing->subject !== $data['subject'] || $existing->category !== $data['category'] || $existing->priority !== $data['priority'] || $existing->cohort_id !== $cohortId) {
                        throw ValidationException::withMessages(['request_key' => 'این شناسه برای درخواست دیگری استفاده شده است.']);
                    }

                    return $existing;
                }
                if (TicketMessage::where('author_id', $actor->id)->where('request_key', $key)->exists()) {
                    throw ValidationException::withMessages(['request_key' => 'شناسه ارسال قبلاً استفاده شده است.']);
                }
                $ticket = SupportTicket::create(['user_id' => $actor->id, 'cohort_id' => $cohortId, 'subject' => $data['subject'], 'category' => $data['category'], 'priority' => $data['priority'], 'request_key' => $key, 'last_message_at' => now()]);
                $this->message($actor, $ticket, $data, $file, false, $path);
                $this->notify($actor, $ticket, false);

                return $ticket;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('support')->delete($path);
            }
            throw $exception;
        }
    }

    public function reply(User $actor, SupportTicket $ticket, array $data, ?UploadedFile $file): void
    {
        $path = null;
        try {
            DB::transaction(function () use ($actor, $ticket, $data, $file, &$path) {
                $actor = $this->lock($actor, $ticket);
                $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket->id);
                $this->authorize($actor, $ticket);
                $internal = (bool) ($data['internal_note'] ?? false);
                abort_if($internal && ! $actor->hasRole('admin'), 403);
                if ($existing = TicketMessage::where('author_id', $actor->id)->where('request_key', strtolower($data['request_key']))->first()) {
                    $this->sameMessage($existing, $ticket, $data['body'] ?? null, $file, $internal);

                    return;
                }
                if ($ticket->status === 'closed' || (! $internal && $ticket->status === 'resolved')) {
                    throw ValidationException::withMessages(['body' => 'پیش از ارسال پیام، درخواست باید بازگشایی شود.']);
                }
                if ($actor->id === $ticket->user_id && $ticket->cohort_id && ! Enrollment::where('user_id', $actor->id)->where('cohort_id', $ticket->cohort_id)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['body' => 'عضویت آموزشی فعال نیست؛ درخواست عمومی تازه‌ای برای پشتیبانی بفرستید.']);
                }
                $this->message($actor, $ticket, $data, $file, $internal, $path);
                if (! $internal) {
                    $ticket->update(['status' => $actor->id === $ticket->user_id ? 'waiting_staff' : 'waiting_requester', 'revision' => $ticket->revision + 1, 'last_message_at' => now()]);
                }
                $this->notify($actor, $ticket, $internal);
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('support')->delete($path);
            }
            throw $exception;
        }
    }

    private function sameMessage(TicketMessage $message, SupportTicket $ticket, ?string $body, ?UploadedFile $file, bool $internal): void
    {
        if ($message->support_ticket_id !== $ticket->id || ($message->body ?? '') !== ($body ?? '') || $message->internal_note !== $internal || $message->file_hash !== ($file ? hash_file('sha256', $file->getRealPath()) : null)) {
            throw ValidationException::withMessages(['request_key' => 'شناسه ارسال تکراری است و محتوای آن تغییر کرده است.']);
        }
    }

    private function message(User $actor, SupportTicket $ticket, array $data, ?UploadedFile $file, bool $internal, ?string &$path): void
    {
        $path = $file?->store((string) $ticket->id, 'support');
        $ticket->messages()->create(['author_id' => $actor->id, 'author_role' => $actor->id === $ticket->user_id ? 'requester' : ($actor->hasRole('admin') ? 'admin' : 'coach'), 'body' => $data['body'] ?? null, 'internal_note' => $internal, 'request_key' => strtolower($data['request_key']), 'file_path' => $path, 'file_name' => $file ? preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', mb_substr($file->getClientOriginalName(), 0, 180)) : null, 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'file_size' => $file?->getSize()]);
    }

    public function change(User $actor, SupportTicket $ticket, array $data): void
    {
        DB::transaction(function () use ($actor, $ticket, $data) {
            $actor = $this->lock($actor, $ticket, assigneeId: $data['assigned_to'] ?? null);
            $ticket = SupportTicket::lockForUpdate()->findOrFail($ticket->id);
            $this->authorize($actor, $ticket);
            $admin = $actor->hasRole('admin');
            abort_unless($admin || $actor->id === $ticket->user_id, 403);
            if ($ticket->revision !== $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'درخواست تغییر کرده است؛ صفحه را تازه کنید.']);
            }
            $from = $ticket->status;
            if ($data['action'] === 'assign') {
                abort_unless($admin, 403);
                $target = isset($data['assigned_to']) ? User::findOrFail($data['assigned_to']) : null;
                if ($target && (! $target->is_active || ! $target->hasRole('admin'))) {
                    throw ValidationException::withMessages(['assigned_to' => 'مسئول باید ادمین فعال باشد.']);
                }
                $ticket->assigned_to = $target?->id;
            } else {
                $status = match ($data['action']) {
                    'resolve' => 'resolved',
                    'close' => 'closed',
                    'reopen' => 'waiting_staff',
                };
                abort_if($data['action'] === 'close' && ! $admin, 403);
                if (($from === 'closed' && ! $admin) || ($data['action'] === 'reopen' && ! in_array($from, ['resolved', 'closed'])) || ($data['action'] !== 'reopen' && in_array($from, ['resolved', 'closed']))) {
                    throw ValidationException::withMessages(['action' => 'این تغییر برای وضعیت فعلی مجاز نیست.']);
                }
                $ticket->status = $status;
            }
            $ticket->revision++;
            $ticket->save();
            $ticket->events()->create(['actor_id' => $actor->id, 'action' => $data['action'], 'from_status' => $from, 'to_status' => $ticket->status, 'reason' => $data['reason'], 'internal' => $data['action'] === 'assign']);
            $this->notify($actor, $ticket, $data['action'] === 'assign');
        });
    }

    private function notify(User $actor, SupportTicket $ticket, bool $internal): void
    {
        $recipients = [];
        if (! $internal && $actor->id !== $ticket->user_id && $ticket->owner->is_active) {
            $recipients[] = $ticket->user_id;
        }
        if ($actor->id === $ticket->user_id || $internal) {
            $recipients = [...$recipients, ...User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'admin'))->when($ticket->assigned_to && User::whereKey($ticket->assigned_to)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'admin'))->exists(), fn ($q) => $q->whereKey($ticket->assigned_to))->pluck('id')->all()];
        }
        if (! $internal && $ticket->cohort_id && ($coach = User::find($ticket->cohort->course->coach_id)) && $this->isCoach($coach, $ticket)) {
            $recipients[] = $coach->id;
        }
        foreach (array_unique($recipients) as $id) {
            if ($id !== $actor->id) {
                User::findOrFail($id)->notify(new SupportChanged($ticket->id));
            }
        }
    }
}
