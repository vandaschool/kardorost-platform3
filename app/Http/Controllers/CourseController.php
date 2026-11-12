<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $courses = Course::where('is_published', true)->when($q, fn ($query) => $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))->with(['cohorts' => fn ($q) => $q->where('registration_open', true)->with('installmentPlan')])->latest()->get();

        return Inertia::render('Courses', ['courses' => $courses, 'query' => $q]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:150', 'description' => 'required|string|max:5000', 'stage' => 'required|integer|between:1,3', 'price' => 'required|integer|min:0|max:1000000000', 'capacity' => 'required|integer|between:1,10000', 'format' => 'required|in:live,recorded,hybrid', 'is_published' => 'required|boolean']);
        DB::transaction(function () use ($data, $request) {
            $course = Course::create(['title' => $data['title'], 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6)), 'description' => $data['description'], 'stage' => $data['stage'], 'price' => (int) $data['price'] * 10, 'is_published' => $data['is_published']]);
            Cohort::create(['course_id' => $course->id, 'title' => 'نوبت اول', 'format' => $data['format'], 'capacity' => $data['capacity']]);
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'course.created', 'subject_type' => 'course', 'subject_id' => $course->id]);
        });

        return back()->with('success', 'دوره و نوبت اول آن ساخته شد.');
    }
}
