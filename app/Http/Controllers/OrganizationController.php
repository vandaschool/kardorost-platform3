<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\LearningProgress;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function selected(Request $request): ?Organization
    {
        return $request->user()->organizations()->wherePivot('kind', 'manager')->where('status', 'active')->when($request->session()->has('organization_id'), fn ($q) => $q->where('organizations.id', $request->session()->get('organization_id')))->first();
    }

    public function index(Request $request): Response
    {
        $org = $this->selected($request);

        return Inertia::render('Organization', ['organization' => $org, 'organizations' => $request->user()->organizations()->wherePivot('kind', 'manager')->where('status', 'active')->get(['organizations.id', 'name']), 'members' => $org?->members()->get(['users.id', 'name', 'phone', 'is_active']) ?? []]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['organization_id' => 'required|integer']);
        abort_unless($request->user()->organizations()->wherePivot('kind', 'manager')->where('organizations.id', $data['organization_id'])->where('status', 'active')->exists(), 403);
        $request->session()->put('organization_id', $data['organization_id']);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'type' => 'required|in:university,company', 'phone' => 'required|string|max:30']);
        $manager = User::where('phone', Phone::normalize($data['phone']))->where('is_active', true)->first();
        if (! $manager) {
            throw ValidationException::withMessages(['phone' => 'مدیر ابتدا باید ثبت‌نام کند و شماره‌اش را تأیید کند.']);
        }
        DB::transaction(function () use ($data, $manager, $request) {
            $org = Organization::create(['name' => $data['name'], 'type' => $data['type']]);
            $org->members()->attach($manager->id, ['kind' => 'manager']);
            $manager->roles()->syncWithoutDetaching([Role::where('key', 'org_admin')->value('id')]);
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'organization.created', 'subject_type' => 'organization', 'subject_id' => $org->id]);
        });

        return back()->with('success', 'سازمان ساخته و مدیر آن تعیین شد.');
    }

    public function addMember(Request $request): RedirectResponse
    {
        $org = $this->selected($request);
        abort_unless($org, 403);
        $data = $request->validate(['phone' => 'required|string|max:30']);
        $user = User::where('phone', Phone::normalize($data['phone']))->where('is_active', true)->first();
        if (! $user) {
            throw ValidationException::withMessages(['phone' => 'این عضو ابتدا باید در کاردرست ثبت‌نام کند.']);
        }
        DB::transaction(function () use ($org, $user, $request) {
            $org->members()->syncWithoutDetaching([$user->id]);
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'organization.member_added', 'subject_type' => 'user', 'subject_id' => $user->id, 'metadata' => ['organization_id' => $org->id]]);
        });

        return back()->with('success', 'عضو به سازمان اضافه شد.');
    }

    public function report(Request $request, User $member): Response
    {
        $org = $this->selected($request);
        abort_unless($org && $org->members()->where('users.id', $member->id)->exists(), 403);
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'organization.report_viewed', 'subject_type' => 'user', 'subject_id' => $member->id, 'metadata' => ['organization_id' => $org->id]]);

        return Inertia::render('Member', ['member' => $member->only('id', 'name', 'phone'), 'organization' => $org, 'assessmentReports' => AssessmentAttempt::where('user_id', $member->id)->where('status', 'completed')->with('edition')->latest('completed_at')->get()->map(fn ($a) => ['id' => $a->id, 'title' => $a->edition->definition['title'], 'number' => $a->edition->number, 'completed_at' => $a->completed_at]), 'enrollments' => Enrollment::where('user_id', $member->id)->with('course', 'cohort')->get(), 'learningReports' => Enrollment::where('user_id', $member->id)->get()->mapWithKeys(fn ($e) => [$e->id => ['progress' => app(LearningProgress::class)->summary($e), 'attendance' => Attendance::where('enrollment_id', $e->id)->get(['live_session_id', 'status']), 'submissions' => Submission::where('enrollment_id', $e->id)->with('assignment:id,title,max_score', 'reviews')->latest('version')->get()->values()->map(fn ($s) => ['id' => $s->id, 'title' => $s->assignment->title, 'max_score' => $s->assignment->max_score, 'status' => $s->status, 'score' => $s->score, 'feedback' => $s->feedback, 'version' => $s->version, 'body' => $s->body, 'file_name' => $s->file_name, 'download_url' => $s->file_path ? route('submissions.file', $s) : null, 'reviews' => $s->reviews->map(fn ($r) => $r->only('id', 'revision', 'status', 'score', 'feedback', 'created_at'))])]])]);
    }
}
