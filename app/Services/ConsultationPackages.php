<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationGrantUse;
use App\Models\ConsultationSlot;
use App\Models\User;
use App\Notifications\ConsultationChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationPackages
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['package' => $message]);
    }

    public function configure(User $admin, User $student, User $coach, array $data, ?ConsultationGrant $grant = null): ConsultationGrant
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);

        return DB::transaction(function () use ($admin, $student, $coach, $data, $grant) {
            User::whereIn('id', [$student->id, $coach->id])->orderBy('id')->lockForUpdate()->get();
            $student->refresh();
            $coach->refresh();
            if (! $grant && (! $student->is_active || ! $student->phone_verified_at || ! $student->hasRole('student') || ! $coach->is_active || ! $coach->hasRole('coach') || $student->id === $coach->id)) {
                $this->fail('دانشجوی تأییدشده و مربی فعال و متفاوت لازم است.');
            }
            if ($grant) {
                $grant = ConsultationGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
                if ($grant->courseBenefit()->exists()) {
                    $this->fail('سهمیه همراه دوره تابع قرارداد خرید است و از این فرم قابل تغییر نیست.');
                }
                if ($grant->student_id !== $student->id || $grant->coach_id !== $coach->id || $grant->revision !== (int) $data['revision'] || (int) $data['total_sessions'] < $grant->uses()->whereNull('released_at')->count()) {
                    $this->fail('نسخه قدیمی است یا سقف سهمیه از مصرف ثبت‌شده کمتر است.');
                }
                $grant->update(['total_sessions' => (int) $data['total_sessions'], 'valid_until' => $data['valid_until'], 'active' => (bool) $data['active'], 'revision' => $grant->revision + 1]);
            } else {
                $source = mb_strtoupper(trim($data['source_reference']));
                if (str_starts_with($source, 'COURSE-BENEFIT-')) {
                    $this->fail('این پیشوند برای سهمیه خودکار دوره رزرو شده است.');
                }
                if (ConsultationGrant::where('student_id', $student->id)->where('source_reference', $source)->exists()) {
                    $this->fail('این منبع سهمیه قبلاً برای دانشجو ثبت شده است.');
                }
                $grant = ConsultationGrant::create(['student_id' => $student->id, 'coach_id' => $coach->id, 'created_by' => $admin->id, 'title' => $data['title'], 'source_reference' => $source, 'total_sessions' => (int) $data['total_sessions'], 'session_minutes' => (int) $data['session_minutes'], 'valid_until' => $data['valid_until']]);
            }
            AuditEvent::create(['actor_id' => $admin->id, 'action' => 'consultation_grant.configured', 'subject_type' => 'consultation_grant', 'subject_id' => $grant->id, 'metadata' => ['revision' => $grant->revision, 'total_sessions' => $grant->total_sessions, 'active' => $grant->active, 'valid_until' => $grant->valid_until->toDateString()]]);
            $student->notify(new ConsultationChanged('سهمیه پکیج مشاوره به‌روز شد'));

            return $grant;
        }, 3);
    }

    public function forRequest(User $student, int $id): ConsultationGrant
    {
        abort_unless($student->is_active && $student->phone_verified_at && $student->hasRole('student'), 403);
        $grant = ConsultationGrant::whereKey($id)->where('student_id', $student->id)->firstOrFail();
        if (! $grant->active || $grant->valid_until->toDateString() < now()->toDateString() || $grant->remaining() < 1 || $grant->cancellation_policy !== 'before_start_full_credit_v1') {
            $this->fail('سهمیه معتبر و دارای مانده لازم است.');
        }

        return $grant;
    }

    public function matches(ConsultationGrant $grant, ConsultationSlot $slot): bool
    {
        return $grant->coach_id === $slot->coach_id && $slot->price > 0
            && (int) $slot->starts_at->diffInSeconds($slot->ends_at) === $grant->session_minutes * 60
            && $slot->ends_at->setTimezone('Asia/Tehran')->toDateString() <= $grant->valid_until->toDateString();
    }

    /** Caller holds the student, coach and slot locks. */
    public function select(User $student, ConsultationSlot $slot, int $id): ConsultationGrant
    {
        $grant = ConsultationGrant::whereKey($id)->where('student_id', $student->id)->lockForUpdate()->firstOrFail();
        $this->forRequest($student, $id);
        if (! $this->matches($grant, $slot)) {
            $this->fail('این زمان از نظر مربی، مدت یا تاریخ پایان با پکیج سازگار نیست.');
        }

        return $grant;
    }

    public function consume(ConsultationGrant $grant, Consultation $consultation, User $actor): void
    {
        ConsultationGrantUse::create(['consultation_grant_id' => $grant->id, 'consultation_id' => $consultation->id]);
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'consultation_grant.consumed', 'subject_type' => 'consultation', 'subject_id' => $consultation->id, 'metadata' => ['grant_id' => $grant->id]]);
    }

    public function release(Consultation $consultation, User $actor, string $reason): void
    {
        if (! $consultation->consultation_grant_id) {
            return;
        }
        ConsultationGrant::whereKey($consultation->consultation_grant_id)->lockForUpdate()->firstOrFail();
        $use = ConsultationGrantUse::where('consultation_id', $consultation->id)->lockForUpdate()->firstOrFail();
        if (! $use->released_at) {
            $use->update(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $reason]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'consultation_grant.released', 'subject_type' => 'consultation', 'subject_id' => $consultation->id, 'metadata' => ['grant_id' => $consultation->consultation_grant_id]]);
        }
    }
}
