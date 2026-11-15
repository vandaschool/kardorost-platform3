<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\CourseSurvey;
use App\Models\Enrollment;
use App\Models\SurveyPublication;
use App\Models\SurveyResponse;
use App\Services\CourseSurveys;
use App\Services\LearningAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class SurveyController extends Controller
{
    public function index(Request $request): Response
    {
        $cohorts = Enrollment::where('user_id', $request->user()->id)->where('status', 'active')->pluck('cohort_id');
        $offers = CourseSurvey::whereIn('cohort_id', $cohorts)->where('available', true)->with('cohort.course')->latest()->get()->map(function ($survey) {
            $publication = $survey->publications()->where('number', $survey->current_version)->first();

            return $publication ? ['id' => $publication->id, 'title' => $publication->definition['title'], 'description' => $publication->definition['description'], 'mode' => $publication->definition['mode'], 'minimum_responses' => $publication->definition['minimum_responses'], 'number' => $publication->number, 'course_title' => $survey->cohort->course->title, 'cohort_title' => $survey->cohort->title, 'receiving' => $publication->receiving(), 'opens_at' => $publication->opens_at, 'closes_at' => $publication->closes_at] : null;
        })->filter()->values();
        $responses = SurveyResponse::whereHas('enrollment', fn ($query) => $query->where('user_id', $request->user()->id))->with('publication.survey.cohort.course')->latest()->paginate(12)->through(fn ($response) => ['id' => $response->id, 'status' => $response->status, 'title' => $response->publication->definition['title'], 'number' => $response->publication->number, 'submitted_at' => $response->submitted_at, 'course_title' => $response->publication->survey->cohort->course->title]);

        return Inertia::render('Surveys', ['offers' => $offers, 'responses' => $responses]);
    }

    public function admin(): Response
    {
        $surveys = CourseSurvey::with('cohort.course')->latest()->paginate(15)->through(fn ($survey) => [...$survey->only('id', 'title', 'current_version', 'available'), 'cohort_title' => $survey->cohort->title, 'course_title' => $survey->cohort->course->title]);

        return Inertia::render('AdminSurveys', ['surveys' => $surveys, 'cohorts' => Cohort::with('course:id,title')->latest()->get()->map(fn ($cohort) => ['id' => $cohort->id, 'title' => $cohort->course->title.' · '.$cohort->title])]);
    }

    public function create(Request $request, CourseSurveys $service): RedirectResponse
    {
        $data = $request->validate(['cohort_id' => 'required|integer|exists:cohorts,id', 'title' => 'required|string|max:160']);
        $survey = $service->create($request->user(), Cohort::findOrFail($data['cohort_id']), $data['title']);

        return redirect()->route('admin.surveys.edit', $survey);
    }

    public function edit(CourseSurvey $survey): Response
    {
        return Inertia::render('SurveyEditor', ['survey' => $survey->only('id', 'title', 'draft', 'revision', 'available', 'current_version'), 'cohort_title' => $survey->cohort->title, 'course_title' => $survey->cohort->course->title, 'publications' => $survey->publications()->latest('number')->paginate(12)->through(fn ($publication) => [...$publication->only('id', 'number', 'accepting', 'revision', 'opens_at', 'closes_at'), 'mode' => $publication->definition['mode'], 'minimum_responses' => $publication->definition['minimum_responses'], 'receiving' => $publication->receiving()])]);
    }

    public function draft(Request $request, CourseSurvey $survey, CourseSurveys $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'definition' => 'required|array']);
        $service->draft($request->user(), $survey, (int) $data['revision'], $data['definition']);

        return back()->with('success', 'پیش‌نویس ذخیره شد؛ نسخه‌های منتشرشده تغییر نکردند.');
    }

    public function publish(Request $request, CourseSurvey $survey, CourseSurveys $service): RedirectResponse
    {
        $rules = ['revision' => 'required|integer|min:1', 'reviewed' => 'required|accepted', 'opens_at' => 'nullable|date', 'closes_at' => 'nullable|date'];
        if ($request->filled('opens_at')) {
            $rules['closes_at'] .= '|after:opens_at';
        }
        $data = $request->validate($rules);
        $service->publish($request->user(), $survey, (int) $data['revision'], $data['opens_at'] ?? null, $data['closes_at'] ?? null);

        return back()->with('success', 'نسخه ثابت نظرسنجی منتشر شد.');
    }

    public function availability(Request $request, CourseSurvey $survey, CourseSurveys $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'available' => 'required|boolean']);
        $service->availability($request->user(), $survey, (int) $data['revision'], (bool) $data['available']);

        return back()->with('success', 'وضعیت شروع پاسخ‌های تازه ثبت شد.');
    }

    public function receiving(Request $request, SurveyPublication $publication, CourseSurveys $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'accepting' => 'required|boolean', 'opens_at' => 'sometimes|nullable|date', 'closes_at' => 'sometimes|nullable|date']);
        $service->receiving($request->user(), $publication, (int) $data['revision'], (bool) $data['accepting'], array_intersect_key($data, array_flip(['opens_at', 'closes_at'])));

        return back()->with('success', 'وضعیت دریافت این نسخه ثبت شد.');
    }

    public function start(Request $request, SurveyPublication $publication, CourseSurveys $service): RedirectResponse
    {
        $response = $service->start($request->user(), $publication);

        return redirect()->route('surveys.response', $response);
    }

    public function response(Request $request, SurveyResponse $response): Response
    {
        abort_unless($response->enrollment->user_id === $request->user()->id, 403);
        $enrollment = $response->enrollment;
        $writable = $response->status === 'in_progress' && $enrollment->status === 'active' && $request->user()->hasRole('student') && $request->user()->phone_verified_at && $response->publication->receiving();

        return Inertia::render('SurveyResponse', ['response' => [...$response->only('id', 'status', 'revision', 'submitted_at'), 'answers' => $response->answers, 'writable' => (bool) $writable], 'publication' => ['number' => $response->publication->number, 'definition' => $response->publication->definition, 'opens_at' => $response->publication->opens_at, 'closes_at' => $response->publication->closes_at], 'course_title' => $response->publication->survey->cohort->course->title]);
    }

    public function save(Request $request, SurveyResponse $response, CourseSurveys $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'answers' => 'present|array|max:30']);
        $service->save($request->user(), $response, (int) $data['revision'], $data['answers'], $request->routeIs('surveys.submit'));

        return back()->with('success', $request->routeIs('surveys.submit') ? 'پاسخ نهایی ثبت شد.' : 'پاسخ نیمه‌کاره ذخیره شد.');
    }

    public function report(Request $request, SurveyPublication $publication, CourseSurveys $service): Response
    {
        $data = $request->validate(['question' => 'nullable|string|max:40', 'page' => 'nullable|integer|min:1|max:100000']);
        $key = $data['question'] ?? collect($publication->definition['questions'])->firstWhere('type', 'text')['key'] ?? null;
        $page = (int) ($data['page'] ?? 1);
        $report = $service->report($request->user(), $publication, $key, $page);
        $identified = $publication->definition['mode'] === 'identified';
        $rows = $identified && $report['unlocked'] ? $publication->responses()->where('status', 'submitted')->latest('submitted_at')->paginate(12)->through(fn ($response) => ['respondent_name' => $response->respondent_name, 'answers' => $response->answers, 'submitted_at' => $response->submitted_at]) : null;
        $comments = ! $identified ? new LengthAwarePaginator($report['comments'], $report['comment_count'], 12, $page, ['path' => $request->url(), 'query' => $request->query()]) : null;
        unset($report['comments'], $report['comment_count']);

        return Inertia::render('SurveyReport', ['role' => $request->user()->hasRole('admin') ? 'admin' : 'coach', 'publication' => [...$publication->only('id', 'number'), 'definition' => $publication->definition], 'course_title' => $publication->survey->cohort->course->title, 'cohort_title' => $publication->survey->cohort->title, 'report' => $report, 'identified_rows' => $rows, 'comments' => $comments, 'selected_question' => $key]);
    }

    public function teaching(Request $request, Cohort $cohort): Response
    {
        app(LearningAccess::class)->manage($request->user(), $cohort->course);
        $publications = SurveyPublication::whereHas('survey', fn ($query) => $query->where('cohort_id', $cohort->id))->latest()->paginate(15)->through(fn ($publication) => ['id' => $publication->id, 'number' => $publication->number, 'title' => $publication->definition['title'], 'mode' => $publication->definition['mode']]);

        return Inertia::render('TeachingSurveys', ['publications' => $publications, 'course_title' => $cohort->course->title, 'cohort_title' => $cohort->title, 'role' => $request->user()->hasRole('admin') ? 'admin' : 'coach']);
    }
}
