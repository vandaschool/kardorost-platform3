<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Cohort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingPlanController extends Controller
{
    public function update(Request $request, Cohort $cohort): RedirectResponse
    {
        $rules = ['expected_revision' => 'required|integer|min:0', 'installment_enabled' => 'required|boolean', 'manual_payment_enabled' => 'required|boolean', 'parts' => 'present|array|max:12'];
        if ($request->boolean('installment_enabled')) {
            $rules['parts.*.amount'] = 'required|integer|min:1|max:1000000000';
            $rules['parts.*.due_date'] = 'nullable|date_format:Y-m-d';
        }
        $data = $request->validate($rules);
        if ($data['manual_payment_enabled'] && ! config('platform.finance.transfer_destination')) {
            throw ValidationException::withMessages(['manual_payment_enabled' => 'اطلاعات حساب دریافت وجه هنوز توسط مدیریت پلتفرم تنظیم نشده است.']);
        }
        DB::transaction(function () use ($cohort, $data, $request) {
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $price = $cohort->course->price;
            if ($cohort->billing_revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['parts' => 'تنظیمات مالی توسط مدیر دیگری تغییر کرده است. صفحه را به‌روز کنید.']);
            }
            if ($data['installment_enabled']) {
                if (count($data['parts']) < 2 || $price === 0 || array_sum(array_map(fn ($part) => (int) $part['amount'] * 10, $data['parts'])) !== $price) {
                    throw ValidationException::withMessages(['parts' => 'حداقل دو قسط تعریف کنید؛ جمع مبلغ‌ها باید دقیقاً برابر قیمت دوره باشد.']);
                }
                $last = now()->toDateString();
                foreach ($data['parts'] as $i => $part) {
                    if ($i > 0) {
                        if (empty($part['due_date']) || $part['due_date'] < $last) {
                            throw ValidationException::withMessages(['parts.'.$i.'.due_date' => 'تاریخ اقساط بعدی باید مشخص و به ترتیب از امروز به بعد باشد.']);
                        }$last = $part['due_date'];
                    }
                }
            }
            $cohort->forceFill(['billing_revision' => $cohort->billing_revision + 1])->save();
            $cohort->update(['installment_enabled' => $data['installment_enabled'], 'manual_payment_enabled' => $data['manual_payment_enabled']]);
            $cohort->installmentPlan()->delete();
            if ($data['installment_enabled']) {
                foreach ($data['parts'] as $i => $part) {
                    $cohort->installmentPlan()->create(['sequence' => $i + 1, 'amount' => (int) $part['amount'] * 10, 'due_date' => $i === 0 ? now()->toDateString() : $part['due_date']]);
                }
            }
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'cohort.billing_plan_updated', 'subject_type' => 'cohort', 'subject_id' => $cohort->id]);
        });

        return back()->with('success', 'تنظیمات مالی ذخیره شد. قرارداد و اقساط ثبت‌نام‌های قبلی تغییر نکرد.');
    }
}
