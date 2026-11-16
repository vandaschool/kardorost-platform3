<?php

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssessmentReportExports
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['report' => 'گزارش نهایی ذخیره‌شده برای خروجی معتبر نیست؛ با پشتیبانی پیگیری کنید.']);
    }

    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->fail();
        }

        return preg_match('/^[\s\p{Z}\x{FEFF}\x{200B}]*[=+@\-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }

    /** @param resource $output */
    private function row($output, array $values): void
    {
        if (fputcsv($output, array_map($this->cell(...), $values), ',', '"', '') === false) {
            $this->fail();
        }
    }

    private function dimensions(mixed $result): array
    {
        if (! is_array($result) || ($result['engine'] ?? null) !== 'likert_sum_v1' || ! is_array($result['dimensions'] ?? null)
            || ! array_is_list($result['dimensions']) || count($result['dimensions']) < 1 || count($result['dimensions']) > 20) {
            $this->fail();
        }
        $keys = [];
        foreach ($result['dimensions'] as $dimension) {
            if (! is_array($dimension) || ! is_string($dimension['key'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $dimension['key']) || in_array($dimension['key'], $keys, true)
                || ! is_string($dimension['title'] ?? null) || mb_strlen($dimension['title']) > 120 || trim($dimension['title']) === '') {
                $this->fail();
            }
            foreach (['sum', 'count', 'min', 'max'] as $field) {
                if (! is_int($dimension[$field] ?? null)) {
                    $this->fail();
                }
            }
            $percent = $dimension['range_percent'] ?? null;
            if ($dimension['count'] < 1 || $dimension['count'] > 300 || $dimension['min'] !== $dimension['count'] || $dimension['max'] !== $dimension['count'] * 5
                || $dimension['sum'] < $dimension['min'] || $dimension['sum'] > $dimension['max'] || (! is_int($percent) && ! is_float($percent))
                || ! is_finite((float) $percent) || abs($percent - round(($dimension['sum'] - $dimension['min']) / ($dimension['max'] - $dimension['min']) * 100, 1)) > 0.00001) {
                $this->fail();
            }
            $keys[] = $dimension['key'];
        }

        return $result['dimensions'];
    }

    /** @return array{stream: resource, filename: string} */
    public function prepare(User $actor, AssessmentAttempt $attempt, ?Organization $organization = null): array
    {
        $ownerId = (int) AssessmentAttempt::whereKey($attempt->id)->firstOrFail(['id', 'user_id'])->user_id;
        $mode = $actor->id === $ownerId ? 'owner' : ($actor->hasRole('admin') ? 'admin' : 'organization');
        if ($mode === 'organization') {
            abort_unless($organization && $actor->hasRole('org_admin'), 403);
        }
        $output = tmpfile();
        if ($output === false) {
            $this->fail();
        }
        try {
            DB::transaction(function () use ($actor, $attempt, $organization, $ownerId, $mode, $output): void {
                rewind($output);
                if (! ftruncate($output, 0)) {
                    $this->fail();
                }
                $organization = $mode === 'organization' ? Organization::whereKey($organization->id)->sharedLock()->firstOrFail() : null;
                $accounts = User::whereIn('id', [$actor->id, $ownerId])->orderBy('id')->sharedLock()->get()->keyBy('id');
                $actor = $accounts->get($actor->id);
                $owner = $accounts->get($ownerId);
                abort_unless($actor?->is_active && $owner, 403);
                if ($mode === 'admin') {
                    abort_unless($actor->hasRole('admin'), 403);
                } elseif ($mode === 'organization') {
                    abort_unless($organization->status === 'active' && $actor->hasRole('org_admin')
                        && $organization->members()->where('users.id', $actor->id)->wherePivot('kind', 'manager')->exists()
                        && $organization->members()->where('users.id', $ownerId)->exists(), 403);
                }
                $current = AssessmentAttempt::whereKey($attempt->id)->sharedLock()->firstOrFail(['id', 'user_id', 'assessment_version_id', 'status', 'result', 'completed_at']);
                abort_unless($current->user_id === $ownerId && ($mode !== 'owner' || $actor->id === $current->user_id), 403);
                abort_unless($current->status === 'completed' && $current->completed_at, 404);
                try {
                    $result = $current->result;
                } catch (Throwable) {
                    $this->fail();
                }
                $dimensions = $this->dimensions($result);
                $edition = $current->edition;
                $definition = $edition->definition;
                foreach (['title' => 160, 'source' => 2000] as $key => $maximum) {
                    if (! is_string($definition[$key] ?? null) || mb_strlen($definition[$key]) > $maximum || trim($definition[$key]) === '') {
                        $this->fail();
                    }
                }
                if (fwrite($output, "\xEF\xBB\xBF") !== 3) {
                    $this->fail();
                }
                $this->row($output, ['نام جاری صاحب گزارش', 'عنوان نسخه گزارش', 'شماره نسخه', 'زمان نهایی‌شدن به وقت تهران', 'روش امتیازدهی', 'منبع و روش اعلام‌شده', 'کلید بعد', 'عنوان بعد', 'امتیاز خام', 'تعداد پرسش', 'کمینه ممکن', 'بیشینه ممکن', 'درصد در بازه ممکن امتیاز', 'معنای درصد']);
                $common = [$owner->name, $definition['title'], $edition->number, $current->completed_at->timezone('Asia/Tehran')->toIso8601String(), $result['engine'], $definition['source']];
                foreach ($dimensions as $dimension) {
                    $this->row($output, [...$common, $dimension['key'], $dimension['title'], $dimension['sum'], $dimension['count'], $dimension['min'], $dimension['max'], $dimension['range_percent'], 'درصد بازه امتیاز است؛ رتبه جامعه، تشخیص یا تأیید اعتبار رسمی نیست.']);
                }
                AuditEvent::create(['actor_id' => $actor->id, 'action' => 'assessment.report_exported', 'subject_type' => 'assessment_attempt', 'subject_id' => $current->id, 'metadata' => ['organization_id' => $organization?->id, 'assessment_version_id' => $edition->id, 'dimensions' => count($dimensions), 'scope' => $mode]]);
            }, 3);
            rewind($output);

            return ['stream' => $output, 'filename' => 'kardorost-assessment-report-'.$attempt->id.'.csv'];
        } catch (Throwable $exception) {
            fclose($output);
            throw $exception;
        }
    }
}
