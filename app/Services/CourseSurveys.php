<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseSurvey;
use App\Models\Enrollment;
use App\Models\SurveyPublication;
use App\Models\SurveyResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CourseSurveys
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['survey' => $message]);
    }

    private function admin(User $user): void
    {
        abort_unless($user->is_active && $user->hasRole('admin'), 403);
    }

    private function audit(User $user, string $action, string $type, int $id): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id]);
    }

    private function parents(Cohort $cohort): void
    {
        Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
        Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
    }

    public function definition(array $input): array
    {
        $data = Validator::make($input, ['title' => 'required|string|max:160', 'description' => 'required|string|max:3000', 'mode' => 'required|in:identified,aggregate', 'minimum_responses' => 'required|integer|between:1,50', 'options' => 'required|array|size:5', 'options.*' => 'required|string|max:100', 'questions' => 'required|array|min:1|max:30', 'questions.*.key' => ['required', 'string', 'max:40', 'distinct', 'regex:/^[a-z][a-z0-9_]*$/D'], 'questions.*.text' => 'required|string|max:1000', 'questions.*.type' => 'required|in:rating,text', 'questions.*.required' => 'required|boolean'])->validate();
        if (! array_is_list($data['options']) || ! array_is_list($data['questions']) || ($data['mode'] === 'aggregate' && $data['minimum_responses'] < 3)) {
            $this->fail('فهرست مرتب و حداقل سه پاسخ برای گزارش بدون نمایش نام لازم است.');
        }

        return ['title' => $data['title'], 'description' => $data['description'], 'mode' => $data['mode'], 'minimum_responses' => $data['mode'] === 'identified' ? 1 : (int) $data['minimum_responses'], 'options' => array_values($data['options']), 'questions' => array_map(fn ($question) => ['key' => $question['key'], 'text' => $question['text'], 'type' => $question['type'], 'required' => (bool) $question['required']], $data['questions']), 'engine' => 'course_feedback_v1'];
    }

    public function create(User $user, Cohort $cohort, string $title): CourseSurvey
    {
        $this->admin($user);

        return DB::transaction(function () use ($user, $cohort, $title) {
            $this->parents($cohort);
            $survey = CourseSurvey::create(['cohort_id' => $cohort->id, 'title' => $title]);
            $this->audit($user, 'survey.created', 'course_survey', $survey->id);

            return $survey;
        }, 3);
    }

    public function draft(User $user, CourseSurvey $survey, int $revision, array $input): void
    {
        $this->admin($user);
        $definition = $this->definition($input);
        DB::transaction(function () use ($user, $survey, $revision, $definition) {
            $this->parents($survey->cohort);
            $survey = CourseSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            if ($survey->revision !== $revision) {
                $this->fail('پیش‌نویس تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $survey->update(['title' => $definition['title'], 'draft' => $definition, 'revision' => $revision + 1]);
            $this->audit($user, 'survey.draft_saved', 'course_survey', $survey->id);
        }, 3);
    }

    public function publish(User $user, CourseSurvey $survey, int $revision, ?string $opens, ?string $closes): SurveyPublication
    {
        $this->admin($user);

        return DB::transaction(function () use ($user, $survey, $revision, $opens, $closes) {
            $this->parents($survey->cohort);
            $survey = CourseSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            if ($survey->revision !== $revision || ! $survey->draft) {
                $this->fail('پیش‌نویس معتبر و نسخه فعلی لازم است.');
            }
            $definition = $this->definition($survey->draft);
            $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($survey->publications()->where('number', $survey->current_version)->value('content_hash') === $hash) {
                $this->fail('محتوا نسبت به آخرین انتشار تغییر نکرده است.');
            }
            $opens = $opens ? CarbonImmutable::parse($opens)->setTimezone(config('app.timezone')) : null;
            $closes = $closes ? CarbonImmutable::parse($closes)->setTimezone(config('app.timezone')) : null;
            if ($closes && $closes->lte($opens ?? now())) {
                $this->fail('پایان دریافت باید پس از شروع باشد.');
            }
            $publication = $survey->publications()->create(['number' => $survey->current_version + 1, 'definition' => $definition, 'content_hash' => $hash, 'opens_at' => $opens, 'closes_at' => $closes, 'published_by' => $user->id]);
            $survey->update(['current_version' => $publication->number, 'available' => true, 'revision' => $survey->revision + 1]);
            $this->audit($user, 'survey.published', 'survey_publication', $publication->id);

            return $publication;
        }, 3);
    }

    public function availability(User $user, CourseSurvey $survey, int $revision, bool $available): void
    {
        $this->admin($user);
        DB::transaction(function () use ($user, $survey, $revision, $available) {
            $this->parents($survey->cohort);
            $survey = CourseSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            if ($survey->revision !== $revision || ($available && ! $survey->current_version)) {
                $this->fail('نسخه معتبر انتشار و فرم تازه لازم است.');
            }
            $survey->update(['available' => $available, 'revision' => $revision + 1]);
            $this->audit($user, 'survey.availability_changed', 'course_survey', $survey->id);
        }, 3);
    }

    public function receiving(User $user, SurveyPublication $publication, int $revision, bool $accepting, array $schedule = []): void
    {
        $this->admin($user);
        DB::transaction(function () use ($user, $publication, $revision, $accepting, $schedule) {
            $this->parents($publication->survey->cohort);
            $publication = SurveyPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($publication->revision !== $revision) {
                $this->fail('وضعیت دریافت تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $dates = [];
            foreach (['opens_at', 'closes_at'] as $field) {
                $dates[$field] = array_key_exists($field, $schedule) ? ($schedule[$field] ? CarbonImmutable::parse($schedule[$field])->setTimezone(config('app.timezone')) : null) : $publication->$field;
            }
            if ($dates['opens_at'] && $dates['closes_at'] && $dates['closes_at']->lte($dates['opens_at'])) {
                $this->fail('پایان دریافت باید پس از شروع باشد.');
            }
            $before = $publication->only('accepting', 'opens_at', 'closes_at');
            $publication->update([...$dates, 'accepting' => $accepting, 'revision' => $revision + 1]);
            AuditEvent::create(['actor_id' => $user->id, 'action' => 'survey.receiving_changed', 'subject_type' => 'survey_publication', 'subject_id' => $publication->id, 'metadata' => ['before' => $before, 'after' => $publication->only('accepting', 'opens_at', 'closes_at')]]);
        }, 3);
    }

    private function student(User $user, Enrollment $enrollment): void
    {
        abort_unless($user->is_active && $user->phone_verified_at && $user->hasRole('student') && $enrollment->user_id === $user->id && $enrollment->status === 'active', 403);
    }

    public function start(User $user, SurveyPublication $publication): SurveyResponse
    {
        return DB::transaction(function () use ($user, $publication) {
            $survey = $publication->survey;
            $this->parents($survey->cohort);
            $survey = CourseSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $publication = SurveyPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::where('cohort_id', $survey->cohort_id)->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($enrollment, 403);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->student($user, $enrollment);
            $existing = SurveyResponse::where('survey_publication_id', $publication->id)->where('enrollment_id', $enrollment->id)->first();
            if ($existing) {
                return $existing;
            }
            if (! $survey->available || $survey->current_version !== $publication->number || ! $publication->receiving()) {
                $this->fail('شروع پاسخ به این نسخه فعال نیست.');
            }
            $response = SurveyResponse::create(['survey_publication_id' => $publication->id, 'enrollment_id' => $enrollment->id, 'answers' => []]);
            $this->audit($user, 'survey.started', 'survey_response', $response->id);

            return $response;
        }, 3);
    }

    private function answers(array $definition, array $existing, array $input, bool $final): array
    {
        $questions = collect($definition['questions'])->keyBy('key');
        foreach ($input as $key => $value) {
            $question = $questions->get($key);
            if (! $question) {
                $this->fail('شناسه پرسش معتبر نیست.');
            }
            if ($value === null || $value === '') {
                unset($existing[$key]);

                continue;
            }
            if ($question['type'] === 'rating') {
                if (! (is_int($value) || (is_string($value) && preg_match('/^[1-5]$/D', $value))) || (int) $value < 1 || (int) $value > 5) {
                    $this->fail('گزینه امتیاز باید یکی از اعداد یک تا پنج باشد.');
                }
                $existing[$key] = (int) $value;
            } else {
                if (! is_string($value) || mb_strlen($value) > 2000) {
                    $this->fail('پاسخ متنی باید حداکثر ۲۰۰۰ نویسه باشد.');
                }
                $value = trim($value);
                if ($value === '') {
                    unset($existing[$key]);
                } else {
                    $existing[$key] = $value;
                }
            }
        }
        if ($final) {
            foreach ($questions as $key => $question) {
                if ($question['required'] && ! array_key_exists($key, $existing)) {
                    $this->fail('پاسخ پرسش‌های الزامی را تکمیل کنید.');
                }
            }
            if (count($existing) === 0) {
                $this->fail('حداقل یک پاسخ لازم است.');
            }
        }

        return $existing;
    }

    public function save(User $user, SurveyResponse $response, int $revision, array $input, bool $final): void
    {
        abort_unless($response->enrollment->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $response, $revision, $input, $final) {
            $publication = $response->publication;
            $this->parents($publication->survey->cohort);
            $publication = SurveyPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::whereKey($response->enrollment_id)->lockForUpdate()->firstOrFail();
            $response = SurveyResponse::whereKey($response->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->student($user, $enrollment);
            if ($final && $response->status === 'submitted') {
                return;
            }
            if ($response->status !== 'in_progress' || $response->revision !== $revision || ! $publication->receiving()) {
                $this->fail('پاسخ نهایی شده، تغییر کرده یا مهلت دریافت بسته است.');
            }
            $answers = $this->answers($publication->definition, $response->answers, $input, $final);
            $response->update(['answers' => $answers, 'revision' => $revision + 1, 'status' => $final ? 'submitted' : 'in_progress', 'submitted_at' => $final ? now() : null, 'respondent_name' => $final && $publication->definition['mode'] === 'identified' ? $user->name : null]);
            if ($final) {
                $this->audit($user, 'survey.submitted', 'survey_response', $response->id);
            }
        }, 3);
    }

    public function report(User $user, SurveyPublication $publication, ?string $commentKey = null, int $page = 1): array
    {
        app(LearningAccess::class)->manage($user, $publication->survey->cohort->course);
        $definition = $publication->definition;
        $statistics = [];
        foreach ($definition['questions'] as $question) {
            $statistics[$question['key']] = [...$question, 'count' => 0, 'sum' => 0, 'distribution' => array_fill(1, 5, 0)];
        }
        if ($commentKey && (! isset($statistics[$commentKey]) || $statistics[$commentKey]['type'] !== 'text')) {
            $this->fail('پرسش متنی معتبر انتخاب کنید.');
        }
        $count = 0;
        $comments = [];
        $commentCount = 0;
        foreach ($publication->responses()->where('status', 'submitted')->orderBy('id')->cursor() as $response) {
            $count++;
            foreach ($response->answers as $key => $value) {
                if (! isset($statistics[$key])) {
                    continue;
                }
                $statistics[$key]['count']++;
                if ($statistics[$key]['type'] === 'rating') {
                    $statistics[$key]['sum'] += $value;
                    $statistics[$key]['distribution'][$value]++;
                } elseif ($key === $commentKey) {
                    if ($commentCount >= (max(1, $page) - 1) * 12 && count($comments) < 12) {
                        $comments[] = ['body' => $value];
                    }
                    $commentCount++;
                }
            }
        }
        $unlocked = $count >= $definition['minimum_responses'];
        $questions = [];
        if ($unlocked) {
            foreach ($statistics as $question) {
                $visible = $question['count'] >= $definition['minimum_responses'];
                $questions[] = [...array_intersect_key($question, array_flip(['key', 'text', 'type', 'required', 'count'])), 'unlocked' => $visible,
                    ...($visible && $question['type'] === 'rating' ? ['average' => round($question['sum'] / $question['count'], 2), 'distribution' => collect($question['distribution'])->map(fn ($amount, $value) => ['value' => $value, 'count' => $amount])->values()->all()] : [])];
            }
        }
        $commentsVisible = $unlocked && $commentCount >= $definition['minimum_responses'] && $definition['mode'] === 'aggregate';

        return ['count' => $count, 'unlocked' => $unlocked, 'questions' => $questions, 'comments' => $commentsVisible ? $comments : [], 'comment_count' => $commentsVisible ? $commentCount : 0];
    }
}
