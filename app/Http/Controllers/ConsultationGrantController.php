<?php

namespace App\Http\Controllers;

use App\Models\ConsultationGrant;
use App\Models\User;
use App\Services\ConsultationPackages;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationGrantController extends Controller
{
    public function data(ConsultationGrant $grant, bool $admin = false): array
    {
        return [...$grant->only('id', 'title', 'coach_id', 'total_sessions', 'session_minutes', 'active', 'revision', 'cancellation_policy'), 'valid_until' => $grant->valid_until->toDateString(), 'expired' => $grant->valid_until->toDateString() < now()->toDateString(), 'source_kind' => $grant->courseBenefit ? 'course' : 'manual', 'source_course' => $grant->courseBenefit?->definition['course_title'] ?? null, 'remaining' => $grant->remaining(), 'coach_name' => $grant->coach->name, ...($admin ? ['student' => $grant->student->only('id', 'name', 'phone'), 'source_reference' => $grant->source_reference] : [])];
    }

    public function index(Request $request): Response
    {
        $admin = $request->routeIs('admin.consultation-grants');
        $grants = ConsultationGrant::with('coach:id,name', 'student:id,name,phone', 'courseBenefit')->withCount(['uses as spent_count' => fn ($query) => $query->whereNull('released_at')])->when(! $admin, fn ($query) => $query->where('student_id', $request->user()->id))->latest()->paginate(12)->through(fn ($grant) => $this->data($grant, $admin));

        return Inertia::render('ConsultationGrants', ['admin' => $admin, 'grants' => $grants, 'coaches' => $admin ? User::where('is_active', true)->whereHas('roles', fn ($query) => $query->where('key', 'coach'))->get(['id', 'name']) : []]);
    }

    public function create(Request $request, ConsultationPackages $packages): RedirectResponse
    {
        $data = $request->validate(['student_phone' => 'required|string|max:30', 'coach_id' => 'required|integer|exists:users,id', 'title' => 'required|string|max:160', 'source_reference' => 'required|string|min:3|max:200', 'total_sessions' => 'required|integer|min:1|max:100', 'session_minutes' => 'required|integer|min:15|max:180', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:today', 'source_reviewed' => 'required|accepted']);
        $student = User::where('phone', Phone::normalize($data['student_phone']))->first();
        if (! $student) {
            throw ValidationException::withMessages(['student_phone' => 'دانشجو ابتدا باید با این شماره ثبت‌نام و موبایلش را تأیید کند.']);
        }
        $packages->configure($request->user(), $student, User::findOrFail($data['coach_id']), $data);

        return back()->with('success', 'سهمیه با منبع بررسی‌شده ثبت شد. این فرم پرداخت یا خرید تازه ثبت نمی‌کند.');
    }

    public function update(Request $request, ConsultationGrant $grant, ConsultationPackages $packages): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'total_sessions' => 'required|integer|min:1|max:100', 'valid_until' => 'required|date_format:Y-m-d', 'active' => 'required|boolean']);
        $packages->configure($request->user(), $grant->student, $grant->coach, $data, $grant);

        return back()->with('success', 'سهمیه به‌روز شد؛ رزروهای قطعی قبلی تغییر نکردند.');
    }
}
