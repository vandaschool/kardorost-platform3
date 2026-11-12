<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Services\CohortCapacity;
use App\Services\OrganizationEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationLicenseController extends Controller
{
    public function admin(Request $r): Response
    {
        $licenses = OrganizationLicense::with(['organization', 'cohort.course'])->withCount('seats')->latest()->paginate(15)->through(fn ($l) => $this->data($l));

        return Inertia::render('AdminOrganizationLicenses', ['licenses' => $licenses, 'organizations' => Organization::where('status', 'active')->get(['id', 'name']), 'cohorts' => Cohort::with('course:id,title')->get()->map(fn ($c) => ['id' => $c->id, 'title' => $c->course->title.' · '.$c->title, 'capacity' => $c->capacity, 'available' => app(CohortCapacity::class)->available($c)])]);
    }

    public function data(OrganizationLicense $l): array
    {
        $used = $l->seats_count ?? $l->seats()->count();

        return [...$l->only('id', 'organization_id', 'cohort_id', 'seat_limit', 'active', 'revision', 'contract_reference'), 'expires_on' => $l->expires_on->toDateString(), 'organization_name' => $l->organization->name, 'course_title' => $l->cohort->course->title, 'cohort_title' => $l->cohort->title, 'used' => $used, 'remaining' => max(0, $l->seat_limit - $used), 'can_enroll' => $used < $l->seat_limit && $l->active && $l->organization->status === 'active' && $l->expires_on->toDateString() >= now()->toDateString() && $l->cohort->registration_open && $l->cohort->course->is_published];
    }

    private function rules(): array
    {
        return ['seat_limit' => 'required|integer|min:1|max:10000', 'expires_on' => 'required|date_format:Y-m-d', 'active' => 'required|boolean', 'contract_reference' => 'required|string|max:200', 'authorized' => 'required|accepted'];
    }

    public function store(Request $r, OrganizationEnrollment $service): RedirectResponse
    {
        $d = $r->validate([...$this->rules(), 'organization_id' => 'required|integer|exists:organizations,id', 'cohort_id' => 'required|integer|exists:cohorts,id']);
        $service->configure($r->user(), Organization::findOrFail($d['organization_id']), Cohort::findOrFail($d['cohort_id']), $d);

        return back()->with('success', 'سهمیه سازمان از ظرفیت نوبت کنار گذاشته شد. این ثبت، تأیید دریافت وجه نیست.');
    }

    public function update(Request $r, OrganizationLicense $license, OrganizationEnrollment $service): RedirectResponse
    {
        $d = $r->validate([...$this->rules(), 'revision' => 'required|integer|min:1']);
        $service->configure($r->user(), $license->organization, $license->cohort, $d, $license);

        return back()->with('success', 'سهمیه به‌روز شد؛ ثبت‌نام‌های مصرف‌شده حفظ شدند.');
    }

    private function selected(Request $r): Organization
    {
        $org = app(OrganizationController::class)->selected($r);
        abort_unless($org, 403);

        return $org;
    }

    public function index(Request $r): Response
    {
        $org = $this->selected($r);
        $licenses = OrganizationLicense::where('organization_id', $org->id)->with('organization', 'cohort.course')->withCount('seats')->latest()->paginate(15)->through(fn ($l) => $this->data($l));

        return Inertia::render('OrganizationLicenses', ['organization' => $org->only('id', 'name'), 'licenses' => $licenses]);
    }

    private function access(Request $r, OrganizationLicense $license): Organization
    {
        $org = $this->selected($r);
        abort_unless($license->organization_id === $org->id, 403);

        return $org;
    }

    public function show(Request $r, OrganizationLicense $license): Response
    {
        $org = $this->access($r, $license);
        $d = $r->validate(['q' => 'nullable|string|max:100']);
        $q = trim($d['q'] ?? '');
        $course = $license->cohort->course;
        $cohort = $license->cohort;
        $members = $org->members()->when($q, fn ($s) => $s->where(fn ($s) => $s->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%')->orWhere('phone', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%')))->with('roles')->orderBy('users.id')->paginate(100, ['users.id', 'name', 'phone', 'is_active', 'phone_verified_at'], 'members_page')->withQueryString();
        $existing = Enrollment::where('cohort_id', $cohort->id)->whereIn('user_id', $members->pluck('id'))->where('status', 'active')->pluck('user_id')->all();
        $members->through(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'eligible' => $u->is_active && $u->phone_verified_at && $u->hasRole('student'), 'enrolled' => in_array($u->id, $existing, true)]);
        $seats = $license->seats()->with('enrollment:id,status')->latest()->paginate(20, ['*'], 'seats_page')->withQueryString()->through(fn ($s) => $s->only('id', 'member_name', 'member_phone', 'created_at') + ['status' => $s->enrollment->status]);

        return Inertia::render('OrganizationEnrollment', ['organization' => $org->only('id', 'name'), 'license' => $this->data($license), 'members' => $members, 'seats' => $seats, 'query' => $q]);
    }

    public function enroll(Request $r, OrganizationLicense $license, OrganizationEnrollment $service): RedirectResponse
    {
        $org = $this->access($r, $license);
        $d = $r->validate(['revision' => 'required|integer|min:1', 'member_ids' => 'required|array|min:1|max:100', 'member_ids.*' => 'required|integer|distinct', 'confirmed' => 'required|accepted']);
        $result = $service->enroll($r->user(), $org, $license, $d['revision'], $d['member_ids']);

        return back()->with('success', $result['enrolled'].' عضو ثبت‌نام شد؛ '.$result['skipped'].' عضو از قبل ثبت‌نام فعال داشت و سهمیه دوباره مصرف نکرد.');
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[=+@\-\t\r\n]/u', ltrim($value)) ? "'".$value : $value;
    }

    public function export(Request $r, OrganizationLicense $license): StreamedResponse
    {
        $org = $this->access($r, $license);
        AuditEvent::create(['actor_id' => $r->user()->id, 'action' => 'organization.enrollments_exported', 'subject_type' => 'organization_license', 'subject_id' => $license->id, 'metadata' => ['organization_id' => $org->id]]);

        return response()->streamDownload(function () use ($license) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['نام ثبت‌شده عضو', 'موبایل ثبت‌شده', 'دوره', 'نوبت', 'وضعیت ثبت‌نام', 'زمان ثبت‌نام'], ',', '"', '');
            $license->seats()->with('enrollment')->orderBy('id')->chunkById(200, function ($seats) use ($out, $license) {
                foreach ($seats as $seat) {
                    fputcsv($out, array_map(fn ($v) => $this->csvCell((string) $v), [$seat->member_name, $seat->member_phone, $license->cohort->course->title, $license->cohort->title, $seat->enrollment->status, $seat->created_at->format('Y-m-d H:i')]), ',', '"', '');
                }
            });
            fclose($out);
        }, 'organization-enrollments-'.$license->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
