<?php

namespace App\Services;

use App\Models\OrganizationInvoice;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrganizationPaymentSchedule
{
    /** Input amounts are toman; stored schedules contain integer rials. */
    public function normalize(mixed $input, int $amount, string $finalDate): ?array
    {
        if ($input === null) {
            return null;
        }
        $data = Validator::make(['installments' => $input], [
            'installments' => 'required|array|list|min:1|max:24',
            'installments.*.due_date' => 'required|date_format:Y-m-d',
            'installments.*.amount' => 'required|integer|min:1|max:100000000000',
        ])->validate();
        $previous = null;
        $total = 0;
        $items = [];
        foreach ($data['installments'] as $index => $row) {
            if ($previous !== null && strcmp($row['due_date'], $previous) <= 0) {
                throw ValidationException::withMessages(['invoice' => 'سررسیدهای اقساط باید بدون تکرار و از زودترین به دیرترین مرتب باشند.']);
            }
            $previous = $row['due_date'];
            $value = (int) $row['amount'] * 10;
            $total += $value;
            $items[] = ['sequence' => $index + 1, 'due_date' => $row['due_date'], 'amount' => $value];
        }
        if ($total !== $amount || $previous !== $finalDate) {
            throw ValidationException::withMessages(['invoice' => 'جمع اقساط باید برابر جمع صورتحساب و آخرین تاریخ برابر سررسید نهایی آن باشد.']);
        }

        return ['method' => 'oldest_due_first_v1', 'items' => $items];
    }

    /** Legacy invoices retain a single final due date without rewriting their ledger. */
    public function definition(OrganizationInvoice $invoice): array
    {
        return $invoice->payment_schedule ?? ['method' => 'oldest_due_first_v1', 'items' => [['sequence' => 1, 'due_date' => $invoice->due_date->toDateString(), 'amount' => $invoice->amount]]];
    }

    /** This is a projection of verified invoice allocations, never a second payment ledger. */
    public function summary(OrganizationInvoice $invoice, int $paid): array
    {
        $schedule = $this->definition($invoice);
        $today = now()->timezone('Asia/Tehran')->toDateString();
        $available = max(0, $paid);
        $overdue = 0;
        $dueToday = 0;
        $nextDate = null;
        $rows = [];
        foreach ($schedule['items'] as $item) {
            $covered = min($available, $item['amount']);
            $available -= $covered;
            $remaining = $item['amount'] - $covered;
            $status = match (true) {
                $invoice->status === 'void' => 'void',
                $invoice->status === 'draft' => 'draft',
                $remaining === 0 => 'paid',
                $item['due_date'] < $today => 'overdue',
                $item['due_date'] === $today => 'due',
                default => 'upcoming',
            };
            if ($status === 'overdue') {
                $overdue += $remaining;
            }
            if ($status === 'due') {
                $dueToday += $remaining;
            }
            if ($remaining > 0 && $nextDate === null && ! in_array($invoice->status, ['draft', 'void'], true)) {
                $nextDate = $item['due_date'];
            }
            $rows[] = [...$item, 'paid_amount' => $covered, 'remaining_amount' => $remaining, 'status' => $status];
        }

        return ['method' => $schedule['method'], 'items' => $rows, 'overdue_amount' => $overdue, 'due_today_amount' => $dueToday, 'next_due_date' => $nextDate];
    }
}
