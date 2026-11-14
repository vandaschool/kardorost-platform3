<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\EnrollmentConsultationBenefit;
use App\Services\CourseConsultationBenefits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseBenefitController extends Controller
{
    public function update(Request $request, Cohort $cohort, CourseConsultationBenefits $benefits): RedirectResponse
    {
        $data = $request->validate([
            'revision' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'coach_id' => 'exclude_unless:enabled,true|required|integer|exists:users,id',
            'title' => 'exclude_unless:enabled,true|required|string|max:160',
            'sessions' => 'exclude_unless:enabled,true|required|integer|between:1,100',
            'session_minutes' => 'exclude_unless:enabled,true|required|integer|between:15,180',
            'valid_days' => 'exclude_unless:enabled,true|required|integer|between:1,365',
            'activation' => 'exclude_unless:enabled,true|required|in:first_payment,paid_in_full',
            'reviewed' => 'exclude_unless:enabled,true|required|accepted',
        ]);
        $benefits->configure($request->user(), $cohort, $data);

        return back()->with('success', 'شرایط مشاوره برای ثبت‌نام‌های بعدی ذخیره شد؛ قراردادهای قبلی تغییر نکردند.');
    }

    public function index(CourseConsultationBenefits $benefits): Response
    {
        $contracts = EnrollmentConsultationBenefit::with('enrollment.user:id,name,phone')->latest()->paginate(20)->through(fn ($contract) => [...$benefits->contractData($contract), 'billing_version' => $contract->billing_version, 'student' => $contract->enrollment->user->only('id', 'name', 'phone'), 'can_retry' => $contract->status === 'blocked' && $contract->billing_version === $contract->enrollment->billing_version]);

        return Inertia::render('CourseBenefits', ['contracts' => $contracts]);
    }

    public function retry(Request $request, EnrollmentConsultationBenefit $benefit, CourseConsultationBenefits $benefits): RedirectResponse
    {
        $contract = $benefits->retry($request->user(), $benefit);

        return back()->with('success', $contract->status === 'issued' ? 'سهمیه صادر شده است؛ صدور دوباره یا تمدید انجام نشد.' : 'وضعیت بررسی شد. صدور سهمیه همچنان به احراز شرایط قرارداد وابسته است.');
    }
}
