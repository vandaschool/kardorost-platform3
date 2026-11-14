<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\OrganizationSeat;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrganizationEnrolled;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationEnrollment
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['license' => $message]);
    }

    private function audit(User $actor, string $action, string $type, int $id, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => $metadata]);
    }

    public function configure(User $actor, Organization $organization, Cohort $cohort, array $data, ?OrganizationLicense $license = null): OrganizationLicense
    {
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);

        return DB::transaction(function () use ($actor, $organization, $cohort, $data, $license) {
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            if ($license) {
                $license = OrganizationLicense::whereKey($license->id)->lockForUpdate()->firstOrFail();
                if ($license->organization_id !== $organization->id || $license->cohort_id !== $cohort->id || $license->revision !== (int) $data['revision']) {
                    $this->fail('فرم سهمیه قدیمی است؛ صفحه را تازه کنید.');
                }
            } elseif (OrganizationLicense::where('organization_id', $organization->id)->where('cohort_id', $cohort->id)->exists()) {
                $this->fail('برای این سازمان و نوبت سهمیه وجود دارد؛ همان سهمیه را ویرایش کنید.');
            }
            $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $used = $license?->seats()->count() ?? 0;
            if ((int) $data['seat_limit'] < $used) {
                $this->fail('سقف سهمیه نمی‌تواند از تعداد صندلی‌های مصرف‌شده کمتر باشد.');
            }
            if ($data['active']) {
                if ($organization->status !== 'active' || ! $cohort->registration_open || ! $cohort->course->is_published || $data['expires_on'] < now()->toDateString()) {
                    $this->fail('برای سهمیه فعال، سازمان و ثبت‌نام دوره فعال و مهلت معتبر لازم‌اند.');
                }
                $room = app(CohortCapacity::class)->available($cohort, null, $license?->id);
                if ((int) $data['seat_limit'] - $used > $room) {
                    $this->fail('ظرفیت آزاد نوبت برای رزرو این سهمیه کافی نیست.');
                }
            }
            $attributes = ['seat_limit' => (int) $data['seat_limit'], 'expires_on' => $data['expires_on'], 'active' => (bool) $data['active'], 'contract_reference' => $data['contract_reference'], 'revision' => $license ? $license->revision + 1 : 1];
            if ($license) {
                $license->update($attributes);
            } else {
                $license = OrganizationLicense::create([...$attributes, 'organization_id' => $organization->id, 'cohort_id' => $cohort->id, 'created_by' => $actor->id]);
            }
            $this->audit($actor, 'organization_license.configured', 'organization_license', $license->id, ['seat_limit' => $license->seat_limit, 'active' => $license->active, 'expires_on' => $data['expires_on']]);

            return $license;
        }, 3);
    }

    public function enroll(User $actor, Organization $organization, OrganizationLicense $license, int $revision, array $memberIds): array
    {
        abort_unless($actor->is_active && $actor->hasRole('org_admin') && $license->organization_id === $organization->id, 403);
        if (! $memberIds || count($memberIds) > 100 || count($memberIds) !== count(array_unique($memberIds))) {
            $this->fail('بین یک تا صد عضو یکتا انتخاب کنید.');
        }
        sort($memberIds, SORT_NUMERIC);

        return DB::transaction(function () use ($actor, $organization, $license, $revision, $memberIds) {
            $cohort = Cohort::whereKey($license->cohort_id)->lockForUpdate()->firstOrFail();
            $license = OrganizationLicense::whereKey($license->id)->lockForUpdate()->firstOrFail();
            $org = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_unless($org->status === 'active' && $org->members()->where('users.id', $actor->id)->wherePivot('kind', 'manager')->exists(), 403);
            if ($license->revision !== $revision || ! $license->active || $license->expires_on->toDateString() < now()->toDateString() || ! $cohort->registration_open || ! $cohort->course->is_published) {
                $this->fail('سهمیه یا ثبت‌نام نوبت تغییر کرده یا مهلت آن تمام شده است.');
            }
            $members = $org->members()->whereIn('users.id', $memberIds)->orderBy('users.id')->lockForUpdate()->get();
            abort_unless($members->count() === count($memberIds), 403);
            $pending = [];
            $skipped = 0;
            foreach ($members as $member) {
                if (! $member->is_active || ! $member->phone_verified_at || ! $member->hasRole('student')) {
                    $this->fail('همه اعضای انتخاب‌شده باید دانشجوی فعال با شماره تأییدشده باشند.');
                }
                $e = Enrollment::where('user_id', $member->id)->where('cohort_id', $cohort->id)->lockForUpdate()->first();
                if ($e && $e->status === 'active') {
                    $skipped++;

                    continue;
                }
                if ($e && ($e->organization_license_id || ($e->status === 'pending_payment' && $e->expires_at?->isFuture()) || Payment::where('enrollment_id', $e->id)->where('status', '!=', 'refunded')->where(fn ($q) => $q->whereNotNull('captured_at')->orWhereIn('status', ['pending_review', 'review_required'])->orWhere(fn ($p) => $p->where('status', 'pending')->where(fn ($p) => $p->whereNull('attempt_expires_at')->orWhere('attempt_expires_at', '>', now()))))->exists())) {
                    $this->fail('یکی از اعضا رزرو یا پرداخت قبلیِ نیازمند تعیین تکلیف دارد؛ ابتدا آن را پیگیری کنید.');
                }
                $pending[] = [$member, $e];
            }
            $used = $license->seats()->count();
            $capacity = app(CohortCapacity::class);
            if (count($pending) > $license->seat_limit - $used) {
                $this->fail('مانده سهمیه برای همه اعضای انتخاب‌شده کافی نیست؛ هیچ ثبت‌نامی انجام نشد.');
            }
            if (count($pending) > $capacity->available($cohort, null, $license->id) || $capacity->occupied($cohort) + $capacity->reserved($cohort) > $cohort->capacity) {
                $this->fail('ظرفیت نوبت برای ثبت‌نام گروه کافی نیست.');
            }
            foreach ($pending as [$member,$e]) {
                $data = ['user_id' => $member->id, 'course_id' => $cohort->course_id, 'cohort_id' => $cohort->id, 'organization_license_id' => $license->id, 'status' => 'active', 'amount' => 0, 'payment_plan' => 'organization', 'expires_at' => null, 'billing_version' => $e ? $e->billing_version + 1 : 1];
                if ($e) {
                    Payment::where('enrollment_id', $e->id)->where('status', 'pending')->update(['status' => 'expired']);
                    $e->installments()->whereNull('paid_at')->whereNull('voided_at')->update(['voided_at' => now()]);
                    $e->update($data);
                } else {
                    $e = Enrollment::create($data);
                }
                app(CourseConsultationBenefits::class)->supersede($e);
                OrganizationSeat::create(['organization_license_id' => $license->id, 'enrollment_id' => $e->id, 'user_id' => $member->id, 'created_by' => $actor->id, 'member_name' => $member->name, 'member_phone' => $member->phone]);
                $this->audit($actor, 'organization.enrolled', 'enrollment', $e->id, ['organization_id' => $org->id, 'organization_license_id' => $license->id]);
                $member->notify(new OrganizationEnrolled($cohort->course->title, $org->name));
            }

            return ['enrolled' => count($pending), 'skipped' => $skipped];
        }, 3);
    }
}
