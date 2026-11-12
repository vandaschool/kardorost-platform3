<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\User;
use App\Notifications\ConsultationChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['consultation' => $message]);
    }

    private function people(array $ids): void
    {
        User::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get();
    }

    private function audit(User $actor, string $action, string $type, int $id): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id]);
    }

    public function manager(User $actor, ConsultationSlot $slot): void
    {
        abort_unless($actor->is_active && ($actor->hasRole('admin') || ($actor->hasRole('coach') && $slot->coach_id === $actor->id)), 403);
    }

    public function createSlot(User $actor, User $coach, CarbonImmutable $start, int $minutes, string $provider, ?string $url): ConsultationSlot
    {
        abort_unless($actor->hasRole('admin') || ($actor->hasRole('coach') && $actor->id === $coach->id), 403);

        $start = $start->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($actor, $coach, $start, $minutes, $provider, $url) {
            $this->people([$coach->id]);
            $coach->refresh();
            if (! $coach->is_active || ! $coach->hasRole('coach') || $start->lte(now()) || $start->gt(now()->addYear())) {
                $this->fail('مربی فعال و زمان آینده تا یک سال را انتخاب کنید.');
            }
            $end = $start->addMinutes($minutes);
            if (ConsultationSlot::where('coach_id', $coach->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                $this->fail('این بازه با زمان دیگری از مربی هم‌پوشانی دارد.');
            }
            $slot = ConsultationSlot::create(['coach_id' => $coach->id, 'starts_at' => $start, 'ends_at' => $end, 'provider' => $provider, 'meeting_url' => $url]);
            $this->audit($actor, 'consultation.slot_created', 'consultation_slot', $slot->id);

            return $slot;
        }, 3);
    }

    public function book(User $student, ConsultationSlot $slot, string $topic, ?ConsultationRequest $request = null, ?User $actor = null, ?int $version = null): Consultation
    {
        $actor ??= $student;
        abort_unless($student->is_active && $student->hasRole('student'), 403);
        abort_unless($actor->id === $student->id || $actor->hasRole('admin'), 403);

        return DB::transaction(function () use ($student, $slot, $topic, $request, $actor, $version) {
            $this->people([$student->id, $slot->coach_id]);
            if ($request) {
                $request = ConsultationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($request->student_id !== $student->id || $request->status !== 'pending' || $request->version !== $version) {
                    $this->fail('درخواست تغییر کرده است؛ صفحه را تازه کنید.');
                }
            }
            $slot = ConsultationSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $coach = $slot->coach;
            $student->refresh();
            if (! $student->is_active || ! $student->hasRole('student') || ! $coach->is_active || ! $coach->hasRole('coach') || $slot->coach_id === $student->id || ! $slot->published || $slot->booked || $slot->starts_at->lte(now())) {
                $this->fail('این زمان قابل رزرو نیست.');
            }
            if (Consultation::where('student_id', $student->id)->where('status', 'confirmed')->whereHas('slot', fn ($q) => $q->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at))->exists()) {
                $this->fail('در این بازه مشاوره دیگری دارید.');
            }
            $item = Consultation::create(['student_id' => $student->id, 'consultation_slot_id' => $slot->id, 'topic' => $topic]);
            $slot->update(['booked' => true]);
            if ($request) {
                $request->update(['status' => 'scheduled', 'consultation_id' => $item->id, 'version' => $request->version + 1, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            }
            $this->audit($actor, 'consultation.booked', 'consultation', $item->id);
            $this->notify($item, 'جلسه مشاوره رزرو شد');

            return $item;
        }, 3);
    }

    public function request(User $student, string $topic, string $availability): ConsultationRequest
    {
        abort_unless($student->hasRole('student'), 403);

        return DB::transaction(function () use ($student, $topic, $availability) {
            $this->people([$student->id]);
            if (ConsultationRequest::where('student_id', $student->id)->where('status', 'pending')->exists()) {
                $this->fail('یک درخواست باز دارید؛ نتیجه همان درخواست را پیگیری کنید.');
            }
            $item = ConsultationRequest::create(['student_id' => $student->id, 'topic' => $topic, 'availability' => $availability]);
            $this->audit($student, 'consultation.requested', 'consultation_request', $item->id);

            return $item;
        }, 3);
    }

    public function resolve(User $actor, ConsultationRequest $item, int $version, string $reason): void
    {
        abort_unless($actor->hasRole('admin') || $actor->id === $item->student_id, 403);
        DB::transaction(function () use ($actor, $item, $version, $reason) {
            $this->people([$item->student_id]);
            $item = ConsultationRequest::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'pending' || $item->version !== $version) {
                $this->fail('این درخواست قبلاً تعیین تکلیف شده است.');
            }
            $item->update(['status' => $actor->hasRole('admin') ? 'declined' : 'withdrawn', 'resolution' => $reason, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'version' => $item->version + 1]);
            $this->audit($actor, 'consultation.request_closed', 'consultation_request', $item->id);
            $item->student->notify(new ConsultationChanged('درخواست هماهنگی بسته شد'));
        }, 3);
    }

    public function change(User $actor, Consultation $item, int $version, string $action, ?string $reason, ?string $summary): void
    {
        $slot = $item->slot;
        $owner = $actor->id === $item->student_id;
        if (! $owner || $action !== 'cancel') {
            $this->manager($actor, $slot);
        }
        DB::transaction(function () use ($actor, $item, $slot, $version, $action, $reason, $summary) {
            $this->people([$item->student_id, $slot->coach_id]);
            $slot = ConsultationSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $item = Consultation::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'confirmed' || $item->version !== $version) {
                $this->fail('وضعیت جلسه تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($action === 'cancel') {
                if ($slot->starts_at->lte(now())) {
                    $this->fail('لغو فقط پیش از شروع جلسه ممکن است.');
                }
                $item->fill(['status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
                $slot->update(['booked' => false]);
            } else {
                if ($slot->ends_at->gt(now())) {
                    $this->fail('نتیجه فقط پس از پایان بازه جلسه قابل ثبت است.');
                }
                $item->fill(['status' => $action, 'shared_summary' => $summary]);
            }
            $item->version++;
            $item->save();
            $this->audit($actor, 'consultation.'.$action, 'consultation', $item->id);
            $this->notify($item, 'وضعیت جلسه مشاوره تغییر کرد');
        }, 3);
    }

    public function publish(User $actor, ConsultationSlot $slot, bool $published): void
    {
        $this->manager($actor, $slot);
        DB::transaction(function () use ($actor, $slot, $published) {
            $this->people([$slot->coach_id]);
            $slot = ConsultationSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            if ($slot->booked || $slot->starts_at->lte(now())) {
                $this->fail('زمان رزروشده یا گذشته قابل تغییر نیست.');
            }
            $slot->update(['published' => $published]);
            $this->audit($actor, 'consultation.slot_visibility', 'consultation_slot', $slot->id);
        }, 3);
    }

    public function meeting(User $actor, ConsultationSlot $slot, string $url): void
    {
        $this->manager($actor, $slot);
        DB::transaction(function () use ($actor, $slot, $url) {
            $slot = ConsultationSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            if ($slot->ends_at->lte(now())) {
                $this->fail('جلسه پایان یافته است.');
            }
            $slot->update(['meeting_url' => app(MediaUrl::class)->meeting($slot->provider, $url)]);
            $this->audit($actor, 'consultation.meeting_updated', 'consultation_slot', $slot->id);
        }, 3);
    }

    private function notify(Consultation $item, string $title): void
    {
        foreach (User::whereIn('id', [$item->student_id, $item->slot->coach_id])->get() as $user) {
            $user->notify(new ConsultationChanged($title));
        }
    }
}
