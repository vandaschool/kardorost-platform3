<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrganizationLearningExports
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['export' => 'خروجی خصوصی ساخته نشد؛ دوباره تلاش کنید.']);
    }

    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->fail();
        }

        return preg_match('/^[\s\p{Z}\x{FEFF}\x{200B}]*[=+@\-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }

    /** @param resource $output */
    private function row($output, array $values): void
    {
        if (fputcsv($output, array_map($this->cell(...), $values), ',', '"', '') === false) {
            $this->fail();
        }
    }

    private function status(Enrollment $enrollment): string
    {
        return match ($enrollment->status) {
            'active' => 'فعال', 'pending_payment' => 'در انتظار پرداخت', 'expired' => 'منقضی‌شده', 'cancelled' => 'لغوشده', default => $enrollment->status,
        };
    }

    /** @param resource $output */
    private function summary($output, array $common, Enrollment $enrollment): int
    {
        $progress = app(LearningProgress::class)->summary($enrollment);
        $attendance = Attendance::where('enrollment_id', $enrollment->id)->whereHas('liveSession', fn ($query) => $query->where('cohort_id', $enrollment->cohort_id))->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $certificate = app(Certification::class)->status($enrollment);
        $certificate = match ($certificate) {
            'none' => 'درخواستی ثبت نشده', 'pending' => 'در انتظار بررسی', 'issued' => 'صادرشده', 'revoked' => 'باطل‌شده', 'withdrawn' => 'انصراف از درخواست گواهی', 'rejected' => 'نیازمند اصلاح', default => $certificate,
        };
        $this->row($output, [...$common, $progress['total'], $progress['completed'], $progress['percent'], (int) ($attendance['present'] ?? 0), (int) ($attendance['absent'] ?? 0), (int) ($attendance['excused'] ?? 0), $certificate]);

        return 1;
    }

    /** @param resource $output */
    private function attendance($output, array $common, Enrollment $enrollment, int $lastId): int
    {
        $rows = 0;
        $query = Attendance::where('enrollment_id', $enrollment->id)->where('id', '<=', $lastId)->whereHas('liveSession', fn ($session) => $session->where('cohort_id', $enrollment->cohort_id));
        foreach ($query->with('liveSession:id,cohort_id,title,starts_at')->select('id', 'enrollment_id', 'live_session_id', 'status', 'updated_at')->lazyById(200) as $attendance) {
            $session = $attendance->liveSession;
            $status = match ($attendance->status) {
                'present' => 'حاضر', 'absent' => 'غایب', 'excused' => 'غیبت موجه', default => $attendance->status,
            };
            $this->row($output, [...$common, $session->id, $session->title, $session->starts_at->timezone('Asia/Tehran')->toIso8601String(), $status, $attendance->updated_at->timezone('Asia/Tehran')->toIso8601String()]);
            $rows++;
        }

        return $rows;
    }

    /** @param resource $output */
    private function submissions($output, array $common, Enrollment $enrollment, int $lastId): int
    {
        $rows = 0;
        $query = Submission::where('enrollment_id', $enrollment->id)->where('id', '<=', $lastId)->whereHas('assignment', fn ($assignment) => $assignment->where('cohort_id', $enrollment->cohort_id));
        foreach ($query->with('assignment:id,cohort_id,title,max_score')->select('id', 'enrollment_id', 'assignment_id', 'version', 'status', 'score', 'created_at', 'reviewed_at')->lazyById(200) as $submission) {
            $status = match ($submission->status) {
                'submitted' => 'منتظر بازخورد', 'reviewed' => 'بررسی‌شده', 'revision_requested' => 'نیازمند اصلاح', default => $submission->status,
            };
            $this->row($output, [...$common, $submission->assignment_id, $submission->assignment->title, $submission->id, $submission->version, $status, $submission->score, $submission->assignment->max_score, $submission->created_at->timezone('Asia/Tehran')->toIso8601String(), $submission->reviewed_at?->timezone('Asia/Tehran')->toIso8601String()]);
            $rows++;
        }

        return $rows;
    }

    /** @return array{stream: resource, filename: string} */
    public function prepare(User $actor, Organization $organization, User $member, bool $asAdmin = false, string $dataset = 'summary'): array
    {
        if (! in_array($dataset, ['summary', 'attendance', 'submissions'], true)) {
            throw ValidationException::withMessages(['dataset' => 'نوع خروجی آموزشی معتبر نیست.']);
        }
        $output = tmpfile();
        if ($output === false) {
            $this->fail();
        }
        try {
            DB::transaction(function () use ($actor, $organization, $member, $asAdmin, $output, $dataset): void {
                rewind($output);
                if (! ftruncate($output, 0)) {
                    $this->fail();
                }
                $organization = Organization::whereKey($organization->id)->sharedLock()->firstOrFail();
                $accounts = User::whereIn('id', [$actor->id, $member->id])->orderBy('id')->sharedLock()->get()->keyBy('id');
                $actor = $accounts->get($actor->id);
                $member = $accounts->get($member->id);
                abort_unless($actor?->is_active && $member, 403);
                if ($asAdmin) {
                    abort_unless($actor->hasRole('admin'), 403);
                } else {
                    abort_unless($actor->hasRole('org_admin') && $organization->status === 'active' && $organization->members()->where('users.id', $actor->id)->wherePivot('kind', 'manager')->exists(), 403);
                }
                abort_unless($organization->members()->where('users.id', $member->id)->exists(), 403);
                if (fwrite($output, "\xEF\xBB\xBF") !== 3) {
                    $this->fail();
                }
                $headers = ['مجموعه', 'نام عضو', 'زمان تهیه به وقت تهران', 'شناسه ثبت‌نام', 'دوره', 'نوبت', 'وضعیت ثبت‌نام'];
                $headers = [...$headers, ...match ($dataset) {
                    'summary' => ['درس‌های منتشرشده', 'درس‌های تکمیل‌شده خوداظهاری', 'درصد تکمیل خوداظهاری', 'حضور ثبت‌شده', 'غیبت ثبت‌شده', 'غیبت موجه ثبت‌شده', 'وضعیت گواهی'],
                    'attendance' => ['شناسه جلسه', 'عنوان جلسه', 'شروع جلسه به وقت تهران', 'وضعیت حضور ثبت‌شده', 'آخرین ثبت وضعیت به وقت تهران'],
                    'submissions' => ['شناسه تمرین', 'عنوان تمرین', 'شناسه پاسخ', 'نسخه ارسال', 'وضعیت ثبت‌شده', 'نمره ثبت‌شده در صورت وجود', 'سقف امتیاز جاری تمرین', 'زمان ارسال به وقت تهران', 'زمان بررسی به وقت تهران'],
                }];
                $this->row($output, $headers);
                $generated = now('Asia/Tehran')->toIso8601String();
                $query = Enrollment::where('user_id', $member->id);
                $lastId = (clone $query)->max('id') ?? 0;
                $query->where('id', '<=', $lastId);
                $recordIds = (clone $query)->select('id');
                $lastRecord = match ($dataset) {
                    'summary' => 0,
                    'attendance' => Attendance::whereIn('enrollment_id', $recordIds)->max('id') ?? 0,
                    'submissions' => Submission::whereIn('enrollment_id', $recordIds)->max('id') ?? 0,
                };
                $rows = 0;
                foreach ($query->with('course:id,title', 'cohort:id,title')->select('id', 'user_id', 'course_id', 'cohort_id', 'status')->lazyById(200) as $enrollment) {
                    $common = [$organization->name, $member->name, $generated, $enrollment->id, $enrollment->course->title, $enrollment->cohort->title, $this->status($enrollment)];
                    $rows += match ($dataset) {
                        'summary' => $this->summary($output, $common, $enrollment),
                        'attendance' => $this->attendance($output, $common, $enrollment, (int) $lastRecord),
                        'submissions' => $this->submissions($output, $common, $enrollment, (int) $lastRecord),
                    };
                }
                AuditEvent::create(['actor_id' => $actor->id, 'action' => 'organization.learning_exported', 'subject_type' => 'user', 'subject_id' => $member->id, 'metadata' => ['organization_id' => $organization->id, 'dataset' => 'learning_'.$dataset.'_v1', 'rows' => $rows]]);
            }, 3);
            rewind($output);
            $suffix = $dataset === 'summary' ? 'learning' : $dataset;

            return ['stream' => $output, 'filename' => 'kardorost-organization-'.$organization->id.'-member-'.$member->id.'-'.$suffix.'.csv'];
        } catch (Throwable $exception) {
            fclose($output);
            throw $exception;
        }
    }
}
