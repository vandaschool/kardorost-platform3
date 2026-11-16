<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\SurveyPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SurveyExports
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['survey' => $message]);
    }

    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->fail('متن نامعتبر است؛ خروجی ساخته نشد.');
        }

        return preg_match('/^[\s\p{Z}\x{FEFF}\x{200B}]*[=+@\-]|^[\t\r\n]/u', $text) ? "'".$text : $text;
    }

    /** @param resource $output */
    private function row($output, array $values): void
    {
        if (fputcsv($output, array_map($this->cell(...), $values), ',', '"', '') === false) {
            $this->fail('فضای موقت برای ساخت خروجی کافی نیست.');
        }
    }

    /** @return array{stream: resource, filename: string} */
    public function prepare(User $actor, SurveyPublication $publication, string $dataset, ?string $questionKey = null): array
    {
        $output = tmpfile();
        if ($output === false) {
            $this->fail('فایل خصوصی موقت ساخته نشد.');
        }
        try {
            DB::transaction(function () use ($actor, $publication, $dataset, $questionKey, $output): void {
                rewind($output);
                ftruncate($output, 0);
                $cohortId = $publication->survey->cohort_id;
                $courseId = $publication->survey->cohort->course_id;
                $course = Course::whereKey($courseId)->sharedLock()->firstOrFail();
                $cohort = Cohort::whereKey($cohortId)->sharedLock()->firstOrFail();
                $current = SurveyPublication::whereKey($publication->id)->sharedLock()->firstOrFail();
                $user = User::whereKey($actor->id)->sharedLock()->firstOrFail();
                app(LearningAccess::class)->manage($user, $course);
                $definition = $current->definition;
                if (! in_array($dataset, ['summary', 'comments', 'responses'], true)
                    || ($dataset === 'responses' && $definition['mode'] !== 'identified')
                    || ($dataset === 'comments' && $definition['mode'] !== 'aggregate')) {
                    $this->fail('نوع خروجی با شیوه اعلام‌شده این نظرسنجی سازگار نیست.');
                }
                $question = collect($definition['questions'])->firstWhere('key', $questionKey);
                if ($dataset === 'comments' && (! $question || $question['type'] !== 'text')) {
                    $this->fail('برای خروجی متن، یک پرسش متنی معتبر انتخاب کنید.');
                }
                $report = app(CourseSurveys::class)->report($user, $current, $dataset === 'comments' ? $questionKey : null);
                if (! $report['unlocked']) {
                    $this->fail('هنوز حداقل پاسخ نهایی برای ساخت خروجی ثبت نشده است.');
                }
                if ($dataset === 'comments' && ! collect($report['questions'])->firstWhere('key', $questionKey)['unlocked']) {
                    $this->fail('این پرسش هنوز حداقل پاسخ لازم برای خروجی متن را ندارد.');
                }
                fwrite($output, "\xEF\xBB\xBF");
                $common = [$definition['title'], $current->number, $course->title, $cohort->title];
                $headers = ['عنوان نظرسنجی', 'نسخه', 'دوره', 'نوبت'];
                $rows = 0;
                if ($dataset === 'summary') {
                    $this->row($output, [...$headers, 'پاسخ نهایی', 'کلید پرسش', 'پرسش', 'نوع', 'تعداد پاسخ پرسش', 'نمایش نتیجه', 'میانگین', ...array_map(fn ($label, $index) => 'گزینه '.($index + 1).': '.$label, $definition['options'], array_keys($definition['options']))]);
                    foreach ($report['questions'] as $stat) {
                        $values = $stat['unlocked'] && $stat['type'] === 'rating' ? array_column($stat['distribution'], 'count') : array_fill(0, 5, '');
                        $this->row($output, [...$common, $report['count'], $stat['key'], $stat['text'], $stat['type'], $stat['count'], $stat['unlocked'] ? 'مجاز' : 'کمتر از حداقل پاسخ', $stat['average'] ?? '', ...$values]);
                        $rows++;
                    }
                } elseif ($dataset === 'comments') {
                    $this->row($output, [...$headers, 'کلید پرسش', 'پرسش', 'پاسخ متنی بدون شناسه حساب']);
                    foreach ($current->responses()->where('status', 'submitted')->lazyById(200) as $response) {
                        $answer = $response->answers[$questionKey] ?? null;
                        if ($answer !== null) {
                            $this->row($output, [...$common, $questionKey, $question['text'], $answer]);
                            $rows++;
                        }
                    }
                } else {
                    $this->row($output, [...$headers, 'نام هنگام ثبت پاسخ', 'زمان ثبت به وقت تهران', ...array_map(fn ($question) => $question['key'].': '.$question['text'], $definition['questions'])]);
                    foreach ($current->responses()->where('status', 'submitted')->lazyById(200) as $response) {
                        $answers = $response->answers;
                        $this->row($output, [...$common, $response->respondent_name, $response->submitted_at->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s'), ...array_map(fn ($question) => $answers[$question['key']] ?? '', $definition['questions'])]);
                        $rows++;
                    }
                }
                AuditEvent::create(['actor_id' => $user->id, 'action' => 'survey.exported', 'subject_type' => 'survey_publication', 'subject_id' => $current->id, 'metadata' => ['dataset' => $dataset, 'question' => $dataset === 'comments' ? $questionKey : null, 'rows' => $rows]]);
            }, 3);
            rewind($output);

            return ['stream' => $output, 'filename' => 'kardorost-survey-'.$publication->id.'-v'.$publication->number.'-'.$dataset.'.csv'];
        } catch (Throwable $exception) {
            fclose($output);
            throw $exception;
        }
    }
}
