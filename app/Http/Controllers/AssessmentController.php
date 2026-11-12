<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AuditEvent;
use App\Services\AssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function index(Request $r): Response
    {
        $items = Assessment::where('available', true)->where('current_version', '>', 0)->with('versions')->latest()->get()->map(function ($a) use ($r) {
            $v = $a->versions->firstWhere('number', $a->current_version);
            $attempt = AssessmentAttempt::where('user_id', $r->user()->id)->where('assessment_version_id', $v->id)->first();

            return ['id' => $a->id, 'title' => $v->definition['title'], 'description' => $v->definition['description'], 'source' => $v->definition['source'], 'version' => $v->number, 'question_count' => count($v->definition['questions']), 'attempt_id' => $attempt?->id, 'status' => $attempt?->status];
        });
        $attempts = AssessmentAttempt::where('user_id', $r->user()->id)->with('edition')->latest()->paginate(12)->through(fn ($a) => ['id' => $a->id, 'title' => $a->edition->definition['title'], 'number' => $a->edition->number, 'status' => $a->status, 'answered' => count($a->answers), 'total' => count($a->edition->definition['questions']), 'completed_at' => $a->completed_at]);

        return Inertia::render('Assessments', ['assessments' => $items, 'attempts' => $attempts]);
    }

    public function start(Request $r, Assessment $assessment, AssessmentService $service): RedirectResponse
    {
        $r->validate(['acknowledged' => 'required|accepted']);
        $item = $service->start($r->user(), $assessment);

        return redirect()->route($item->status === 'completed' ? 'assessments.report' : 'assessments.attempt', $item);
    }

    public function attempt(Request $r, AssessmentAttempt $attempt): Response|RedirectResponse
    {
        abort_unless($r->user()->hasRole('student') && $attempt->user_id === $r->user()->id, 403);
        if ($attempt->status === 'completed') {
            return redirect()->route('assessments.report', $attempt);
        }
        $d = $r->validate(['page' => 'nullable|integer|min:1|max:30']);
        $definition = $attempt->edition->definition;
        $last = (int) ceil(count($definition['questions']) / 10);
        $page = min($last, $d['page'] ?? 1);
        $questions = array_slice($definition['questions'], ($page - 1) * 10, 10);

        return Inertia::render('AssessmentAttempt', ['attempt' => $attempt->only('id', 'revision'), 'title' => $definition['title'], 'instructions' => $definition['instructions'], 'source' => $definition['source'], 'number' => $attempt->edition->number, 'options' => $definition['options'], 'questions' => array_map(fn ($q) => ['key' => $q['key'], 'text' => $q['text']], $questions), 'answers' => array_intersect_key($attempt->answers, array_flip(array_column($questions, 'key'))), 'answered' => count($attempt->answers), 'total' => count($definition['questions']), 'page' => $page, 'last' => $last]);
    }

    public function save(Request $r, AssessmentAttempt $attempt, AssessmentService $service): RedirectResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:1', 'answers' => 'present|array|max:300', 'next_page' => 'required|integer|min:1|max:30']);
        $service->save($r->user(), $attempt, $d['revision'], $d['answers']);

        return redirect()->route('assessments.attempt', [$attempt, 'page' => $d['next_page']])->with('success', 'پاسخ‌ها ذخیره شدند.');
    }

    public function finish(Request $r, AssessmentAttempt $attempt, AssessmentService $service): RedirectResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:1']);
        $item = $service->finish($r->user(), $attempt, $d['revision']);

        return redirect()->route('assessments.report', $item);
    }

    public function report(Request $r, AssessmentAttempt $attempt): Response
    {
        $user = $r->user();
        $owner = $user->id === $attempt->user_id;
        $admin = $user->hasRole('admin');
        $organization = null;
        if (! $owner && ! $admin && $user->hasRole('org_admin')) {
            $organization = app(OrganizationController::class)->selected($r);
        }
        abort_unless($owner || $admin || ($organization && $organization->members()->where('users.id', $attempt->user_id)->exists()), 403);
        abort_unless($attempt->status === 'completed', 404);
        if (! $owner) {
            AuditEvent::create(['actor_id' => $user->id, 'action' => 'assessment.report_viewed', 'subject_type' => 'assessment_attempt', 'subject_id' => $attempt->id, 'metadata' => ['organization_id' => $organization?->id]]);
        }

        return Inertia::render('AssessmentReport', ['attempt' => $attempt->only('id', 'completed_at'), 'title' => $attempt->edition->definition['title'], 'source' => $attempt->edition->definition['source'], 'number' => $attempt->edition->number, 'result' => $attempt->result, 'student_name' => $attempt->user->name, 'role' => $admin ? 'admin' : ($organization ? 'org_admin' : $r->session()->get('panel_role', $user->roleKeys()[0] ?? 'student'))]);
    }

    public function admin(Request $r): Response
    {
        return Inertia::render('AdminAssessments', ['assessments' => Assessment::latest()->paginate(15)]);
    }

    public function create(Request $r): RedirectResponse
    {
        $d = $r->validate(['title' => 'required|string|max:160']);
        $a = Assessment::create(['title' => $d['title']]);
        AuditEvent::create(['actor_id' => $r->user()->id, 'action' => 'assessment.created', 'subject_type' => 'assessment', 'subject_id' => $a->id]);

        return redirect()->route('admin.assessments.edit', $a);
    }

    public function edit(Request $r, Assessment $assessment): Response
    {
        $reports = AssessmentAttempt::where('status', 'completed')->whereHas('edition', fn ($q) => $q->where('assessment_id', $assessment->id))->with(['edition', 'user:id,name'])->latest('completed_at')->paginate(15)->through(fn ($a) => ['id' => $a->id, 'student_name' => $a->user->name, 'number' => $a->edition->number, 'completed_at' => $a->completed_at]);

        return Inertia::render('AssessmentEditor', ['assessment' => $assessment, 'versions' => $assessment->versions()->orderByDesc('number')->get(['id', 'number', 'created_at']), 'reports' => $reports]);
    }

    public function draft(Request $r, Assessment $assessment, AssessmentService $service): RedirectResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:0', 'definition' => 'required|array']);
        $service->saveDraft($r->user(), $assessment, $d['revision'], $d['definition']);

        return back()->with('success', 'پیش‌نویس ذخیره شد.');
    }

    public function publish(Request $r, Assessment $assessment, AssessmentService $service): RedirectResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:0', 'reviewed' => 'required|accepted']);
        $service->publish($r->user(), $assessment, $d['revision']);

        return back()->with('success', 'نسخه تازه منتشر شد؛ نتایج و پاسخ‌های قبلی حفظ شدند.');
    }

    public function availability(Request $r, Assessment $assessment, AssessmentService $service): RedirectResponse
    {
        $d = $r->validate(['revision' => 'required|integer|min:0', 'available' => 'required|boolean']);
        $service->availability($r->user(), $assessment, $d['revision'], $d['available']);

        return back();
    }
}
