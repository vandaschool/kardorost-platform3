<?php

namespace App\Services;

use App\Models\Cohort;
use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EventReminder;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\EventApproaching;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventReminders
{
    private function lead(): int
    {
        return max(5, min(1440, (int) config('platform.reminders.lead_minutes', 60)));
    }

    private function upcoming(CarbonInterface $time): bool
    {
        return $time->gt(now()) && $time->lte(now()->addMinutes($this->lead()));
    }

    /** Locks current educational/consultation parents before recipient and reminder rows. */
    public function context(string $type, int $sourceId, int $userId, ?CarbonInterface $occursAt = null): ?array
    {
        if ($type === 'consultation') {
            $source = Consultation::with('slot')->find($sourceId);
            if (! $source) {
                return null;
            }
            User::whereIn('id', [$source->student_id, $source->slot->coach_id])->orderBy('id')->lockForUpdate()->get();
            $slot = ConsultationSlot::lockForUpdate()->findOrFail($source->consultation_slot_id);
            $source = Consultation::lockForUpdate()->findOrFail($sourceId);
            $user = User::findOrFail($userId);
            $eligible = ($source->student_id === $userId && $user->hasRole('student')) || ($slot->coach_id === $userId && $user->hasRole('coach'));
            if (! $eligible || ! $user->is_active || ! $user->phone_verified_at || $source->status !== 'confirmed' || ! $this->upcoming($slot->starts_at) || ($occursAt && ! $occursAt->equalTo($slot->starts_at))) {
                return null;
            }

            return ['user' => $user, 'occurs_at' => $slot->starts_at, 'kind' => 'مشاوره'];
        }
        if ($type !== 'live_session') {
            return null;
        }
        $source = LiveSession::with('cohort.course')->find($sourceId);
        if (! $source) {
            return null;
        }
        $course = Course::lockForUpdate()->findOrFail($source->cohort->course_id);
        $cohort = Cohort::lockForUpdate()->findOrFail($source->cohort_id);
        $enrollment = Enrollment::where('user_id', $userId)->where('cohort_id', $cohort->id)->lockForUpdate()->first();
        $user = User::lockForUpdate()->findOrFail($userId);
        $source = LiveSession::lockForUpdate()->findOrFail($sourceId);
        $eligible = ($course->coach_id === $userId && $user->hasRole('coach')) || ($enrollment?->status === 'active' && $user->hasRole('student'));
        if (! $eligible || ! $user->is_active || ! $user->phone_verified_at || ! $source->is_published || ! $this->upcoming($source->starts_at) || ($occursAt && ! $occursAt->equalTo($source->starts_at))) {
            return null;
        }

        return ['user' => $user, 'occurs_at' => $source->starts_at, 'kind' => 'کلاس'];
    }

    public function prepare(string $type, int $sourceId, int $userId): bool
    {
        if (! config('platform.reminders.enabled')) {
            return false;
        }

        return DB::transaction(function () use ($type, $sourceId, $userId) {
            $context = $this->context($type, $sourceId, $userId);
            if (! $context) {
                return false;
            }
            $user = $context['user'];
            $reminder = EventReminder::firstOrCreate(['source_type' => $type, 'source_id' => $sourceId, 'user_id' => $userId, 'occurs_at' => $context['occurs_at']], ['sms_status' => config('platform.sms.melipayamak.reminders_enabled') && $user->sms_reminders_enabled ? 'queued' : 'not_requested']);
            if ($reminder->in_app_sent_at) {
                return false;
            }
            $notification = new EventApproaching($reminder->id, $reminder->occurs_at->toIso8601String(), $type);
            $notification->id = (string) Str::uuid();
            $user->notify($notification);
            $reminder->update(['notification_id' => $notification->id, 'in_app_sent_at' => now()]);

            return true;
        }, 3);
    }

    public function generate(): int
    {
        if (! config('platform.reminders.enabled')) {
            return 0;
        }
        $count = 0;
        $consultations = Consultation::where('status', 'confirmed')->whereHas('slot', fn ($q) => $q->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($this->lead())))->with('slot')->lazyById(100);
        foreach ($consultations as $consultation) {
            foreach (array_unique([$consultation->student_id, $consultation->slot->coach_id]) as $id) {
                $count += (int) $this->prepare('consultation', $consultation->id, $id);
            }
        }
        $sessions = LiveSession::where('is_published', true)->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($this->lead()))->with('cohort.course')->lazyById(100);
        foreach ($sessions as $session) {
            $ids = Enrollment::where('cohort_id', $session->cohort_id)->where('status', 'active')->orderBy('id')->pluck('user_id');
            if ($session->cohort->course->coach_id) {
                $ids->push($session->cohort->course->coach_id);
            }
            foreach ($ids->unique() as $id) {
                $count += (int) $this->prepare('live_session', $session->id, $id);
            }
        }

        return $count;
    }

    public function destination(User $user, EventReminder $reminder): string
    {
        $user = $user->fresh();
        $admin = $user->hasRole('admin');
        abort_unless($user->is_active && ($user->id === $reminder->user_id || $admin), 403);
        if ($reminder->source_type === 'consultation') {
            $consultation = Consultation::with('slot')->findOrFail($reminder->source_id);
            $mode = $admin ? 'admin' : ($consultation->student_id === $user->id && $user->hasRole('student') ? 'student' : ($consultation->slot->coach_id === $user->id && $user->hasRole('coach') ? 'coach' : null));
            abort_unless($mode, 403);
            $preceding = Consultation::where('id', '>', $consultation->id)->when($mode === 'student', fn ($q) => $q->where('student_id', $user->id))->when($mode === 'coach', fn ($q) => $q->whereHas('slot', fn ($slot) => $slot->where('coach_id', $user->id)))->count();

            return route('consultations', ['view' => $mode, 'bookings_page' => 1 + intdiv($preceding, 12), 'focus' => $consultation->id]).'#consultation-'.$consultation->id;
        }
        abort_unless($reminder->source_type === 'live_session', 404);
        $session = LiveSession::with('cohort.course')->findOrFail($reminder->source_id);
        $course = $session->cohort->course;
        if ($admin || ($user->hasRole('coach') && $course->coach_id === $user->id)) {
            return route('teaching.course', $course->id);
        }
        abort_unless($user->hasRole('student'), 403);
        $enrollment = Enrollment::where('user_id', $user->id)->where('cohort_id', $session->cohort_id)->where('status', 'active')->first();
        abort_unless($enrollment, 403);

        return route('learning.room', ['enrollment' => $enrollment->id, 'tab' => 'sessions']).'#live-'.$session->id;
    }
}
