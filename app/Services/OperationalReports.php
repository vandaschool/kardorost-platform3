<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OperationalReports
{
    public const PURPOSES = ['course' => 'دوره', 'consultation' => 'مشاوره', 'organization' => 'قرارداد سازمان', 'package' => 'پکیج مشاوره'];

    public function filters(array $input): array
    {
        $today = CarbonImmutable::now('Asia/Tehran');
        $data = Validator::make($input + ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString(), 'dataset' => 'captures'], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'dataset' => 'required|in:captures,refunds,enrollments'])->validate();
        $from = $data['from'] ?? $today->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Asia/Tehran');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Asia/Tehran');
        if ($last->lt($start) || $start->diffInDays($last) > 365) {
            throw ValidationException::withMessages(['from' => 'بازه باید مرتب و حداکثر ۳۶۶ روز باشد.']);
        }

        return ['from' => $from, 'to' => $to, 'dataset' => $data['dataset'] ?? 'captures'];
    }

    /** Timestamp columns use the configured application timezone (Asia/Tehran). */
    private function between(Builder $query, string $column, array $filters): Builder
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], 'Asia/Tehran')->timezone(config('app.timezone'));
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], 'Asia/Tehran')->addDay()->timezone(config('app.timezone'));

        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }

    public function query(array $filters): Builder
    {
        return match ($filters['dataset']) {
            'captures' => $this->between(Payment::whereNotNull('captured_at'), 'captured_at', $filters),
            'refunds' => $this->between(Refund::where('refunds.status', 'completed')->whereNotNull('completed_at')->whereHas('payment', fn ($q) => $q->whereNotNull('captured_at')), 'refunds.completed_at', $filters),
            'enrollments' => $this->between(Enrollment::query(), 'created_at', $filters),
        };
    }

    private function purposeSql(): string
    {
        return "CASE WHEN payments.organization_invoice_id IS NOT NULL THEN 'organization' WHEN payments.package_purchase_id IS NOT NULL THEN 'package' WHEN payments.consultation_id IS NOT NULL THEN 'consultation' ELSE 'course' END";
    }

    public function summary(array $filters): array
    {
        $captures = $this->query([...$filters, 'dataset' => 'captures'])->selectRaw('DATE(captured_at) as day, '.$this->purposeSql().' as purpose, COUNT(*) as records, SUM(amount) as total')->groupByRaw('DATE(captured_at), '.$this->purposeSql())->get();
        $refunds = $this->query([...$filters, 'dataset' => 'refunds'])->join('payments', 'payments.id', '=', 'refunds.payment_id')->selectRaw('DATE(refunds.completed_at) as day, '.$this->purposeSql().' as purpose, COUNT(*) as records, SUM(refunds.amount) as total')->groupByRaw('DATE(refunds.completed_at), '.$this->purposeSql())->get();
        $totals = ['captured_amount' => 0, 'captured_count' => 0, 'refunded_amount' => 0, 'refunded_count' => 0];
        $purposes = [];
        foreach (self::PURPOSES as $key => $label) {
            $purposes[$key] = ['key' => $key, 'label' => $label, ...$totals];
        }
        $daily = [];
        for ($date = CarbonImmutable::parse($filters['from']); $date->toDateString() <= $filters['to']; $date = $date->addDay()) {
            $daily[$date->toDateString()] = ['date' => $date->toDateString(), ...$totals];
        }
        foreach (['captured' => $captures, 'refunded' => $refunds] as $kind => $rows) {
            foreach ($rows as $row) {
                $amount = (int) $row->total;
                $count = (int) $row->records;
                $totals[$kind.'_amount'] += $amount;
                $totals[$kind.'_count'] += $count;
                $purposes[$row->purpose][$kind.'_amount'] += $amount;
                $purposes[$row->purpose][$kind.'_count'] += $count;
                $daily[$row->day][$kind.'_amount'] += $amount;
                $daily[$row->day][$kind.'_count'] += $count;
            }
        }
        $enrollments = $this->query([...$filters, 'dataset' => 'enrollments'])->selectRaw('status, COUNT(*) as records')->groupBy('status')->get()->map(fn ($row) => ['status' => $row->status, 'count' => (int) $row->records])->all();
        $newUsers = $this->between(User::query(), 'created_at', $filters)->count();

        return ['totals' => [...$totals, 'net_cash_flow' => $totals['captured_amount'] - $totals['refunded_amount'], 'new_accounts' => $newUsers, 'new_enrollments' => array_sum(array_column($enrollments, 'count'))], 'purposes' => array_values($purposes), 'daily' => array_values($daily), 'enrollment_statuses' => $enrollments];
    }

    public function related(Builder $query, string $dataset): Builder
    {
        return match ($dataset) {
            'refunds' => $query->with('payment:id,user_id,enrollment_id,consultation_id,organization_invoice_id,package_purchase_id'),
            default => $query,
        };
    }

    public function record(Model $record, string $dataset): array
    {
        if ($dataset === 'enrollments') {
            return ['id' => $record->id, 'user_id' => $record->user_id, 'purpose' => $record->organization_license_id ? 'ثبت‌نام تحت پوشش سازمان' : 'ثبت‌نام شخصی', 'source_id' => $record->course_id, 'amount' => $record->amount, 'status' => $record->status, 'reference' => null, 'occurred_at' => $record->created_at->toIso8601String()];
        }
        $payment = $dataset === 'refunds' ? $record->payment : $record;
        $purpose = $payment->organization_invoice_id ? 'organization' : ($payment->package_purchase_id ? 'package' : ($payment->consultation_id ? 'consultation' : 'course'));

        return ['id' => $record->id, 'user_id' => $payment->user_id, 'purpose' => self::PURPOSES[$purpose], 'source_id' => $payment->organization_invoice_id ?? $payment->package_purchase_id ?? $payment->consultation_id ?? $payment->enrollment_id, 'amount' => $record->amount, 'status' => $record->status, 'reference' => $dataset === 'refunds' ? $record->transfer_reference : $record->reference, 'occurred_at' => ($dataset === 'refunds' ? $record->completed_at : $record->captured_at)->toIso8601String()];
    }
}
