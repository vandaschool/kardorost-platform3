<?php

namespace App\Http\Controllers;

use App\Models\CareerResume;
use App\Models\ResumeCheck;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\Phone;
use App\Services\ResumeBuilder;
use App\Services\ResumeTextReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CareerController extends Controller
{
    private function role(Request $request): string
    {
        $role = $request->session()->get('panel_role', 'student');

        return $request->user()->hasRole($role) ? $role : ($request->user()->roleKeys()[0] ?? 'student');
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate(['view' => 'nullable|in:own,admin', 'state' => 'nullable|in:active,archived,all', 'phone' => 'nullable|string|max:30']);
        $admin = ($filters['view'] ?? 'own') === 'admin';
        abort_if($admin && ! $request->user()->hasRole('admin'), 403);
        $state = $filters['state'] ?? 'active';
        $ownerId = null;
        if ($admin && ! empty($filters['phone'])) {
            $ownerId = User::where('phone', Phone::normalize($filters['phone']))->value('id') ?? -1;
        }
        $resumes = CareerResume::with('owner:id,name')->when(! $admin, fn ($q) => $q->where('user_id', $request->user()->id))->when($ownerId, fn ($q) => $q->where('user_id', $ownerId))->when($state !== 'all', fn ($q) => $q->where('archived', $state === 'archived'))->latest('updated_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($resume) => [...$resume->only('id', 'revision', 'current_version', 'archived', 'updated_at'), 'title' => $resume->title, 'owner_name' => $admin ? $resume->owner->name : null]);

        return Inertia::render('CareerResumes', ['resumes' => $resumes, 'filters' => [...$filters, 'view' => $admin ? 'admin' : 'own', 'state' => $state], 'role' => $this->role($request), 'admin' => $request->user()->hasRole('admin'), 'limit' => ResumeBuilder::MAX_RESUMES]);
    }

    public function create(Request $request, ResumeBuilder $builder): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:100']);
        $resume = $builder->create($request->user(), $data['title']);

        return redirect()->route('career.edit', $resume)->with('success', 'پیش‌نویس خصوصی رزومه ساخته شد.');
    }

    public function edit(Request $request, CareerResume $resume, ResumeBuilder $builder): Response
    {
        $builder->authorize($request->user(), $resume);
        $versions = $resume->versions()->latest('number')->paginate(10)->through(fn ($version) => $version->only('id', 'number', 'created_at'));

        return Inertia::render('CareerResumeEditor', ['resume' => [...$resume->only('id', 'revision', 'current_version', 'archived'), 'title' => $resume->title, 'draft' => $resume->draft, 'owner_name' => $resume->owner->name], 'versions' => $versions, 'role' => $this->role($request)]);
    }

    public function save(Request $request, CareerResume $resume, ResumeBuilder $builder): RedirectResponse
    {
        $builder->authorize($request->user(), $resume);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'title' => 'required|string|max:100', 'definition' => 'required|array']);
        $builder->save($request->user(), $resume, $data['revision'], $data['title'], $data['definition']);

        return back()->with('success', 'پیش‌نویس ذخیره شد؛ نسخه‌های چاپی قبلی حفظ شدند.');
    }

    public function snapshot(Request $request, CareerResume $resume, ResumeBuilder $builder): RedirectResponse
    {
        $builder->authorize($request->user(), $resume);
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $version = $builder->snapshot($request->user(), $resume, $data['revision']);

        return redirect()->route('career.versions.show', ['resume' => $resume, 'version' => $version]);
    }

    public function archive(Request $request, CareerResume $resume, ResumeBuilder $builder): RedirectResponse
    {
        $builder->authorize($request->user(), $resume);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'archived' => 'required|boolean']);
        $builder->archive($request->user(), $resume, $data['revision'], $data['archived']);

        return back()->with('success', $data['archived'] ? 'رزومه بایگانی شد؛ نسخه‌های قبلی قابل مشاهده‌اند.' : 'رزومه بازیابی شد.');
    }

    private function versionAccess(Request $request, CareerResume $resume, ResumeVersion $version, ResumeBuilder $builder): void
    {
        $builder->authorize($request->user(), $resume);
        abort_unless($version->career_resume_id === $resume->id, 404);
    }

    public function version(Request $request, CareerResume $resume, ResumeVersion $version, ResumeBuilder $builder): Response
    {
        $this->versionAccess($request, $resume, $version, $builder);

        return Inertia::render('CareerResumeVersion', ['resume' => ['id' => $resume->id, 'title' => $resume->title], 'version' => [...$version->only('id', 'number', 'created_at'), 'definition' => $version->definition], 'checks' => $version->checks()->latest('id')->paginate(10)->through(fn ($check) => $check->only('id', 'method', 'created_at')), 'role' => $this->role($request)]);
    }

    public function print(Request $request, CareerResume $resume, ResumeVersion $version, ResumeBuilder $builder): HttpResponse
    {
        $this->versionAccess($request, $resume, $version, $builder);

        return response()->view('resume', ['definition' => $version->definition, 'version' => $version->number])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function check(Request $request, CareerResume $resume, ResumeVersion $version, ResumeBuilder $builder, ResumeTextReview $review): RedirectResponse
    {
        $this->versionAccess($request, $resume, $version, $builder);
        $data = $request->validate(['job_text' => 'nullable|string|max:12000']);
        $check = $review->check($request->user(), $version, $data['job_text'] ?? '');

        return redirect()->route('career.checks.show', $check);
    }

    public function report(Request $request, ResumeCheck $check, ResumeBuilder $builder): Response
    {
        $builder->authorize($request->user(), $check->version->resume);

        return Inertia::render('CareerResumeReview', ['resume' => ['id' => $check->version->resume->id, 'title' => $check->version->resume->title], 'version' => $check->version->only('id', 'number'), 'check' => [...$check->only('id', 'method', 'created_at'), 'job_text' => $check->job_text, 'report' => $check->report], 'role' => $this->role($request)]);
    }
}
