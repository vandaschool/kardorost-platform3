<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Organization;
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

    /** @return array{stream: resource, filename: string} */
    public function prepare(User $actor, Organization $organization, User $member, bool $asAdmin = false): array
    {
        $output = tmpfile();
        if ($output === false) {
            $this->fail();
        }
        try {
            DB::transaction(function () use ($actor, $organization, $member, $asAdmin, $output): void {
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
                $this->row($output, ['مجموعه', 'نام عضو', 'زمان تهیه به وقت تهران', 'شناسه ثبت‌نام', 'دوره', 'نوبت', 'وضعیت ثبت‌نام', 'درس‌های منتشرشده', 'درس‌های تکمیل‌شده خوداظهاری', 'درصد تکمیل خوداظهاری', 'حضور ثبت‌شده', 'غیبت ثبت‌شده', 'غیبت موجه ثبت‌شده', 'وضعیت گواهی']);
                $generated = now('Asia/Tehran')->toIso8601String();
                $query = Enrollment::where('user_id', $member->id);
                $lastId = (clone $query)->max('id') ?? 0;
                $rows = 0;
                foreach ($query->where('id', '<=', $lastId)->with('course:id,title', 'cohort:id,title')->select('id', 'user_id', 'course_id', 'cohort_id', 'status')->lazyById(200) as $enrollment) {
                    $progress = app(LearningProgress::class)->summary($enrollment);
                    $attendance = Attendance::where('enrollment_id', $enrollment->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
                    $status = match ($enrollment->status) {
                        'active' => 'فعال', 'pending_payment' => 'در انتظار پرداخت', 'expired' => 'منقضی‌شده', 'cancelled' => 'لغوشده', default => $enrollment->status,
                    };
                    $certificate = app(Certification::class)->status($enrollment);
                    $certificate = match ($certificate) {
                        'none' => 'درخواستی ثبت نشده', 'pending' => 'در انتظار بررسی', 'issued' => 'صادرشده', 'revoked' => 'باطل‌شده', 'withdrawn' => 'انصراف از درخواست گواهی', 'rejected' => 'نیازمند اصلاح', default => $certificate,
                    };
                    $this->row($output, [$organization->name, $member->name, $generated, $enrollment->id, $enrollment->course->title, $enrollment->cohort->title, $status, $progress['total'], $progress['completed'], $progress['percent'], (int) ($attendance['present'] ?? 0), (int) ($attendance['absent'] ?? 0), (int) ($attendance['excused'] ?? 0), $certificate]);
                    $rows++;
                }
                AuditEvent::create(['actor_id' => $actor->id, 'action' => 'organization.learning_exported', 'subject_type' => 'user', 'subject_id' => $member->id, 'metadata' => ['organization_id' => $organization->id, 'dataset' => 'learning_summary_v1', 'rows' => $rows]]);
            }, 3);
            rewind($output);

            return ['stream' => $output, 'filename' => 'kardorost-organization-'.$organization->id.'-member-'.$member->id.'-learning.csv'];
        } catch (Throwable $exception) {
            fclose($output);
            throw $exception;
        }
    }
}
